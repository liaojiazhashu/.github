'use strict';
// 预演「111 合并进 113」：no-JS 骨架 + 注入等价的 MQ 覆盖，量落点
const { openAdmin, sleep, BASE } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ try { if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; } catch(e){} await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9399, width: 390, height: 900 });
  try {
    for (const w of [320, 331, 390, 442, 443, 700]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.session.send('Emulation.setScriptExecutionDisabled', { value: true });
      await p.session.send('Page.navigate', { url: BASE + '/library/terms/2435' });
      await sleep(900);
      await p.session.send('Emulation.setScriptExecutionDisabled', { value: false });
      await ready(p);
      const before = await p.evaluate(`(function(){
        return { pad: getComputedStyle(document.documentElement).scrollPaddingTop,
                 inline: document.documentElement.style.getPropertyValue('--top-h') || '(空)' }; })()`);
      // 注入等价覆盖：仅 331–442 区间把 111 抬到 113（模拟合并）
      await p.evaluate(`(function(){
        var s = document.createElement('style');
        s.textContent = '@media (min-width:331px) and (max-width:442px){:root{--top-h:113px}}';
        document.head.appendChild(s); return 1; })()`);
      await sleep(150);
      const after = await p.evaluate(`(function(){
        var top = document.querySelector('.top'), hb = top.getBoundingClientRect();
        return { pad: getComputedStyle(document.documentElement).scrollPaddingTop, topH: Math.round(hb.height*10)/10 }; })()`);
      // 触发原生锚点滚动后测量落点
      const land = await p.evaluate(`(function(){
        location.hash = ''; location.hash = '#s-works';
        var top = document.querySelector('.top'), s = document.querySelector('#s-works');
        var hb = top.getBoundingClientRect(), sb = s.getBoundingClientRect();
        var h = s.querySelector('h2');
        return { boxGap: Math.round((sb.top - hb.bottom)*10)/10,
                 headGap: h ? Math.round((h.getBoundingClientRect().top - hb.bottom)*10)/10 : null,
                 covered: sb.top < hb.bottom }; })()`);
      console.log(w + '\t合并前 ' + JSON.stringify(before) + '\t合并后 ' + JSON.stringify(after) + '\t落点 ' + JSON.stringify(land));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
