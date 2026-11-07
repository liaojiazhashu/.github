'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9417, width: 1440, height: 900 });
  try {
    await p.goto('/admin/source', '.card'); await ready(p); await sleep(300);
    const href = await p.evaluate(`(function () {
      var a = Array.prototype.filter.call(document.querySelectorAll('a[href]'), function (x) { return /\/admin\/source\/\d+/.test(x.getAttribute('href')); })[0];
      return a ? a.getAttribute('href') : null;
    })()`);
    if (!href) { console.log('没找到文章链接'); return; }
    await p.goto(href, '.card'); await ready(p); await sleep(400);
    const r = await p.evaluate(`(function () {
      var out = { href: location.pathname, ths: [], dropped: [] };
      var ths = document.querySelectorAll('th[style]');
      for (var i = 0; i < ths.length; i++) {
        out.ths.push({ attr: ths[i].getAttribute('style'), cssText: ths[i].style.cssText,
                       w: Math.round(ths[i].getBoundingClientRect().width*10)/10 });
      }
      var all = document.querySelectorAll('[style]');
      for (var k = 0; k < all.length; k++) {
        if (all[k].getAttribute('style') && !all[k].style.cssText) out.dropped.push(all[k].tagName + ' «' + all[k].getAttribute('style') + '»');
      }
      return out;
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
