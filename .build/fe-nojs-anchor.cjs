'use strict';
// 独立复现 ux 的 no-JS 锚点验收：禁脚本加载 → 原生片段跳转 → 恢复脚本后测量
const { openAdmin, sleep, BASE } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ try { if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; } catch(e){} await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9397, width: 390, height: 900 });
  try {
    for (const w of [320, 330, 331, 360, 390, 442, 443, 560, 700, 900, 1440]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.session.send('Emulation.setScriptExecutionDisabled', { value: true });
      await p.session.send('Page.navigate', { url: BASE + '/library/terms/2435#s-works' });
      await sleep(900);
      await p.session.send('Emulation.setScriptExecutionDisabled', { value: false });
      await ready(p);
      await sleep(300);
      const r = await p.evaluate(`(function () {
        var top = document.querySelector('.top'), h = document.querySelector('#s-works');
        var hb = top.getBoundingClientRect(), hd = h.getBoundingClientRect();
        var cs = getComputedStyle(document.documentElement);
        return { topH: Math.round(hb.height*10)/10,
                 inlineTopH: document.documentElement.style.getPropertyValue('--top-h') || '(未写入,说明JS没跑)',
                 pad: cs.scrollPaddingTop,
                 headerBottom: Math.round(hb.bottom*10)/10,
                 headTop: Math.round(hd.top*10)/10,
                 gap: Math.round((hd.top - hb.bottom)*10)/10,
                 covered: hd.top < hb.bottom,
                 y: Math.round(window.scrollY) };
      })()`);
      console.log(w + '\t' + JSON.stringify(r));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
