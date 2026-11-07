#!/usr/bin/env node
/* 放大截一张「四阶段都在」的节点条带，scale=2 便于肉眼比对 */
'use strict';
const fs = require('fs');
const { openAdmin } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9339, width: 1440, height: 1000 });
  try {
    await p.goto('/admin/works', 'table');
    const list = await p.evaluate(`Array.from(document.querySelectorAll('a[href]')).map(a=>a.getAttribute('href')).filter(h=>/^\\/admin\\/works\\/[0-9A-HJKMNP-TV-Z]{26}$/.test(h))`);
    let path = null;
    for (const h of list.slice(0, 8)) {
      if (!(await p.goto(h, '.card'))) continue;
      path = await p.evaluate(`Array.from(document.querySelectorAll('a[href]')).map(a=>a.getAttribute('href')).filter(h=>/^\\/admin\\/analyses\\/[0-9A-HJKMNP-TV-Z]{26}$/.test(h))[0] || null`);
      if (path) break;
    }
    if (!path) throw new Error('没有拆解页');
    await p.goto(path, '.beat');
    await p.evaluate(`(async () => { await document.fonts.ready; return 1; })()`);

    // 找一段连续的、四种阶段都出现的节点
    const box = await p.evaluate(`(function(){
      var beats = Array.prototype.slice.call(document.querySelectorAll('.beat'));
      function ph(el){ var m = el.className.match(/beat--(start|turn|crisis|end)/); return m ? m[1] : '?'; }
      var best = null;
      for (var i = beats.length - 1; i >= 0; i--) {
        var seen = {}, n = 0;
        for (var j = i; j >= 0 && j > i - 12; j--) {
          var k = ph(beats[j]); seen[k] = 1;
          n = Object.keys(seen).length;
          if (seen.start && seen.turn && seen.crisis && seen.end) { best = [j, i]; break; }
        }
        if (best) break;
      }
      if (!best) best = [0, Math.min(beats.length - 1, 7)];
      var a = beats[best[0]].getBoundingClientRect(), b = beats[best[1]].getBoundingClientRect();
      return { x: a.left + window.scrollX - 22, y: a.top + window.scrollY - 6, w: a.width + 28, h: b.bottom - a.top + 12,
               from: best[0] + 1, to: best[1] + 1, phases: beats.slice(best[0], best[1]+1).map(ph) };
    })()`);
    console.log('条带:', JSON.stringify(box));

    await p.session.send('Emulation.setDeviceMetricsOverride', {
      width: 1440, height: Math.ceil(box.y + box.h + 20), deviceScaleFactor: 1, mobile: false
    });
    const shot = await p.session.send('Page.captureScreenshot', {
      format: 'png',
      clip: { x: box.x, y: box.y, width: box.w, height: box.h, scale: 2 },
      captureBeyondViewport: true
    });
    const out = 'D:/AITEST/huangqi-scifi/.build/fe-beats-zoom.png';
    fs.writeFileSync(out, Buffer.from(shot.data, 'base64'));
    console.log('输出:', out);
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
