'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9391, width: 390, height: 844 });
  try {
    await p.session.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await p.goto('/library/terms/2435', '.split__main'); await ready(p);
    await p.evaluate('(function(){window.scrollTo(0,0);return 1})()');
    await sleep(300);
    const r = await p.evaluate(`(function () {
      function R(sel) { var el = document.querySelector(sel); if (!el) return null; var b = el.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), h: Math.round(b.height) }; }
      var toc = document.querySelector('.split__aside .toc');
      var firstP = document.querySelector('.split__main p');
      return {
        top: R('.top'),
        crumb: R('.crumb, nav.crumb, .breadcrumb'),
        h1: R('.split__main h1, h1'),
        def: R('.split__main .lede, .split__main .ency, .split__main .def'),
        aside: R('.split__aside'),
        tocPanel: R('.split__aside .panel'),
        toc: toc ? { top: Math.round(toc.getBoundingClientRect().top), h: Math.round(toc.getBoundingClientRect().height), scrollW: toc.scrollWidth, clientW: toc.clientWidth } : null,
        h2First: R('.split__main h2'),
        firstP: R('.split__main p'),
        firstPText: firstP ? firstP.textContent.trim().slice(0, 30) : null,
        topHVar: document.documentElement.style.getPropertyValue('--top-h'),
        docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth
      };
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
