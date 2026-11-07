'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9411, width: 1440, height: 900 });
  try {
    for (const path of ['/admin/source/activity', '/admin/source']) {
      await p.goto(path, '.card'); await ready(p); await sleep(250);
      const r = await p.evaluate(`(function () {
        var out = [];
        var all = document.querySelectorAll('[style]');
        for (var i = 0; i < Math.min(all.length, 5); i++) {
          var n = all[i], cs = getComputedStyle(n);
          out.push({ tag: n.tagName, attr: n.getAttribute('style'),
                     margin: cs.margin, width: cs.width });
        }
        return { url: location.pathname, count: all.length, samples: out };
      })()`);
      console.log(JSON.stringify(r, null, 1));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
