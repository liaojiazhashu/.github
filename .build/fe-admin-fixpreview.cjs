'use strict';
// 预演 admin 补丁：注入等价覆盖，量重叠/可见高/锚点净空
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
const CSS = `:root{--topbar-h:60px}
@media (max-width:860px){:root{--topbar-h:56px}}
.secnav,.bulkbar{top:var(--topbar-h) !important}
.card[id],.sugg[id],.revitem[id],.termline[id]{scroll-margin-top:calc(var(--topbar-h) + 46px + 12px) !important}`;
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9406, width: 1440, height: 900 });
  try {
    for (const w of [1440, 1280, 1024, 900, 768, 600, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(250);
      await p.evaluate(`(function(){var s=document.createElement('style');s.id='preview';s.textContent=${JSON.stringify(CSS)};document.head.appendChild(s);return 1})()`);
      await sleep(150);
      const sticky = await p.evaluate(`(function () {
        window.scrollTo(0, 2000);
        var tb = document.querySelector('.topbar'), sn = document.querySelector('.secnav');
        var t = tb.getBoundingClientRect(), s = sn.getBoundingClientRect();
        return { topbarH: Math.round(t.height*10)/10, secnavTop: Math.round(s.top*10)/10,
                 overlap: Math.round(Math.max(0, t.bottom - s.top)*10)/10,
                 secnavH: Math.round(s.height*10)/10,
                 visible: Math.round((s.bottom - Math.max(s.top, t.bottom))*10)/10 };
      })()`);
      await sleep(300);
      const anchor = await p.evaluate(`(function () {
        location.hash=''; location.hash='#terms';
        var el=document.getElementById('terms'), sn=document.querySelector('.secnav');
        var b=el.getBoundingClientRect(), sb=sn.getBoundingClientRect();
        return { elTop: Math.round(b.top*10)/10, stackBottom: Math.round(sb.bottom*10)/10,
                 clearance: Math.round((b.top - sb.bottom)*10)/10 };
      })()`);
      console.log(w + '\tsticky ' + JSON.stringify(sticky) + '\tanchor ' + JSON.stringify(anchor));
      await sleep(200);
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
