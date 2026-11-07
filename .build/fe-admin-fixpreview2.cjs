'use strict';
// CSP 挡掉 <style> 注入，改用 CSSOM 内联属性（不受 style-src 限制）预演补丁几何
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9408, width: 1440, height: 900 });
  try {
    for (const w of [1440, 1024, 900, 768, 600, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(250);
      const r = await p.evaluate(`(function () {
        var tb = document.querySelector('.topbar'), sn = document.querySelector('.secnav');
        var topH = Math.round(tb.getBoundingClientRect().height);       // 60 / 56
        var smTop = topH + 46 + 12;                                      // 补丁后的 scroll-margin
        // 预演：把 sticky top 设为实高，锚点 scroll-margin 设为派生值
        sn.style.top = topH + 'px';
        var targets = document.querySelectorAll('.card[id], .sugg[id], .revitem[id], .termline[id]');
        for (var i = 0; i < targets.length; i++) targets[i].style.scrollMarginTop = smTop + 'px';
        window.scrollTo(0, 2000);
        var t = tb.getBoundingClientRect(), s = sn.getBoundingClientRect();
        var out = { width: ${w}, topbarH: Math.round(t.height*10)/10, secnavTop: Math.round(s.top*10)/10,
                    overlap: Math.round(Math.max(0, t.bottom - s.top)*10)/10,
                    visibleH: Math.round((s.bottom - Math.max(s.top, t.bottom))*10)/10,
                    secnavH: Math.round(s.height*10)/10, smTop: smTop };
        location.hash = ''; location.hash = '#terms';
        var el = document.getElementById('terms');
        var eb = el.getBoundingClientRect(), sb = sn.getBoundingClientRect();
        out.elTop = Math.round(eb.top*10)/10;
        out.stackBottom = Math.round(sb.bottom*10)/10;
        out.clearance = Math.round((eb.top - sb.bottom)*10)/10;
        out.covered = eb.top < sb.bottom;
        return out;
      })()`);
      console.log(w + '\t' + JSON.stringify(r));
      await sleep(150);
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
