'use strict';
// 后台页内锚点：secnav 的 #terms 等是否被 sticky 头遮住
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9402, width: 1440, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav');
    await ready(p); await sleep(400);
    const meta = await p.evaluate(`(function () {
      var tb = document.querySelector('.topbar'), sn = document.querySelector('.secnav');
      return { topbarH: tb ? Math.round(tb.getBoundingClientRect().height*10)/10 : null,
               secnavH: sn ? Math.round(sn.getBoundingClientRect().height*10)/10 : null,
               secnavTop: sn ? getComputedStyle(sn).top : null,
               links: sn ? Array.prototype.map.call(sn.querySelectorAll('a'), function(a){ return a.getAttribute('href'); }) : [] };
    })()`);
    console.log('头部: ' + JSON.stringify(meta));
    for (const id of ['terms', 'plot', 'advice', 'stages']) {
      const r = await p.evaluate(`(function () {
        location.hash = ''; location.hash = '#${id}';
        var el = document.getElementById('${id}');
        if (!el) return { missing: true };
        var tb = document.querySelector('.topbar'), sn = document.querySelector('.secnav');
        var tbB = tb.getBoundingClientRect().bottom, snB = sn.getBoundingClientRect().bottom;
        var r = el.getBoundingClientRect();
        var h2 = el.querySelector('h2, h3, .sec__h, h1');
        return { id: '${id}', stackBottom: Math.round(snB*10)/10, topbarBottom: Math.round(tbB*10)/10,
                 elTop: Math.round(r.top*10)/10,
                 coveredBy: Math.round(Math.max(0, snB - r.top)*10)/10,
                 headTop: h2 ? Math.round(h2.getBoundingClientRect().top*10)/10 : null,
                 headCovered: h2 ? Math.round(Math.max(0, snB - h2.getBoundingClientRect().top)*10)/10 : null,
                 scrollMargin: getComputedStyle(el).scrollMarginTop };
      })()`);
      console.log(JSON.stringify(r));
      await sleep(200);
    }
    // termline 的 108px 是否还有活链（无页内链，只测规则值）
    const tl = await p.evaluate(`(function () {
      var t = document.querySelector('.termline[id]');
      if (!t) return { none: true };
      return { id: t.id, scrollMargin: getComputedStyle(t).scrollMarginTop };
    })()`);
    console.log('termline: ' + JSON.stringify(tl));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
