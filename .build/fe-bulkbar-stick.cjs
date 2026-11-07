'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9421, width: 1440, height: 900 });
  try {
    for (const w of [1440, 860, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/works', '.bulkbar'); await ready(p); await sleep(300);
      const r = await p.evaluate(`(function () {
        window.scrollTo(0, 3000);
        var tb=document.querySelector('.topbar'), b=document.querySelector('.bulkbar');
        var t=tb.getBoundingClientRect(), x=b.getBoundingClientRect();
        return { topbarH: Math.round(t.height*10)/10, bulkTop: Math.round(x.top*10)/10,
                 overlap: Math.round(Math.max(0,t.bottom-x.top)*10)/10,
                 visibleH: Math.round((x.bottom-Math.max(x.top,t.bottom))*10)/10,
                 bulkH: Math.round(x.height*10)/10, scrollY: Math.round(window.scrollY),
                 docH: Math.round(document.documentElement.scrollHeight) };
      })()`);
      console.log(w + '\t' + JSON.stringify(r));
      await sleep(200);
    }
    // terms 列表页也有 .bulkbar
    await p.goto('/admin/terms', '.bulkbar'); await ready(p); await sleep(300);
    const r2 = await p.evaluate(`(function () {
      window.scrollTo(0, 3000);
      var tb=document.querySelector('.topbar'), b=document.querySelector('.bulkbar');
      if (!b) return { none: true };
      var t=tb.getBoundingClientRect(), x=b.getBoundingClientRect();
      return { topbarH: Math.round(t.height*10)/10, bulkTop: Math.round(x.top*10)/10,
               overlap: Math.round(Math.max(0,t.bottom-x.top)*10)/10, visibleH: Math.round((x.bottom-Math.max(x.top,t.bottom))*10)/10 };
    })()`);
    console.log('terms\t' + JSON.stringify(r2));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
