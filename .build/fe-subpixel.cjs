'use strict';
// 1) 元素尺寸是否整数  2) 亚像素是否来自滚动位置  3) static 元素的默认 top 是否 auto
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9428, width: 860, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var tb=document.querySelector('.topbar'), sn=document.querySelector('.secnav');
      var t=tb.getBoundingClientRect(), s=sn.getBoundingClientRect();
      var out = { topbar: { h: t.height, top: t.top, bottom: t.bottom },
                  secnav: { h: s.height, top: s.top, bottom: s.bottom },
                  scrollY: window.scrollY };
      // 滚动后的落点：连测三次，看小数是否跟着滚动位置变
      var arr = [];
      [1000, 1001, 1234].forEach(function (y) {
        window.scrollTo(0, y);
        var el = document.getElementById('terms');
        arr.push({ want: y, got: window.scrollY, targetTop: Math.round(el.getBoundingClientRect().top*100)/100 });
      });
      out.landings = arr;
      // static 元素的 top 默认值
      var st = document.createElement('div'); document.body.appendChild(st);
      st.style.cssText = '';
      var autoComputed = getComputedStyle(st).top;
      st.style.top = 'auto';
      out.staticDefaultTop = autoComputed;
      out.staticExplicitAuto = getComputedStyle(st).top;
      st.remove();
      var bk = document.querySelector('.bulkbar');
      return out;
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
