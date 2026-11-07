/* 银河图书馆 · 公开前台交互
   只有三件事：切昼夜、筛选器改动即提交、检索框回填。
   全部走 addEventListener，没有任何内联 onclick —— CSP 是 script-src 'self'。 */
(function () {
  'use strict';

  // ---- 昼夜切换 ----
  // 图标语义与官网一致：显示「要切到的模式」。aria-label 与 aria-pressed 同步更新。
  var toggle = document.querySelector('[data-theme-toggle]');
  if (toggle) {
    var sync = function () {
      var day = document.documentElement.getAttribute('data-theme') === 'day';
      toggle.setAttribute('aria-pressed', day ? 'true' : 'false');
      toggle.setAttribute('aria-label', day ? '切换到夜间模式' : '切换到白天模式');
      toggle.setAttribute('title', day ? '切换到夜间模式' : '切换到白天模式');
    };

    toggle.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'day' ? 'night' : 'day';
      document.documentElement.setAttribute('data-theme', next);
      try {
        localStorage.setItem('hq-theme', next);
      } catch (e) {
        /* 存不上就只在本次生效，不影响阅读 */
      }
      sync();
    });

    sync();
  }

  // ---- 筛选器改动即提交（少一次点「筛选」） ----
  var form = document.querySelector('form.filters');
  if (form) {
    form.querySelectorAll('select').forEach(function (select) {
      select.addEventListener('change', function () {
        form.submit();
      });
    });
  }

  // ---- 吸顶头的真实高度写回 --top-h ----
  // 为什么不用断点算：CSS 里那三档（73 / 113 / 163）是实测取整的近似值，而头部的
  // 换行点是内容决定的 —— 421–560 之间头部已经是三行，但 560 那档的 113 会少算 50px；
  // 反过来说，若给 420 单独加一条断点，改一句文案就可能在 430 上失效。两种失效的代价
  // 也不对等：多留空隙只是难看，少留就是标题被吸顶头盖住、点了像没反应。
  // 高度本来就能在这个位置量到，量一次比猜三次稳。
  // 成本：--top-h 只被 scroll-padding-top 在「点击锚点那一刻」消费，不参与首帧绘制，
  // 因此这个赋值来得早或晚都不产生 CLS，也不用抢在 paint 之前。
  var topbar = document.querySelector('.top');
  if (topbar) {
    var applyTopH = function () {
      var h = topbar.getBoundingClientRect().height;
      // 头部被 display:none 或尚未排版时高度为 0，别把 0 写进去（锚点会全部贴在顶上）
      if (h > 0) {
        document.documentElement.style.setProperty('--top-h', h + 'px');
      }
    };

    if (window.ResizeObserver) {
      new ResizeObserver(applyTopH).observe(topbar);
    } else {
      window.addEventListener('resize', applyTopH);
    }
    applyTopH();
  }

  // ---- 检索框回填当前查询词 ----
  var input = document.querySelector('.find__in');
  if (input && !input.value) {
    var q = new URLSearchParams(window.location.search).get('q');
    if (q) {
      input.value = q;
    }
  }
})();
