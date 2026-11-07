'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9413, width: 1440, height: 900 });
  try {
    await p.goto('/admin/source', '.card'); await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var a = document.createElement('div'); document.body.appendChild(a);
      a.setAttribute('style', 'margin-top:50px');
      var res = { setAttr: { cssText: a.style.cssText, marginTop: getComputedStyle(a).marginTop } };
      var b = document.createElement('div'); document.body.appendChild(b);
      b.style.marginTop = '30px';
      res.cssom = { cssText: b.style.cssText, marginTop: getComputedStyle(b).marginTop };
      var c = document.createElement('div'); document.body.appendChild(c);
      c.setAttribute('style', 'margin-top:70px'); c.style.marginTop = '80px';
      res.mixed = { cssText: c.style.cssText, marginTop: getComputedStyle(c).marginTop };
      a.remove(); b.remove(); c.remove();
      var tpl = document.querySelector('[style]');
      res.template = { attr: tpl.getAttribute('style'), cssText: tpl.style.cssText, margin: getComputedStyle(tpl).margin };
      return res;
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
