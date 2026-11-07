'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9394, width: 1440, height: 900 });
  try {
    await p.goto('/admin/works', '.mono'); await ready(p);
    const out = [];
    for (const dpr of [1, 2, 1.5]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: dpr, mobile: false });
      await sleep(200);
      const r = await p.evaluate(`(function () {
        var el = document.querySelector('.mono');
        var cs = getComputedStyle(el);
        return { dpr: window.devicePixelRatio, font: cs.fontSize, w: el.getBoundingClientRect().width };
      })()`);
      out.push(r);
    }
    // 同时找一个"分数字号"的真实来源：看有没有相对单位链
    const chain = await p.evaluate(`(function () {
      var el = document.querySelector('.mono'), res = [], n = el;
      while (n && n.nodeType === 1 && res.length < 6) {
        var cs = getComputedStyle(n);
        res.push({ tag: n.tagName.toLowerCase() + (n.className ? '.' + String(n.className).trim().split(/\s+/).join('.') : ''), font: cs.fontSize });
        n = n.parentElement;
      }
      return res;
    })()`);
    console.log('DPR 对比:', JSON.stringify(out));
    console.log('字号继承链(自下而上):', JSON.stringify(chain, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
