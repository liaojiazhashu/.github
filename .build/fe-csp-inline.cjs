'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9409, width: 1440, height: 900 });
  try {
    await p.goto('/admin/source/show?cid=1', '.table-wrap'); await ready(p); await sleep(300);
    const probe = await p.evaluate(`(function () {
      var d = document.createElement('div');
      document.body.appendChild(d);
      d.setAttribute('style', 'margin-top:50px; width:123px');
      var viaAttr = getComputedStyle(d).marginTop + ' / ' + getComputedStyle(d).width;
      d.style.marginTop = '50px';
      var viaCssom = getComputedStyle(d).marginTop;
      d.remove();
      // 真实元素：模板里 style="margin-top:16px" 的那个
      var el = Array.prototype.filter.call(document.querySelectorAll('*'), function (n) {
        return (n.getAttribute('style') || '').indexOf('margin-top:16px') >= 0;
      })[0];
      var real = el ? { tag: el.tagName, attr: el.getAttribute('style'), computed: getComputedStyle(el).marginTop } : null;
      return { setAttribute结果: viaAttr, cssom结果: viaCssom, 真实元素: real, styleSheets: document.styleSheets.length };
    })()`);
    console.log(JSON.stringify(probe, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
