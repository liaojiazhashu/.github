/*
 * 拆解实况的轮询脚本。
 *
 * 为什么是轮询而不是 SSE / WebSocket：
 *   站点跑在 PHP 内置服务器上，它是单进程的。一条 SSE 长连接会把唯一的处理进程占住，
 *   后台其它页面（包括 worker 状态、作品详情）就再也转不动了。5 秒一次的短请求
 *   对这点聚合查询没有压力，断线后天然自愈，也不需要处理重连状态机。
 *
 * 三条约定：
 *   1. 页面首屏已经由 Twig 渲染好，脚本只做「覆盖」，脚本挂了页面仍然可读；
 *   2. 所有会变的节点都靠 data-field 指路，取的是 JSON 里的同名字段 ——
 *      后端加字段不用改这里；
 *   3. 「变化」一律与**打开本页那一刻**比较，而不是与上一次轮询比较。
 *      5 秒的差值永远在抖，没有信息量；与基线比较才能回答「这半小时是在推进还是在停」。
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var pollMs = parseInt(script && script.getAttribute('data-poll-ms'), 10);
  if (isNaN(pollMs) || pollMs < 1000) {
    pollMs = 5000;
  }

  var ENDPOINT = '/admin/progress/data';

  var baseline = null;     // 打开页面时的快照，所有差异都相对它
  var baselineAt = 0;
  var inFlight = false;
  var timer = null;
  var failures = 0;
  var stopped = false;     // 会话失效时置真：再轮询也只会拿到登录页，没有意义

  /* ---------------- 取值与格式化 ---------------- */

  function pick(obj, path) {
    var parts = path.split('.');
    var cur = obj;
    for (var i = 0; i < parts.length; i++) {
      if (cur === null || typeof cur !== 'object' || !(parts[i] in cur)) {
        return undefined;
      }
      cur = cur[parts[i]];
    }
    return cur;
  }

  function group(n) {
    // 与后端 number_format 一致：千分位逗号
    var s = String(Math.round(Number(n) || 0));
    return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function fmt(value, kind) {
    if (value === null || value === undefined) {
      return '—';
    }

    switch (kind) {
      case 'int':
        return group(value);
      case 'pct':
        return (Number(value) || 0).toFixed(1);
      case 'yuan2':
        return (Number(value) || 0).toFixed(2);
      case 'yuan3':
        return (Number(value) || 0).toFixed(3);
      case 'rate':
        return (Number(value) || 0).toFixed(2);
      default:
        return String(value);
    }
  }

  /** 秒 → 「12 分钟」「9 小时 35 分」 */
  function duration(seconds) {
    var s = Math.abs(Math.round(seconds));
    if (s < 60) {
      return s + ' 秒';
    }
    var m = Math.round(s / 60);
    if (m < 60) {
      return m + ' 分钟';
    }
    var h = Math.floor(m / 60);
    var rest = m % 60;
    return rest > 0 ? h + ' 小时 ' + rest + ' 分' : h + ' 小时';
  }

  /** 带符号的差值文本。diff 是「当前 - 基线」 */
  function signed(diff, unit, digits) {
    var v = Number(diff) || 0;
    var abs = digits ? Math.abs(v).toFixed(digits) : String(Math.abs(Math.round(v)));
    return (v > 0 ? '+' : v < 0 ? '-' : '') + abs + unit;
  }

  /* ---------------- 渲染 ---------------- */

  var fields = null;

  function collectFields() {
    fields = [];
    var nodes = document.querySelectorAll('[data-field]');
    for (var i = 0; i < nodes.length; i++) {
      fields.push({
        el: nodes[i],
        path: nodes[i].getAttribute('data-field'),
        kind: nodes[i].getAttribute('data-format') || 'text'
      });
    }
  }

  function renderFields(snap) {
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      var value = pick(snap, f.path);
      if (value === undefined) {
        continue;
      }
      var text = fmt(value, f.kind);
      if (f.el.textContent !== text) {
        f.el.textContent = text;
      }
    }
  }

  function renderBars(snap) {
    var p = snap.progress || {};
    var pub = Number(p.published_pct) || 0;
    var ana = Number(p.analyzed_pct) || 0;
    var ing = Number(p.analyzing_pct) || 0;

    setSeg('published', 0, pub);
    setSeg('analyzed', pub, ana);
    setSeg('analyzing', pub + ana, ing);

    var budget = Math.min(100, Number((snap.spend || {}).budget_used_pct) || 0);
    setSeg('budget', 0, budget, (snap.spend || {}).over_budget ? 'is-over' : '');
  }

  function setSeg(name, left, width, extra) {
    var el = document.querySelector('[data-seg="' + name + '"]');
    if (!el) {
      return;
    }
    el.style.left = Math.max(0, Math.min(100, left)) + '%';
    el.style.width = Math.max(0, Math.min(100, width)) + '%';
    if (extra !== undefined) {
      el.classList.toggle('is-over', extra === 'is-over');
    }
  }

  function renderChart(snap) {
    var series = snap.series || [];
    if (!series.length) {
      return;
    }

    var max = 1;
    for (var i = 0; i < series.length; i++) {
      max = Math.max(max, Number(series[i].done) || 0);
    }

    for (var j = 0; j < series.length; j++) {
      var bar = document.querySelector('[data-chart-bar="' + j + '"]');
      if (bar) {
        var done = Number(series[j].done) || 0;
        bar.style.height = (done / max * 100) + '%';
        bar.setAttribute('title', series[j].label + ' · ' + done + ' 篇 · ¥' + (Number(series[j].yuan) || 0).toFixed(2));
        bar.classList.toggle('is-empty', done === 0);
      }

      var label = document.querySelector('[data-chart-label="' + j + '"]');
      if (label) {
        label.textContent = j % 3 === 0 ? series[j].label : '';
      }
    }
  }

  function renderWarnings(snap) {
    var box = document.querySelector('[data-warnings]');
    if (!box) {
      return;
    }

    var list = snap.warnings || [];
    while (box.firstChild) {
      box.removeChild(box.firstChild);
    }

    for (var i = 0; i < list.length; i++) {
      var div = document.createElement('div');
      div.className = 'notice notice--warn';
      div.textContent = list[i];
      box.appendChild(div);
    }

    box.hidden = list.length === 0;
  }

  function renderBudgetTag(snap) {
    var el = document.querySelector('[data-budget-tag]');
    if (!el) {
      return;
    }
    var over = !!(snap.spend || {}).over_budget;
    el.textContent = over ? '将超预算' : '预算内';
    el.classList.toggle('badge--danger', over);
    el.classList.toggle('badge--published', !over);
  }

  function renderRecent(snap) {
    var body = document.querySelector('[data-recent]');
    if (!body) {
      return;
    }

    var rows = snap.recent || [];
    var html = '';

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      // 全部走 textContent 等价的拼接：标题来自数据库，不能直接当 HTML 插进去
      html += '<tr>'
        + '<td><a href="/admin/works/' + esc(r.code) + '">' + esc(r.title) + '</a></td>'
        + '<td class="num mono">¥' + Number(r.yuan).toFixed(3) + '</td>'
        + '<td class="num mono">' + Number(r.secs) + 's</td>'
        + '<td>' + (r.visibility === 'hidden'
          ? '<span class="badge">仅后台</span>'
          : '<span class="badge badge--published">已公开</span>') + '</td>'
        + '<td class="dim mono">' + esc(String(r.finished_at).slice(11, 19)) + '</td>'
        + '</tr>';
    }

    body.innerHTML = html;
  }

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * 三个「与打开页面时相比」的差值。
   *
   * 差值为 0 时一律显示「—」而不是「0 篇」—— 刚打开页面的头几秒所有差值都是 0，
   * 一屏的 0 会让人以为这些数字坏了。等真的动了再显示。
   *
   * 速率与预计完成这两张卡的小字各有「本来的上下文」（多久跑了几篇 / 几点完），
   * 所以差值只往后追加，不覆盖原文 —— 否则每刷新一次就把证据擦掉一次。
   */
  function renderDeltas(snap) {
    if (!baseline) {
      return;
    }

    var curRemain = Number(pick(snap, 'progress.remaining')) || 0;
    var baseRemain = Number(pick(baseline, 'progress.remaining')) || 0;
    var remainDiff = curRemain - baseRemain;

    setDelta('remaining',
      remainDiff === 0 ? '与打开时持平' : '较打开 ' + signed(remainDiff, ' 篇'),
      remainDiff < 0 ? 'is-good' : remainDiff > 0 ? 'is-bad' : '');

    var curRate = Number(pick(snap, 'pace.rate_per_minute')) || 0;
    var baseRate = Number(pick(baseline, 'pace.rate_per_minute')) || 0;
    var rateDiff = curRate - baseRate;
    var measured = Number(pick(snap, 'pace.measured')) || 0;
    var windowMin = Number(pick(snap, 'pace.window')) || 0;

    setDelta('rate',
      '近 ' + windowMin + ' 分钟完成 ' + measured + ' 篇'
        + (rateDiff === 0 ? '' : ' · 较打开 ' + signed(rateDiff, ' 篇/分', 2)),
      rateDiff > 0 ? 'is-good' : rateDiff < 0 ? 'is-bad' : '');

    var curEta = pick(snap, 'eta.seconds');
    var baseEta = pick(baseline, 'eta.seconds');
    var finishAt = pick(snap, 'eta.finish_at');
    var finishDay = pick(snap, 'eta.finish_day');

    if (curEta === null || curEta === undefined) {
      setDelta('eta', '当前无法估算', 'is-bad');
    } else {
      var head = finishAt ? finishDay + ' ' + finishAt : '已完成';

      if (baseEta !== null && baseEta !== undefined) {
        var d = Number(curEta) - Number(baseEta);
        // 估算时间变短是好事：剩下的事更快做完
        var tail = d === 0 ? '' : ' · ' + (d < 0 ? '提前 ' : '推后 ') + duration(d);
        setDelta('eta', head + tail, d < 0 ? 'is-good' : d > 0 ? 'is-bad' : '');
      } else {
        setDelta('eta', head, '');
      }
    }

    var curProj = Number(pick(snap, 'spend.projected_yuan')) || 0;
    var baseProj = Number(pick(baseline, 'spend.projected_yuan')) || 0;
    var projDiff = curProj - baseProj;

    setDelta('projected',
      projDiff === 0 ? '与打开时持平' : '较打开 ' + (projDiff > 0 ? '+' : '-') + '¥' + Math.abs(projDiff).toFixed(2),
      projDiff < 0 ? 'is-good' : projDiff > 0 ? 'is-bad' : '');
  }

  function setDelta(name, text, tone) {
    var el = document.querySelector('[data-delta="' + name + '"]');
    if (!el) {
      return;
    }
    el.textContent = text;
    el.classList.toggle('is-good', tone === 'is-good');
    el.classList.toggle('is-bad', tone === 'is-bad');
  }

  function renderFooter(snap) {
    if (!baseline) {
      return;
    }

    var elapsed = Math.max(0, Math.round((Date.now() - baselineAt) / 1000));
    var doneDiff = (Number(pick(snap, 'progress.done')) || 0) - (Number(pick(baseline, 'progress.done')) || 0);
    var spendDiff = (Number(pick(snap, 'spend.today_yuan')) || 0) - (Number(pick(baseline, 'spend.today_yuan')) || 0);

    setBaseline('elapsed', duration(elapsed));
    setBaseline('done', group(Math.max(0, doneDiff)));
    setBaseline('spend', '¥' + Math.max(0, spendDiff).toFixed(2));
  }

  function setBaseline(name, text) {
    var el = document.querySelector('[data-baseline="' + name + '"]');
    if (el) {
      el.textContent = text;
    }
  }

  function renderLive(snap) {
    var clock = document.querySelector('[data-live-clock]');
    if (clock) {
      clock.textContent = snap.at_hms || '';
    }
  }

  function setState(text, stale) {
    var state = document.querySelector('[data-live-state]');
    var dot = document.querySelector('[data-live-dot]');
    if (state) {
      state.textContent = text;
    }
    if (dot) {
      dot.classList.toggle('is-stale', !!stale);
    }
  }

  function render(snap) {
    if (!baseline) {
      baseline = snap;
      baselineAt = Date.now();
    }

    renderLive(snap);
    renderFields(snap);
    renderBars(snap);
    renderChart(snap);
    renderWarnings(snap);
    renderBudgetTag(snap);
    renderRecent(snap);
    renderDeltas(snap);
    renderFooter(snap);
  }

  /* ---------------- 轮询 ---------------- */

  function tick() {
    if (inFlight || stopped) {
      return;
    }

    inFlight = true;

    fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        // 会话过期时后端会把请求 303 到登录页，跟着跳过去拿到的是一整页 HTML。
        // 这种情况不能当成「网络抖动」重试 —— 重试一百次也拿不到数据。
        var type = res.headers.get('content-type') || '';
        if (res.redirected || type.indexOf('application/json') === -1) {
          var err = new Error('not json');
          err.auth = true;
          throw err;
        }
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.json();
      })
      .then(function (snap) {
        failures = 0;
        setState('实时', false);
        render(snap);
      })
      .catch(function (err) {
        if (err && err.auth) {
          stopped = true;
          setState('会话已失效，请刷新页面', true);
          return;
        }
        failures++;
        setState('连接中断，重试中', true);
      })
      .then(function () {
        inFlight = false;
        if (!stopped) {
          schedule();
        }
      });
  }

  function schedule() {
    if (timer) {
      window.clearTimeout(timer);
    }
    // 连续失败时退避，但最多 30 秒一次，保证恢复后能较快回到画面
    var delay = failures > 0 ? Math.min(30000, pollMs * Math.pow(2, failures - 1)) : pollMs;
    timer = window.setTimeout(tick, delay);
  }

  document.addEventListener('visibilitychange', function () {
    // 后台标签页不轮询：省得开着十个标签页一起捶数据库
    if (document.hidden) {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
      return;
    }
    tick();
  });

  collectFields();
  tick();
})();
