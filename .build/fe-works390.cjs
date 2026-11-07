'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9392, width: 390, height: 900 });
  try {
    for (const w of [390, 1440]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/library/works', '.lede'); await ready(p);
      await p.evaluate('(function(){window.scrollTo(0,0);return 1})()');
      await sleep(250);
      const r = await p.evaluate(`(function () {
        function R(el) { if (!el) return null; var b = el.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), h: Math.round(b.height) }; }
        var links = Array.prototype.filter.call(document.querySelectorAll('a[href]'), function (a) { return (a.getAttribute('href') || '').indexOf('/library/works/') === 0; });
        var first = links[0] ? links[0].closest('li, article, .card, .work, div') : null;
        var lede = document.querySelector('.lede');
        var filters = document.querySelector('.filters');
        var h1 = document.querySelector('h1');
        return {
          top: R(document.querySelector('.top')),
          h1: R(h1),
          lede: R(lede),
          filters: R(filters),
          firstWorkLink: R(links[0]),
          firstCard: R(first),
          workLinks: links.length,
          topHVar: document.documentElement.style.getPropertyValue('--top-h'),
          docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth
        };
      })()`);
      console.log('\n' + w + '×900  ' + JSON.stringify(r, null, 1));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
