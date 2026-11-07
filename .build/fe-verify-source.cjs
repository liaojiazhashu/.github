'use strict';
// 证明生效值来自样式表而非内联：查 inline 属性为空 + 规则原文命中
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9425, width: 1440, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var sn = document.querySelector('.secnav');
      var rules = [];
      for (var s = 0; s < document.styleSheets.length; s++) {
        var rs; try { rs = document.styleSheets[s].cssRules; } catch (e) { continue; }
        for (var i = 0; i < rs.length; i++) {
          var rr = rs[i];
          if (rr.selectorText === '.secnav' || rr.selectorText === '.termline[id]') rules.push(rr.cssText.slice(0, 160));
          if (rr.cssRules) for (var k = 0; k < rr.cssRules.length; k++) {
            var r2 = rr.cssRules[k];
            if (r2.selectorText === ':root' && (r2.style.getPropertyValue('--topbar-h') || r2.style.getPropertyValue('--secnav-h')))
              rules.push(rr.conditionText + ' >> :root{--topbar-h:' + r2.style.getPropertyValue('--topbar-h') + ';--secnav-h:' + r2.style.getPropertyValue('--secnav-h') + '}');
          }
        }
      }
      return { secnavInlineAttr: sn.getAttribute('style'), secnavInlineCssText: sn.style.cssText,
               secnavComputedTop: getComputedStyle(sn).top,
               cardScrollMargin: getComputedStyle(document.querySelector('.card[id]')).scrollMarginTop,
               cssRulesFound: rules };
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
