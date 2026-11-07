'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9422, width: 1440, height: 900 });
  try {
    for (const w of [1440, 860]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/works', '.bulkbar'); await ready(p); await sleep(300);
      const r = await p.evaluate(`(function () {
        var b = document.querySelector('.bulkbar'), out = [], n = b.parentElement;
        while (n && n !== document.documentElement && out.length < 6) {
          var cs = getComputedStyle(n);
          out.push({ el: n.tagName.toLowerCase() + (n.className ? '.' + String(n.className).trim().split(/\s+/).join('.') : ''),
                     h: Math.round(n.getBoundingClientRect().height),
                     overflow: cs.overflowY, position: cs.position, display: cs.display });
          n = n.parentElement;
        }
        return { width: ${w}, bulkPosition: getComputedStyle(b).position, chain: out };
      })()`);
      console.log(JSON.stringify(r, null, 1));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
