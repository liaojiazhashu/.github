'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
const IDS = ['overview','ops','stages','terms','plot','advice','revision','snapshot'];
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9420, width: 1440, height: 900 });
  try {
    console.log('=== 吸顶栈 + scroll-margin 生效值 ===');
    for (const w of [1440, 1280, 1024, 900, 861, 860, 768, 600, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(250);
      await p.evaluate('(function(){window.scrollTo(0,2000);return 1})()'); await sleep(300);
      const r = await p.evaluate(`(function () {
        var tb=document.querySelector('.topbar'), sn=document.querySelector('.secnav');
        var t=tb.getBoundingClientRect(), s=sn.getBoundingClientRect();
        var card=document.querySelector('.card[id]');
        return { topbarH: Math.round(t.height*10)/10,
                 secnavTop: Math.round(s.top*10)/10, secnavH: Math.round(s.height*10)/10,
                 overlap: Math.round(Math.max(0,t.bottom-s.top)*10)/10,
                 visibleH: Math.round((s.bottom-Math.max(s.top,t.bottom))*10)/10,
                 tokTopbar: getComputedStyle(document.documentElement).getPropertyValue('--topbar-h').trim(),
                 tokSecnav: getComputedStyle(document.documentElement).getPropertyValue('--secnav-h').trim(),
                 scrollMargin: card ? getComputedStyle(card).scrollMarginTop : 'no-.card[id]' };
      })()`);
      console.log(w + '\t' + JSON.stringify(r));
    }
    console.log('\n=== works 页 .bulkbar ===');
    for (const w of [1440, 860, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/works', '.bulkbar'); await ready(p); await sleep(250);
      await p.evaluate('(function(){window.scrollTo(0,600);return 1})()'); await sleep(300);
      const r = await p.evaluate(`(function () {
        var tb=document.querySelector('.topbar'), b=document.querySelector('.bulkbar');
        var t=tb.getBoundingClientRect(), x=b.getBoundingClientRect();
        return { topbarH: Math.round(t.height*10)/10, bulkTop: Math.round(x.top*10)/10,
                 overlap: Math.round(Math.max(0,t.bottom-x.top)*10)/10,
                 visibleH: Math.round((x.bottom-Math.max(x.top,t.bottom))*10)/10,
                 bulkH: Math.round(x.height*10)/10 };
      })()`);
      console.log(w + '\t' + JSON.stringify(r));
    }
    console.log('\n=== 7 个锚点净空（1440 / 390）===');
    for (const w of [1440, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(250);
      const out = [];
      for (const id of IDS) {
        const r = await p.evaluate(`(function () {
          location.hash=''; location.hash='#${id}';
          var el=document.getElementById('${id}');
          if(!el) return null;
          var sn=document.querySelector('.secnav');
          var eb=el.getBoundingClientRect(), sb=sn.getBoundingClientRect();
          return { id:'${id}', clearance: Math.round((eb.top-sb.bottom)*10)/10, covered: eb.top < sb.bottom };
        })()`);
        out.push(r);
        await sleep(150);
      }
      console.log(w + '\t' + JSON.stringify(out));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
