'use strict';
// 分清「区块盒子顶边」与「标题」：no-JS 路径下同时量两者
const { openAdmin, sleep, BASE } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ try { if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; } catch(e){} await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9398, width: 390, height: 900 });
  const cases = [
    ['/library/terms/2435#s-works', '#s-works', 'flat'],
    ['/library/method#trust', '#trust', 'tint'],
  ];
  try {
    for (const w of [390, 1440]) {
      for (const [path, sel, kind] of cases) {
        await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
        await p.session.send('Emulation.setScriptExecutionDisabled', { value: true });
        await p.session.send('Page.navigate', { url: BASE + path });
        await sleep(900);
        await p.session.send('Emulation.setScriptExecutionDisabled', { value: false });
        await ready(p);
        await sleep(300);
        const r = await p.evaluate(`(function () {
          var s = document.querySelector(${JSON.stringify(sel)});
          if (!s) return { missing: true };
          var top = document.querySelector('.top');
          var hb = top.getBoundingClientRect(), sb = s.getBoundingClientRect();
          var cs = getComputedStyle(s);
          var head = s.querySelector('h2, h1, .sec__h, h3');
          return { kind: ${JSON.stringify(kind)}, topH: Math.round(hb.height*10)/10,
                   pad: getComputedStyle(document.documentElement).scrollPaddingTop,
                   boxTop: Math.round(sb.top*10)/10,
                   boxGap: Math.round((sb.top - hb.bottom)*10)/10,
                   padTop: cs.paddingTop, borderTop: cs.borderTopWidth,
                   headTag: head ? head.tagName : null,
                   headTop: head ? Math.round(head.getBoundingClientRect().top*10)/10 : null,
                   headGap: head ? Math.round((head.getBoundingClientRect().top - hb.bottom)*10)/10 : null,
                   inlineTopH: document.documentElement.style.getPropertyValue('--top-h') || '(未写入)' };
        })()`);
        console.log(w + '\t' + path + '\t' + JSON.stringify(r));
      }
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
