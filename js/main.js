/* ==========================================================================
   荒启科幻 · 站点通用交互
   --------------------------------------------------------------------------
   零依赖原生 ES6。所有模块在元素缺失时自动跳过，因此同一份脚本可被所有页面共用。
   包含：导航状态 / 下拉 / 移动端抽屉 / 滚动入场 / 数字滚动 / 进度条 /
        折叠面板 / 标签页 / 回到顶部 / 阅读进度 / 卡片光斑 / 页脚年份
   ========================================================================== */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------------------- 工具 */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function rafThrottle(fn) {
    var ticking = false;
    return function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        ticking = false;
        fn();
      });
    };
  }

  /* -------------------------------------------------- 顶部导航滚动状态 */
  function initHeader() {
    var header = $('.site-header');
    if (!header) return;

    var update = rafThrottle(function () {
      if (window.pageYOffset > 80) header.classList.add('is-stuck');
      else header.classList.remove('is-stuck');
    });

    update();
    window.addEventListener('scroll', update, { passive: true });
  }

  /* ------------------------------------------------------------ 阅读进度 */
  function initProgress() {
    var bar = $('.scroll-progress');
    if (!bar) return;

    var update = rafThrottle(function () {
      var doc = document.documentElement;
      var max = doc.scrollHeight - window.innerHeight;
      var p = max > 0 ? (window.pageYOffset / max) * 100 : 0;
      bar.style.width = Math.min(100, Math.max(0, p)) + '%';
    });

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
  }

  /* -------------------------------------------------------- 下拉导航菜单 */
  function initDropdown() {
    var items = $$('.nav__item.has-drop');
    if (!items.length) return;

    items.forEach(function (item) {
      /* 展开按钮是父级链接右侧的那个小箭头，不是链接本身。
         父级链接必须保持「可跳转」，因此不能再对它 preventDefault ——
         之前点击父级只会展开菜单，用户没有任何入口能到板块介绍段落。 */
      var btn = $('.nav__caret-btn', item);
      var drop = $('.nav__drop', item);
      if (!btn || !drop) return;

      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-haspopup', 'true');

      function open() {
        closeAll();
        item.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
      }

      function close() {
        item.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
      }

      function closeAll() {
        items.forEach(function (o) {
          if (o !== item) {
            o.classList.remove('is-open');
            var b = $('.nav__caret-btn', o);
            if (b) b.setAttribute('aria-expanded', 'false');
          }
        });
      }

      /* 桌面端不再随鼠标悬停展开。
         父级文字链接本身就是「业务板块 → 板块介绍」的跳转入口，鼠标路过时
         弹出一层覆盖三个板块名称与简介的面板，会在用户想点链接时先制造一次
         误读与遮挡。子菜单改为完全由右侧箭头按钮主动触发（点击 / 键盘），
         触屏与桌面行为一致。 */

      /* 键盘与触屏：点击切换 */
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (item.classList.contains('is-open')) close(); else open();
      });

      item.addEventListener('focusout', function (e) {
        if (!item.contains(e.relatedTarget)) close();
      });

      item.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); btn.focus(); }
      });
    });

    document.addEventListener('click', function (e) {
      if (!e.target.closest || !e.target.closest('.nav__item.has-drop')) {
        items.forEach(function (o) {
          o.classList.remove('is-open');
          var b = $('.nav__caret-btn', o);
          if (b) b.setAttribute('aria-expanded', 'false');
        });
      }
    });
  }

  /* -------------------------------------------------------- 移动端抽屉 */
  function initDrawer() {
    var toggle = $('.nav-toggle');
    var drawer = $('.drawer');
    if (!toggle || !drawer) return;

    var closeBtn = $('.drawer__close', drawer);
    var lastFocus = null;

    function lockScroll(lock) {
      document.body.style.overflow = lock ? 'hidden' : '';
    }

    function open() {
      lastFocus = document.activeElement;
      drawer.classList.add('is-open');
      drawer.removeAttribute('aria-hidden');
      toggle.setAttribute('aria-expanded', 'true');
      lockScroll(true);
      var first = $('.drawer__link, .drawer__close', drawer);
      if (first) first.focus();
    }

    function close() {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      toggle.setAttribute('aria-expanded', 'false');
      lockScroll(false);
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', drawer.id || 'mobile-drawer');
    drawer.setAttribute('aria-hidden', 'true');

    toggle.addEventListener('click', function () {
      if (drawer.classList.contains('is-open')) close(); else open();
    });

    if (closeBtn) closeBtn.addEventListener('click', close);

    drawer.addEventListener('click', function (e) {
      if (e.target === drawer) close();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('is-open')) close();
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth >= 1024 && drawer.classList.contains('is-open')) close();
    });
  }

  /* ------------------------------------------------------ 当前页高亮
     规则（v1.6 修正）：全站任何时刻只允许一个导航项处于 is-current。
     ── 「业务板块」父链接的 href 是 index.html#business：只有在当前
        location.hash 恰好是 #business 时它才能成为当前项 —— 否则首页上
        「首页」与「业务板块」两条底部亮条会同时常亮（旧版 bug：只要
        文件名匹配就加 is-current，锚点链接被当成整页链接）。
     ── 带锚点跳转不触发页面重载，因此还要监听 hashchange 重算一次。
     ── community / tools / analysis 三个板块页在导航表里没有直接链接，
        由页面模板硬编码 is-current，JS 在「找不到匹配」时保持现状不动。 */
  function initCurrentNav() {
    var path = window.location.pathname.split('/').pop() || 'index.html';
    var hash = window.location.hash || '';
    /* 板块子页（community / tools / analysis）命中的是下拉里的 .drop-item，
       它没有 .nav__link 类 —— 不纳入这里，子页上顶栏「业务板块」就点不亮。 */
    var links = $$('.nav__link[href], .drop-item[href], .drawer__link[href]');

    var hashMatch = null;
    var plainMatch = null;
    links.forEach(function (a) {
      var raw = a.getAttribute('href') || '';
      var file = raw.split('#')[0].split('/').pop();
      if (file !== path) return;
      var h = raw.indexOf('#') !== -1 ? raw.slice(raw.indexOf('#')) : '';
      if (h && hash && h === hash) hashMatch = a;
      else if (!h && !plainMatch) plainMatch = a;
    });

    var best = hashMatch || plainMatch;
    if (!best) return;                    // 没有可匹配的导航项（板块子页），保持模板现状

    links.forEach(function (a) {
      a.classList.remove('is-current');
      var item = a.closest('.nav__item');
      if (item) item.classList.remove('is-current');
      var dl = a.closest('.drawer__link');
      if (dl) dl.classList.remove('is-current');
    });

    best.classList.add('is-current');
    /* .drop-item 在 .nav__drop 里，往上取 .nav__item 会自然落到「业务板块」那一项 */
    var item = best.closest('.nav__item');
    if (item) item.classList.add('is-current');
    var dl = best.closest('.drawer__link');
    if (dl) dl.classList.add('is-current');
  }

  /* ---------------------------------------------------- 滚动入场动画 */
  function initReveal() {
    var els = $$('[data-reveal], [data-count], .bar-fill, .score-line__fill, .title-underline');
    if (!els.length) return;

    if (reduceMotion || !('IntersectionObserver' in window)) {
      els.forEach(function (el) {
        el.classList.add('is-in');
        if (el.hasAttribute('data-count')) runCount(el, true);
        if (el.classList.contains('bar-fill') || el.classList.contains('score-line__fill')) {
          el.style.width = (el.getAttribute('data-value') || '0') + '%';
        }
      });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        el.classList.add('is-in');
        if (el.hasAttribute('data-count')) runCount(el, false);
        if (el.classList.contains('bar-fill') || el.classList.contains('score-line__fill')) {
          el.style.width = (el.getAttribute('data-value') || '0') + '%';
        }
        io.unobserve(el);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

    els.forEach(function (el) { io.observe(el); });
  }

  /* ------------------------------------------------------ 数字滚动动画 */
  function runCount(el, instant) {
    var target = parseFloat(el.getAttribute('data-count'));
    if (isNaN(target)) return;
    var decimals = (el.getAttribute('data-decimals') | 0) || 0;
    var prefix = el.getAttribute('data-prefix') || '';
    var suffix = el.getAttribute('data-suffix') || '';

    function format(v) {
      var txt = decimals > 0 ? v.toFixed(decimals) : String(Math.round(v));
      /* 千分位；年份、期次这类「编号」用 data-count-plain 关掉（2024 不该写成 2,024） */
      if (!el.hasAttribute('data-count-plain')) {
        txt = txt.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      }
      return prefix + txt + suffix;
    }

    if (instant) { el.textContent = format(target); return; }

    var duration = 1400;
    var start = null;

    function tick(ts) {
      if (start === null) start = ts;
      var p = Math.min(1, (ts - start) / duration);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = format(target * eased);
      if (p < 1) window.requestAnimationFrame(tick);
      else el.textContent = format(target);
    }

    window.requestAnimationFrame(tick);
  }

  /* --------------------------------------------------------- 折叠面板 */
  function initAccordion() {
    var groups = $$('[data-accordion]');
    if (!groups.length) return;

    groups.forEach(function (root) {
      var items = $$('.acc-item', root);

      items.forEach(function (item) {
        var btn = $('.acc-btn', item);
        var panel = $('.acc-panel', item);
        if (!btn || !panel) return;

        var expanded = item.classList.contains('is-open');
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        panel.setAttribute('aria-hidden', expanded ? 'false' : 'true');
        if (expanded) panel.style.maxHeight = panel.scrollHeight + 'px';

        btn.addEventListener('click', function () {
          var isOpen = item.classList.contains('is-open');
          var single = root.getAttribute('data-accordion') === 'single';

          if (single && !isOpen) {
            items.forEach(function (other) {
              if (other === item) return;
              other.classList.remove('is-open');
              var ob = $('.acc-btn', other), op = $('.acc-panel', other);
              if (ob) ob.setAttribute('aria-expanded', 'false');
              if (op) { op.style.maxHeight = '0px'; op.setAttribute('aria-hidden', 'true'); }
            });
          }

          if (isOpen) {
            item.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            panel.setAttribute('aria-hidden', 'true');
            panel.style.maxHeight = '0px';
          } else {
            item.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            panel.setAttribute('aria-hidden', 'false');
            panel.style.maxHeight = panel.scrollHeight + 'px';
          }
        });
      });
    });

    /* 视口变化时重算已展开面板高度 */
    window.addEventListener('resize', rafThrottle(function () {
      $$('.acc-item.is-open .acc-panel').forEach(function (p) {
        p.style.maxHeight = p.scrollHeight + 'px';
      });
    }));
  }

  /* ----------------------------------------------------------- 标签页 */
  function initTabs() {
    var roots = $$('[data-tabs]');
    if (!roots.length) return;

    roots.forEach(function (root) {
      var btns = $$('.tab-btn', root);
      var panels = $$('.tab-panel', root);

      function select(idx) {
        btns.forEach(function (b, i) {
          var on = i === idx;
          b.setAttribute('aria-selected', on ? 'true' : 'false');
          b.setAttribute('tabindex', on ? '0' : '-1');
        });
        panels.forEach(function (p, i) {
          if (i === idx) p.removeAttribute('hidden');
          else p.setAttribute('hidden', '');
        });
      }

      btns.forEach(function (btn, i) {
        btn.setAttribute('role', 'tab');
        btn.addEventListener('click', function () { select(i); });
        btn.addEventListener('keydown', function (e) {
          var next = null;
          if (e.key === 'ArrowRight') next = (i + 1) % btns.length;
          if (e.key === 'ArrowLeft') next = (i - 1 + btns.length) % btns.length;
          if (e.key === 'Home') next = 0;
          if (e.key === 'End') next = btns.length - 1;
          if (next !== null) {
            e.preventDefault();
            select(next);
            btns[next].focus();
          }
        });
      });

      var initial = 0;
      btns.forEach(function (b, i) { if (b.getAttribute('aria-selected') === 'true') initial = i; });
      select(initial);
    });
  }

  /* -------------------------------------------------------- 回到顶部 */
  function initToTop() {
    var btn = $('.to-top');
    if (!btn) return;

    var update = rafThrottle(function () {
      if (window.pageYOffset > 600) btn.classList.add('is-shown');
      else btn.classList.remove('is-shown');
    });

    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });

    update();
    window.addEventListener('scroll', update, { passive: true });
  }

  /* ------------------------------------------------------ 卡片光斑跟随 */
  function initSpotlight() {
    if (reduceMotion || window.innerWidth < 1024) return;
    var cards = $$('.spotlight');
    if (!cards.length) return;

    /* mousemove 每秒可触发上百次，直接 setProperty 会反复触发样式重算；
       坐标先记在闭包里，写入合并到一帧一次（v2.3.19）。 */
    cards.forEach(function (card) {
      var mx = 0;
      var my = 0;
      var apply = rafThrottle(function () {
        card.style.setProperty('--sx', mx + '%');
        card.style.setProperty('--sy', my + '%');
      });
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        mx = (e.clientX - r.left) / r.width * 100;
        my = (e.clientY - r.top) / r.height * 100;
        apply();
      });
    });
  }

  /* ------------------------------------------------------ 页脚年份 */
  function initYear() {
    $$('[data-year]').forEach(function (el) {
      el.textContent = String(new Date().getFullYear());
    });
  }

  /* ------------------------------------------------------ 昼夜主题（v2.2）
     夜间 = 星图（默认），白天 = 太阳 / 云 / 月。
     首帧由 <head> 内联脚本应用（避免闪色），这里只负责切换与持久化。 */
  function initTheme() {
    var root = document.documentElement;
    var btn = $('[data-theme-toggle]');
    if (!btn) return;

    var apply = function (t) {
      root.setAttribute('data-theme', t);
      var isDay = t === 'day';
      btn.setAttribute('aria-pressed', isDay ? 'true' : 'false');
      btn.setAttribute('aria-label', isDay ? '切换到夜间模式' : '切换到白天模式');
      var meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', isDay ? '#C9DDEC' : '#0B0E14');
    };

    btn.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'day' ? 'night' : 'day';
      try { localStorage.setItem('hq-theme', next); } catch (e) { /* 隐私模式忽略 */ }
      apply(next);
    });

    apply(root.getAttribute('data-theme') === 'day' ? 'day' : 'night');
  }

  /* ---------------------------------------------------------- 复制到剪贴板
     navigator.clipboard 只在安全上下文（https / localhost）存在，用 http
     或 file:// 打开时它是 undefined —— 所以 execCommand 兜底不是兼容性
     洁癖，是主路径之一。textarea 必须留在文档流里且能聚焦，否则 iOS 上
     select() 拿不到选区；1px + opacity:0 避免页面滚动跳动。 */
  function copyText(text, cb) {
    function viaExecCommand() {
      var ta = document.createElement('textarea');
      var ok = false;
      /* showModal() 之后，top layer 之外的整页都是 inert：临时 textarea 如果挂在
         body 上，focus() 抓不到焦点、选区落在 inert 子树里，execCommand('copy')
         仍然返回 true 但一个字都写不进去。有头浏览器实测过：系统剪贴板读到的是
         原值（哨兵），而按钮已经显示「已复制」。所以优先挂进当前打开的 dialog。 */
      var host = document.querySelector('dialog[open]') || document.body;
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '0';
      ta.style.left = '0';
      ta.style.width = '1px';
      ta.style.height = '1px';
      ta.style.padding = '0';
      ta.style.border = 'none';
      ta.style.opacity = '0';
      host.appendChild(ta);
      try {
        ta.focus();
        ta.select();
        ta.setSelectionRange(0, text.length);
        ok = document.execCommand('copy');
      } catch (err) {
        ok = false;
      }
      host.removeChild(ta);
      cb(ok);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { cb(true); }, viaExecCommand);
    } else {
      viaExecCommand();
    }
  }

  /* 复制按钮（v2.9.0）：群号 9 位数字，手打容易错。
     事件委托挂在 document 上，弹窗是静态 DOM 也不影响 —— 以后别的页面
     加 data-copy 不用改这里。原始文案存进 data-label，避免连续点击时
     把「已复制」当成原文案存下来。 */
  function initCopyButtons() {
    document.addEventListener('click', function (e) {
      var el = e.target;
      var btn = el && el.closest ? el.closest('[data-copy]') : null;
      if (!btn) return;
      var text = btn.getAttribute('data-copy');
      if (!text) return;
      e.preventDefault();

      if (!btn.getAttribute('data-label')) {
        btn.setAttribute('data-label', btn.textContent);
        btn.setAttribute('data-aria', btn.getAttribute('aria-label') || '');
      }
      var label = btn.getAttribute('data-label');
      var ariaLabel = btn.getAttribute('data-aria');

      /* 文案变了但 aria-label 不变的话，读屏软件读到的永远是「复制群号」，
         状态改变不会被读到。两个一起改。 */
      copyText(text, function (ok) {
        btn.textContent = ok ? '已复制' : '复制失败，请手动选择';
        btn.setAttribute('aria-label', ok ? '已复制，群号 ' + text
          : '复制失败，群号 ' + text + '，请手动选择');
        if (ok) btn.classList.add('is-done');
        if (btn._copyTimer) window.clearTimeout(btn._copyTimer);
        btn._copyTimer = window.setTimeout(function () {
          btn.textContent = label;
          if (ariaLabel) btn.setAttribute('aria-label', ariaLabel);
          else btn.removeAttribute('aria-label');
          btn.classList.remove('is-done');
          btn._copyTimer = null;
        }, ok ? 2000 : 3000);
      });
    });
  }

  /* -------------------------------------------- 邀请码提示弹窗（v2.3.10）
     顶栏「下载客户端 / 在线阅读」点击后先弹提示（加群拿邀请码），
     弹窗内保留「直接下载」「进入在线阅读」—— 入口本身不设硬门槛。
     原生 <dialog>：Esc 关闭为浏览器默认行为；点击背板也可关闭。 */
  function initInviteModal() {
    var dlg = document.getElementById('invite-modal');
    if (!dlg || typeof dlg.showModal !== 'function') return;

    $$('[data-invite-open]').forEach(function (t) {
      t.addEventListener('click', function (e) {
        e.preventDefault();
        if (typeof dlg.showModal === 'function') dlg.showModal();
        else dlg.setAttribute('open', '');
      });
    });

    $$('[data-invite-close]', dlg).forEach(function (b) {
      b.addEventListener('click', function () { dlg.close(); });
    });

    /* 点击背板（dialog 元素本身而非卡片）关闭 */
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg) dlg.close();
    });

    /* 点击弹窗内的动作链接后自动关闭，避免跳转返回时弹窗仍开着 */
    $$('a', dlg).forEach(function (a) {
      a.addEventListener('click', function () { dlg.close(); });
    });
  }

  /* ------------------------------------------------------------ 启动 */
  function boot() {
    initHeader();
    initProgress();
    initDropdown();
    initDrawer();
    initCurrentNav();
    /* 锚点跳转不重载页面，「业务板块 → 首页#business」这类跳转后需要重算当前项 */
    window.addEventListener('hashchange', initCurrentNav);
    initReveal();
    initAccordion();
    initTabs();
    initToTop();
    initSpotlight();
    initTheme();
    initYear();
    initCopyButtons();
    initInviteModal();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  /* 供页面级脚本复用 */
  window.HQ = {
    $: $,
    $$: $$,
    rafThrottle: rafThrottle,
    reduceMotion: reduceMotion
  };

  /* ------------------------------------------------------------ 本地预览
     官网里「进入银河图书馆」写的是同域路径 /library —— 生产由 Nginx 交给 PHP，
     官网与馆同一个域名。但本机预览时官网是 file:// 或 8080 起的静态服务，
     而图书馆跑在 127.0.0.1:8090，直接点会 404。
     所以只在「本机」把 /library 补上 8090 的源；生产域名下这段不会命中。
     只改 href，不动 target / rel —— 新窗口与 noopener 是模板里写死的。 */
  (function localLibraryBase() {
    var host = location.hostname;
    var local = location.protocol === 'file:' || host === 'localhost' || host === '127.0.0.1';
    if (!local) return;
    var origin = 'http://127.0.0.1:8090';
    Array.prototype.forEach.call(document.querySelectorAll('a[href^="/library"]'), function (a) {
      a.setAttribute('href', origin + a.getAttribute('href'));
    });
  })();
})();
