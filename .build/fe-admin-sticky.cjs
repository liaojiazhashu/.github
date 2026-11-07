'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9404, width: 1440, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav');
    await ready(p); await sleep(300);
    await p.evaluate('(function(){window.scrollTo(0,2000);return 1})()');
    await sleep(400);
    const r = await p.evaluate(`(function () {
      var tb = document.querySelector('.topbar'), sn = document.querySelector('.secnav');
      var t = tb.getBoundingClientRect(), s = sn.getBoundingClientRect();
      var btn = sn.querySelector('.secnav__item').getBoundingClientRect();
      return { topbar: { top: Math.round(t.top*10)/10, bottom: Math.round(t.bottom*10)/10, h: Math.round(t.height*10)/10 },
               secnav: { top: Math.round(s.top*10)/10, bottom: Math.round(s.bottom*10)/10, h: Math.round(s.height*10)/10,
                         cssTop: getComputedStyle(sn).top, z: getComputedStyle(sn).zIndex },
               overlap: Math.round(Math.max(0, t.bottom - s.top)*10)/10,
               visibleSecnavH: Math.round((s.bottom - Math.max(s.top, t.bottom))*10)/10,
               firstBtnTop: Math.round(btn.top*10)/10,
               btnClipped: Math.round(Math.max(0, t.bottom - btn.top)*10)/10 };
    })()`);
    console.log(JSON.stringify(r, null, 1));
    // 同页 works 列表的 bulkbar 是否同病
    await p.goto('/admin/works', '.bulkbar');
    await ready(p); await sleep(300);
    await p.evaluate('(function(){window.scrollTo(0,600);return 1})()');
    await sleep(400);
    const r2 = await p.evaluate(`(function () {
      var tb = document.querySelector('.topbar'), b = document.querySelector('.bulkbar');
      if (!b) return { none: true };
      var t = tb.getBoundingClientRect(), x = b.getBoundingClientRect();
      return { topbarBottom: Math.round(t.bottom*10)/10, bulkTop: Math.round(x.top*10)/10,
               cssTop: getComputedStyle(b).top, overlap: Math.round(Math.max(0, t.bottom - x.top)*10)/10 };
    })()`);
    console.log('works/bulkbar: ' + JSON.stringify(r2));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
