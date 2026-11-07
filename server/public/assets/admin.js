/*
 * 后台唯一的脚本。
 *
 * 三件事，都必须走脚本而不能写在模板里：
 *   1. 把服务端算好的百分比填进进度条宽度。CSP 里 style-src 是 'self'，
 *      内联 style 属性会被浏览器拦掉（页面看起来没坏，但条子全是 0 宽）；
 *   2. 危险动作的二次确认。CSP 同样拦内联事件处理器，所以用 data-confirm 声明意图，
 *      由这里统一挂监听（form 与 button 两种都支持 —— 「重跑已发布」是按钮级确认）；
 *   3. 批量条的全选与实时计数。
 *
 * 第 3 件事以前不存在，因为以前的列表没有批量操作。1300 篇之后，
 * 「我到底选了多少条」必须在按下按钮之前就看得见 —— 计数实时更新，
 * 全选框要能表达「本页全选」与「部分选中」两种状态（indeterminate）。
 */
(function () {
  'use strict';

  function paint() {
    var nodes = document.querySelectorAll('[data-pct]');
    for (var i = 0; i < nodes.length; i++) {
      var raw = parseInt(nodes[i].getAttribute('data-pct'), 10);
      if (isNaN(raw)) {
        continue;
      }
      var pct = Math.max(0, Math.min(100, raw));
      nodes[i].style.width = pct + '%';
    }
  }

  /*
   * 确认文案里的两个占位符：
   *   {n}     —— 当前勾选的条数。按下按钮那一刻才知道，所以只能在这里替换；
   *   {scope} —— 「所有符合当前筛选的作品」，用于按筛选整批操作的那几个键。
   * 文案必须带数字：只问「确定吗」的确认框，人会条件反射地点掉；
   * 写清「多少条、会发生什么、能不能撤回」，才拦得住手滑。
   */
  function fillConfirm(text) {
    var n = document.querySelectorAll('input[data-check]:checked').length;

    return String(text)
      .replace(/\{n\}/g, String(n))
      .replace(/\{scope\}/g, '所有符合当前筛选的作品');
  }

  function confirms() {
    var forms = document.querySelectorAll('form[data-confirm]');
    for (var i = 0; i < forms.length; i++) {
      forms[i].addEventListener('submit', function (event) {
        if (!window.confirm(fillConfirm(this.getAttribute('data-confirm')))) {
          event.preventDefault();
        }
      });
    }

    // 按钮级确认：一个表单里挂多个动作时，只有危险的那个该被拦一下。
    // 注意这里拦的是 click —— submit 阶段拿不到「是哪个按钮触发的」，
    // 而 click 阶段可以，且 preventDefault 会连带阻止表单提交。
    var buttons = document.querySelectorAll('button[data-confirm]');
    for (var j = 0; j < buttons.length; j++) {
      buttons[j].addEventListener('click', function (event) {
        var text = this.getAttribute('data-confirm') || '这个动作会覆盖已发布的内容，确定继续？';
        if (!window.confirm(fillConfirm(text))) {
          event.preventDefault();
        }
      });
    }
  }

  function bulk() {
    var bar = document.querySelector('[data-bulkbar]');
    if (!bar) {
      return;
    }

    var boxes = Array.prototype.slice.call(document.querySelectorAll('input[data-check]'));
    var all = document.querySelector('input[data-check-all]');
    var count = bar.querySelector('[data-count]');
    // 勾选范围的动作（form="bulkIds"）：一条都没勾时按下去只会得到一句
    // 「没有符合条件的作品」，不如直接禁用 —— 让人看清「你还没选东西」。
    var idButtons = Array.prototype.slice.call(document.querySelectorAll('button[form="bulkIds"]'));

    function refresh() {
      var n = 0;
      for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].checked) {
          n++;
        }
      }
      if (count) {
        count.textContent = String(n);
      }
      bar.classList.toggle('is-empty', n === 0);

      for (var k = 0; k < idButtons.length; k++) {
        idButtons[k].disabled = n === 0;
      }

      if (all) {
        all.checked = n > 0 && n === boxes.length;
        all.indeterminate = n > 0 && n < boxes.length;
      }
    }

    if (all) {
      all.addEventListener('change', function () {
        for (var i = 0; i < boxes.length; i++) {
          boxes[i].checked = all.checked;
        }
        refresh();
      });
    }

    for (var i = 0; i < boxes.length; i++) {
      boxes[i].addEventListener('change', refresh);
    }

    // 不跨页保持勾选：翻页后是新的一批行，跨页持久会让「全选」的含义变模糊
    // （是这一页全选，还是刚才所有页全选？）。要处理整批，用「按筛选全部」。
    refresh();
  }

  /*
   * 单阶段重跑：确认文案要跟着下拉框走。
   * 写死一句「确定重跑？」没有信息量 —— 人要知道的是「重跑哪一阶段、要不要连带下游、
   * 花不花钱、我人工改过的那些还在不在」。前三个从表单里读，最后一个是服务端
   * 算好写在 data-edited 上的（快照备注前缀为「编辑/归并」的条数）。
   */
  function rerunConfirm() {
    var form = document.querySelector('form[data-rerun]');
    if (!form) {
      return;
    }

    form.addEventListener('submit', function (event) {
      var sel = form.querySelector('select[name="stage"]');
      var label = sel && sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '这一阶段';
      var cascade = form.querySelector('input[name="cascade"]');
      var text = '重跑「' + label + '」' + (cascade && cascade.checked ? '，并级联重跑下游' : '') + '？\n';
      text += '会真实调用模型、产生费用。';

      if (form.getAttribute('data-edited') === '1') {
        text += '\n这次分析里有你人工编辑过的内容，重跑会覆盖对应阶段，改动不会自动保留（只在版本快照里看得到）。';
      }

      if (!window.confirm(text)) {
        event.preventDefault();
      }
    });
  }

  /*
   * 长页段落导航：拆解结果页 8000–10000px，滚动时告诉人「现在停在第几段」。
   * 判定用「卡片顶边越过吸顶栏 + 140px 余量」，滚到底强制选末项
   * （最后一节高度不够时永远越不过判定线，不特判就会一直停在前一节）。
   */
  function secnav() {
    var nav = document.querySelector('[data-secnav]');
    if (!nav) {
      return;
    }

    var links = [];
    var secs = [];
    var anchors = nav.querySelectorAll('a[href^="#"]');
    for (var i = 0; i < anchors.length; i++) {
      var el = document.getElementById(anchors[i].getAttribute('href').slice(1));
      if (el) {
        links.push(anchors[i]);
        secs.push(el);
      }
    }
    if (secs.length === 0) {
      return;
    }

    var current = -1;
    var ticking = false;

    function mark(index) {
      if (index === current) {
        return;
      }
      if (current >= 0) {
        links[current].classList.remove('is-active');
      }
      links[index].classList.add('is-active');
      current = index;

      var active = links[index];
      if (active.offsetLeft < nav.scrollLeft || active.offsetLeft + active.offsetWidth > nav.scrollLeft + nav.clientWidth) {
        nav.scrollLeft = active.offsetLeft - 12;
      }
    }

    function update() {
      ticking = false;
      var probe = 140;
      var index = 0;
      for (var i = 0; i < secs.length; i++) {
        if (secs[i].getBoundingClientRect().top - probe <= 0) {
          index = i;
        }
      }
      if (window.innerHeight + window.pageYOffset >= document.documentElement.scrollHeight - 2) {
        index = secs.length - 1;
      }
      mark(index);
    }

    function onScroll() {
      if (ticking) {
        return;
      }
      ticking = true;
      window.requestAnimationFrame(update);
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    update();
  }

  /*
   * 保存后的提示改成右上角浮层。
   * 起因：六个保存动作现在都带锚点跳回原来那一行，页面顶部那行 flash 就落在视野外，
   * 人点了保存只看到页面跳了一下，会怀疑到底存没存。
   * 只搬 .content 下的直接子元素 —— 卡片里嵌的 .flash 是正文说明，不是提示。
   */
  function toasts() {
    var items = document.querySelectorAll('.content > .flash');
    if (items.length === 0) {
      return;
    }

    var box = document.createElement('div');
    box.className = 'toastbox';
    box.setAttribute('role', 'status');
    document.body.appendChild(box);

    function dismiss(node) {
      if (node.classList.contains('is-out')) {
        return;
      }
      node.classList.add('is-out');
      window.setTimeout(function () {
        if (node.parentNode) {
          node.parentNode.removeChild(node);
        }
      }, 300);
    }

    function schedule(node) {
      window.setTimeout(function () {
        dismiss(node);
      }, 6000);
    }

    for (var i = 0; i < items.length; i++) {
      (function (node) {
        box.appendChild(node);
        node.addEventListener('click', function () {
          dismiss(node);
        });
        // 失败了要让人看清楚，不自动消失
        if (!node.classList.contains('flash--err')) {
          schedule(node);
        }
      })(items[i]);
    }
  }

  /*
   * 字数计数。
   *
   * 80–200 是写作提示，不是校验：运营写 63 个字也能保存，只是提示会变成橙色。
   * 之所以要实时计数而不是等服务端回显，是因为这两段文字要一次写完 ——
   * 「差多少字」得在敲的时候看得见，保存后才知道就没意义了。
   * CSP 禁止内联脚本，所以计数逻辑只能放在这里，模板用 data- 声明意图。
   */
  function counters() {
    var inputs = document.querySelectorAll('[data-counter]');
    for (var i = 0; i < inputs.length; i++) {
      (function (input) {
        var name = input.getAttribute('data-counter');
        var out = document.querySelector('[data-counter-output="' + name + '"]');
        if (!out) {
          return;
        }
        var min = parseInt(input.getAttribute('data-counter-min'), 10) || 0;
        var max = parseInt(input.getAttribute('data-counter-max'), 10) || 0;
        var base = out.getAttribute('data-text') || out.textContent;

        function update() {
          // Array.from 按码点计数，与 PHP 的 mb_strlen 一致；中文一个字算一个
          var n = Array.from(input.value).length;
          out.textContent = base + '（当前 ' + n + ' 字）';
          out.classList.remove('is-short', 'is-ok', 'is-long');
          if (min > 0 && n < min) {
            out.classList.add('is-short');
          } else if (max > 0 && n > max) {
            out.classList.add('is-long');
          } else {
            out.classList.add('is-ok');
          }
        }

        out.setAttribute('data-text', base);
        input.addEventListener('input', update);
        update();
      })(inputs[i]);
    }
  }

  function boot() {
    paint();
    confirms();
    rerunConfirm();
    bulk();
    secnav();
    toasts();
    counters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
