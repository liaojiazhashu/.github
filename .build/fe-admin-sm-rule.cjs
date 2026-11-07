'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9403, width: 1440, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav');
    await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var hits = [], el = document.getElementById('terms');
      function walk(rules, href) {
        for (var i = 0; i < rules.length; i++) {
          var r = rules[i];
          if (r.cssRules && r.cssRules.length) { walk(r.cssRules, href); continue; }
          if (r.style && r.style.cssText && r.style.cssText.indexOf('scroll-margin') >= 0) {
            hits.push({ sel: r.selectorText, css: r.style.cssText, href: href });
          }
        }
      }
      for (var s = 0; s < document.styleSheets.length; s++) {
        try { walk(document.styleSheets[s].cssRules, document.styleSheets[s].href || 'inline'); } catch (e) {}
      }
      return { tag: el.tagName, cls: el.className, sm: getComputedStyle(el).scrollMarginTop, hits: hits };
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
