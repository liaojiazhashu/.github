'use strict';
/* 折行点与滚动条：mobile:true(无滚动条) vs mobile:false(桌面缩窗，滚动条占宽) */
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9390, width: 1440, height: 900 });
  try {
    await p.goto('/library/terms/2450', '.top'); await ready(p);
    for (const mobile of [true, false]) {
      console.log('\nmobile=' + mobile + (mobile ? '（无滚动条，真机语义）' : '（桌面缩窗，滚动条占宽）'));
      for (const w of [460, 448, 445, 442, 440, 435, 430, 427, 425, 420]) {
        await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 844, deviceScaleFactor: 1, mobile });
        await sleep(180);
        const r = await p.evaluate(`(function () {
          var top = document.querySelector('.top');
          var cs = getComputedStyle(document.querySelector('.brand__sub'));
          return { topH: Math.round(top.getBoundingClientRect().height * 10) / 10,
                   inner: window.innerWidth, client: document.documentElement.clientWidth,
                   sb: window.innerWidth - document.documentElement.clientWidth,
                   sub: cs.display,
                   rows: (function () {
                     var kids = document.querySelector('.top__in').children, rows = [];
                     for (var i = 0; i < kids.length; i++) {
                       var b = kids[i].getBoundingClientRect(), hit = null;
                       for (var j = 0; j < rows.length; j++) if (Math.abs(rows[j].top - b.top) < 8) hit = rows[j];
                       if (hit) { hit.n++; } else rows.push({ top: b.top, n: 1 });
                     }
                     return rows.length;
                   })() };
        })()`);
        console.log('  ' + String(w).padStart(4) + '  头高 ' + String(r.topH).padStart(6) + '  行数 ' + r.rows +
                    '  可用宽 ' + r.client + '  滚动条 ' + r.sb + '  副标题 ' + r.sub);
      }
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
