'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9414, width: 1440, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.beat'); await ready(p); await sleep(400);
    const r = await p.evaluate(`(function () {
      var fills = document.querySelectorAll('.beat__meter-fill');
      var track = document.querySelector('.beat__meter');
      var out = [];
      for (var i = 0; i < fills.length; i++) {
        var f = fills[i];
        out.push({ attr: f.getAttribute('style'), cssText: f.style.cssText,
                   w: Math.round(f.getBoundingClientRect().width*10)/10 });
      }
      return { trackW: track ? Math.round(track.getBoundingClientRect().width*10)/10 : null, n: fills.length, fills: out };
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
