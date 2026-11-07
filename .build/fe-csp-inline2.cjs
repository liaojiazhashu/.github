'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9412, width: 1440, height: 900 });
  try {
    await p.goto('/admin/source', '.card'); await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var out = [];
      var all = document.querySelectorAll('[style]');
      for (var i = 0; i < all.length; i++) {
        var n = all[i];
        out.push({ tag: n.tagName,
                   attr: n.getAttribute('style'),
                   cssText: n.style.cssText,
                   jsMarginTop: n.style.marginTop,
                   computedMargin: getComputedStyle(n).margin,
                   matched: (function () {
                     var res = [];
                     var sheets = document.styleSheets;
                     for (var s = 0; s < sheets.length; s++) {
                       var rules; try { rules = sheets[s].cssRules; } catch (e) { continue; }
                       for (var k = 0; k < rules.length; k++) {
                         var rr = rules[k];
                         if (rr.selectorText && rr.style && rr.style.margin) {
                           try { if (n.matches(rr.selectorText)) res.push(rr.selectorText + ' {' + rr.style.margin + '}'); } catch (e) {}
                         }
                       }
                     }
                     return res;
                   })() });
      }
      return out;
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
