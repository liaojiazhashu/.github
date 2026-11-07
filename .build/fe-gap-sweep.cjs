'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9387, width: 1440, height: 900 });
  try {
    await p.goto('/library/terms/2450', '.split__main'); await ready(p);
    for (const w of [1440, 960, 768, 560, 520, 442, 400, 390, 360]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await sleep(250);
      await p.evaluate(`(function(){ window.scrollTo(0,0); location.hash=''; location.hash='#s-works'; })()`);
      await sleep(320);
      const r = await p.evaluate(`(function () {
        var s = document.getElementById('s-works');
        var top = document.querySelector('.top');
        var th = top.getBoundingClientRect().height;
        var secTop = s.getBoundingClientRect().top;
        return { topH: Math.round(th * 100) / 100, secTop: Math.round(secTop * 100) / 100,
                 gap: Math.round((secTop - th) * 100) / 100,
                 token: getComputedStyle(document.documentElement).getPropertyValue('--top-h').trim(),
                 inline: document.documentElement.style.getPropertyValue('--top-h'),
                 scrollPad: getComputedStyle(document.documentElement).scrollPaddingTop };
      })()`);
      const ok = Math.abs(r.gap - 12) <= 1.5;
      console.log(String(w).padStart(5), ok ? 'OK  ' : 'FAIL', '头', String(r.topH).padStart(7), '| --top-h', (r.inline||'(无)').padStart(12), '| scroll-pad', r.scrollPad.padStart(9), '| 落点=头下', r.gap);
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
