#!/usr/bin/env node
/* 终回归：排序 lede / 词条页四分区标题与锚点落点 / 表格表头 scope=col 前后 */
'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

const WIDTHS = [
  { w: 1440, h: 900 },
  { w: 390, h: 844 }
];
const SORTS = ['newest', 'words', 'title'];
const LEDE = {
  newest: '按最新入馆排序',
  words: '按字数从多到少排序',
  title: '按标题字序排序'
};

const ready = async (p) => {
  for (let i = 0; i < 30; i++) {
    const st = await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()');
    if (st) return true;
    await sleep(200);
  }
  return false;
};

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9380, width: 1440, height: 900 });
  try {
    const fails = [];

    for (const { w, h } of WIDTHS) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 500 });

      // ---------- 1) 排序 lede ----------
      console.log('\n===== ' + w + '×' + h + ' · 排序 lede =====');
      for (const s of SORTS) {
        await p.goto('/library/works?sort=' + s, '.lede');
        await ready(p);
        const r = await p.evaluate(`(function () {
          var lede = document.querySelector('.lede');
          var sel = document.querySelector('select[name="sort"]');
          var opt = sel ? Array.prototype.filter.call(sel.options, function (o) { return o.selected; })[0] : null;
          return {
            text: lede ? lede.textContent.trim().split('。')[0] : null,
            selected: opt ? opt.value : null,
            rows: document.querySelectorAll('tbody tr').length,
            firstTitle: (document.querySelector('tbody tr td') || {}).textContent || null
          };
        })()`);
        const ok = r.text === LEDE[s] && r.selected === s;
        console.log('  sort=' + s.padEnd(7), ok ? 'OK ' : 'FAIL', '| lede="' + r.text + '" | select=' + r.selected + ' | 行数=' + r.rows);
        if (!ok) fails.push(w + ' sort=' + s + ' lede=' + r.text + ' select=' + r.selected);
      }

      // ---------- 2) 词条页：四分区标题 + #s-works 落点 ----------
      console.log('===== ' + w + '×' + h + ' · 词条页分区 =====');
      await p.goto('/library/terms/2450', '.split__main');
      await ready(p);
      const sec = await p.evaluate(`(function () {
        var heads = Array.prototype.map.call(document.querySelectorAll('.split__main .sec[id] h2'), function (h) {
          var cs = getComputedStyle(h);
          var lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.4;
          return {
            t: h.textContent.trim().slice(0, 14),
            clip: h.scrollWidth - h.clientWidth,
            lines: Math.round(h.getBoundingClientRect().height / lh),
            ov: cs.overflow,
            te: cs.textOverflow,
            ws: cs.whiteSpace
          };
        });
        var topEl = document.querySelector('.top');
        return {
          topH: Math.round(topEl.getBoundingClientRect().height * 10) / 10,
          topHVar: getComputedStyle(document.documentElement).getPropertyValue('--top-h').trim(),
          inlineTopH: document.documentElement.style.getPropertyValue('--top-h'),
          scrollPad: getComputedStyle(document.documentElement).scrollPaddingTop,
          heads: heads
        };
      })()`);
      console.log('  .top 实测=' + sec.topH + ' | --top-h 计算值=' + sec.topHVar + ' | 行内写回=' + (sec.inlineTopH || '(无)') + ' | scroll-padding=' + sec.scrollPad);
      sec.heads.forEach((x) => {
        const bad = x.clip > 1 || x.lines > 1;
        console.log('   ', bad ? 'FAIL' : 'OK  ', '"' + x.t + '" 裁切=' + x.clip + 'px 行数=' + x.lines + ' overflow=' + x.ov + ' white-space=' + x.ws);
        if (bad) fails.push(w + ' 标题被裁/换行: ' + x.t);
      });

      // 锚点落点
      for (const id of ['s-history', 's-works']) {
        await p.evaluate(`(function(){ window.scrollTo(0,0); location.hash=''; location.hash='#${id}'; })()`);
        await sleep(350);
        const a = await p.evaluate(`(function () {
          var s = document.getElementById('${id}');
          var topEl = document.querySelector('.top');
          var th = topEl.getBoundingClientRect().height;
          return { secTop: Math.round(s.getBoundingClientRect().top * 10) / 10, topH: Math.round(th * 10) / 10 };
        })()`);
        const gap = Math.round((a.secTop - a.topH) * 10) / 10;
        const ok = Math.abs(gap - 12) <= 1.5;
        console.log('   #' + id.padEnd(11), ok ? 'OK ' : 'FAIL', '落点=头下 ' + gap + 'px（头 ' + a.topH + ' / 盒顶 ' + a.secTop + '）');
        if (!ok) fails.push(w + ' #' + id + ' 落点 ' + gap + 'px');
      }

      // ---------- 3) 表格表头 ----------
      console.log('===== ' + w + '×' + h + ' · 表格表头 =====');
      await p.goto('/library/works', '.table');
      await ready(p);
      const tb = await p.evaluate(`(function () {
        var t = document.querySelector('.table');
        if (!t) return { noTable: true };
        var ths = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) {
          var cs = getComputedStyle(th);
          var lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.4;
          return {
            t: th.textContent.trim().slice(0, 10),
            scope: th.getAttribute('scope'),
            w: Math.round(th.getBoundingClientRect().width),
            h: Math.round(th.getBoundingClientRect().height * 10) / 10,
            lines: Math.round(th.getBoundingClientRect().height / lh),
            clip: th.scrollWidth - th.clientWidth,
            ta: cs.textAlign,
            pad: cs.padding
          };
        });
        return {
          wrapOv: (function () { var wr = t.closest('.table-wrap') || t.parentElement; return wr.scrollWidth - wr.clientWidth; })(),
          docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          ths: ths
        };
      })()`);
      if (tb.noTable) {
        console.log('  页面无 .table，跳过');
      } else {
        console.log('  表头 ' + tb.ths.length + ' 列 | 表格容器横向溢出=' + tb.wrapOv + ' | 页面横向溢出=' + tb.docOv);
        tb.ths.forEach((x) => {
          const bad = x.clip > 1 || x.lines > 1;
          console.log('   ', bad ? 'FAIL' : 'OK  ', '"' + x.t + '" scope=' + x.scope + ' w=' + x.w + ' 行数=' + x.lines + ' 裁切=' + x.clip + ' 对齐=' + x.ta);
          if (bad) fails.push(w + ' 表头异常: ' + x.t + ' lines=' + x.lines + ' clip=' + x.clip);
        });
      }
    }

    console.log('\n===== 失败项 =====');
    console.log(fails.length ? fails : '无');
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
