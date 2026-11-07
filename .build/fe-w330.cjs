'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9401, width: 390, height: 900 });
  try {
    for (const w of [759, 760, 761, 762, 959, 960, 961]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/library/works', '.top'); await ready(p);
      await sleep(200);
      const r = await p.evaluate(`(function () {
        var t = document.querySelector('.top'), b = t.getBoundingClientRect();
        var nav = document.querySelector('.top nav') || document.querySelector('nav');
        var find = document.querySelector('.find'), btn = document.querySelector('.themebtn');
        function Y(e){ return e ? Math.round(e.getBoundingClientRect().top) : null; }
        return { h: Math.round(b.height*10)/10, raw: b.height,
                 docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                 navY: Y(nav), findY: Y(find), btnY: Y(btn),
                 topH: document.documentElement.style.getPropertyValue('--top-h'),
                 padTop: getComputedStyle(document.documentElement).scrollPaddingTop };
      })()`);
      console.log(w + '\t' + JSON.stringify(r));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
