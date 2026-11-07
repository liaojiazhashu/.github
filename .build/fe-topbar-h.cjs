'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9405, width: 1440, height: 900 });
  try {
    for (const w of [1440, 1280, 1024, 900, 768, 600, 480, 390, 360]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(200);
      const r = await p.evaluate(`(function () {
        var tb = document.querySelector('.topbar'), sn = document.querySelector('.secnav');
        var t = tb.getBoundingClientRect(), s = sn.getBoundingClientRect();
        return { topbarH: Math.round(t.height*10)/10, pad: getComputedStyle(tb).padding,
                 secnavCssTop: getComputedStyle(sn).top, secnavH: Math.round(s.height*10)/10,
                 overlapStatic: Math.round(Math.max(0, t.height - parseFloat(getComputedStyle(sn).top))*10)/10 };
      })()`);
      console.log(w + '\t' + JSON.stringify(r));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
