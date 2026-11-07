'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9427, width: 1440, height: 900 });
  try {
    for (const w of [1440, 860, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/works', '.bulkbar'); await ready(p); await sleep(250);
      const r = await p.evaluate(`(function () {
        var b=document.querySelector('.bulkbar');
        return { w: ${w}, position: getComputedStyle(b).position, top: getComputedStyle(b).top,
                 zIndex: getComputedStyle(b).zIndex, inert: getComputedStyle(b).position === 'static' };
      })()`);
      console.log(JSON.stringify(r));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
