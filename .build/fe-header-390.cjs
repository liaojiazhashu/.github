'use strict';
const fs = require('fs');
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9388, width: 390, height: 844 });
  try {
    await p.goto('/library/terms/2450', '.top'); await ready(p);
    for (const w of [442, 390, 360]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 844, deviceScaleFactor: 1, mobile: true });
      await sleep(250);
      const r = await p.evaluate(`(function () {
        var inner = document.querySelector('.top__in');
        var out = [];
        var kids = inner.children;
        for (var i = 0; i < kids.length; i++) {
          var el = kids[i], b = el.getBoundingClientRect();
          out.push({ cls: (el.className || el.tagName).toString().split(' ')[0], top: Math.round(b.top), h: Math.round(b.height), w: Math.round(b.width), left: Math.round(b.left) });
        }
        var find = document.querySelector('.find__in');
        return { topH: Math.round(document.querySelector('.top').getBoundingClientRect().height * 10) / 10,
                 kids: out, findW: find ? Math.round(find.getBoundingClientRect().width) : null,
                 docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                 subHidden: getComputedStyle(document.querySelector('.brand__sub')).display };
      })()`);
      console.log('\n' + w + ' 头高=' + r.topH + ' 搜索框宽=' + r.findW + ' 副标题=' + r.subHidden + ' 横向溢出=' + r.docOv);
      r.kids.forEach(function (k) { console.log('   ' + k.cls.padEnd(10) + ' top=' + String(k.top).padStart(4) + ' h=' + String(k.h).padStart(3) + ' w=' + String(k.w).padStart(4) + ' left=' + k.left); });
      if (w === 390) {
        const box = await p.evaluate(`(function(){var b=document.querySelector('.top').getBoundingClientRect();return {x:0,y:b.top+window.scrollY,w:390,h:b.height}})()`);
        await p.session.send('Emulation.setDeviceMetricsOverride', { width: 390, height: Math.ceil(box.y + box.h + 10), deviceScaleFactor: 1, mobile: true });
        const shot = await p.session.send('Page.captureScreenshot', { format: 'png', clip: { x: box.x, y: box.y, width: box.w, height: box.h, scale: 2 }, captureBeyondViewport: true });
        fs.writeFileSync('.build/fe-header-390.png', Buffer.from(shot.data, 'base64'));
        console.log('   截图 .build/fe-header-390.png');
      }
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
