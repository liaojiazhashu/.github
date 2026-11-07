'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9375, width: 1440, height: 900 });
  try {
    await p.goto('/library/method', '.sec--tint');
    for (let i = 0; i < 20; i++) { if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) break; await sleep(200); }
    for (const pad of [85, 51]) {
      await p.evaluate(`document.documentElement.style.scrollPaddingTop = '${pad}px';`);
      await p.evaluate(`(function(){ window.scrollTo(0,0); location.hash=''; location.hash='#trust'; })()`);
      await sleep(350);
      const r = await p.evaluate(`(function () {
        var s = document.getElementById('trust');
        var h2 = s.querySelector('h2');
        var top = document.querySelector('.top');
        var th = top.getBoundingClientRect().height;
        var secTop = s.getBoundingClientRect().top;
        var h2Top = h2 ? h2.getBoundingClientRect().top : null;
        var cs = getComputedStyle(s);
        return { pad: ${pad}, secTop: Math.round(secTop * 10) / 10, h2Top: Math.round(h2Top * 10) / 10,
                 topH: Math.round(th * 10) / 10, padTop: cs.paddingTop,
                 borderHiddenBy: Math.round(Math.max(0, th - secTop) * 10) / 10,
                 h2BelowHeader: Math.round((h2Top - th) * 10) / 10 };
      })()`);
      console.log(JSON.stringify(r));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
