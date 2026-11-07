'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9416, width: 1440, height: 900 });
  try {
    await p.goto('/admin/source', '.card'); await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var form = document.querySelector('form[style]');
      var card = form ? form.closest('.card') : null;
      var out = { form: null, card: null };
      if (form) {
        var f = form.getBoundingClientRect(), c = card.getBoundingClientRect();
        out.form = { attr: form.getAttribute('style'), cssText: form.style.cssText,
                     marginLeft: getComputedStyle(form).marginLeft,
                     left: Math.round(f.left), right: Math.round(f.right) };
        out.card = { left: Math.round(c.left), right: Math.round(c.right) };
        out.gapToRight = Math.round(c.right - f.right);
      }
      // 逐个统计被丢弃的内联属性及其"本应生效"的声明
      var all = document.querySelectorAll('[style]'), dropped = [];
      for (var i = 0; i < all.length; i++) {
        if (all[i].getAttribute('style') && !all[i].style.cssText) dropped.push({ tag: all[i].tagName, attr: all[i].getAttribute('style') });
      }
      out.dropped = dropped;
      return out;
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
