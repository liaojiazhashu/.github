#!/usr/bin/env node
/* A 项验收：三档 × 三个锚点，区块盒顶边 = --top-h+12，h2 顶边 = --top-h+46 */
'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

const CASES = [
  { w: 1440, h: 900, topH: 85 },
  { w: 768, h: 1024, topH: 124 },
  { w: 390, h: 844, topH: 174 },
  { w: 960, h: 900, topH: 124 },
  { w: 560, h: 900, topH: 174 }
];
const ANCHORS = ['s-history', 's-classics', 's-works'];

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9362, width: 1440, height: 900 });
  try {
    await p.goto('/library/terms/2450', '.split__main');
    await p.evaluate(`(function(){ return document.readyState; })()`);

    const rows = [];
    for (const c of CASES) {
      await p.session.send('Emulation.setDeviceMetricsOverride', {
        width: c.w, height: c.h, deviceScaleFactor: 1, mobile: c.w < 500
      });
      await sleep(200);
      for (const id of ANCHORS) {
        const res = await p.evaluate(`(function () {
          var a = document.querySelector('.split__aside .toc a[href="#${id}"]');
          if (!a) return { skip: '无锚点链接' };
          window.scrollTo(0, 0);
          a.click();
          return { clicked: true };
        })()`);
        if (res.skip) { rows.push({ w: c.w, id: id, note: res.skip }); continue; }
        await sleep(320);
        const m = await p.evaluate(`(function () {
          var s = document.getElementById('${id}');
          var h2 = s.querySelector('h2');
          var top = document.querySelector('.top');
          var secTop = Math.round(s.getBoundingClientRect().top * 10) / 10;
          var h2Top = h2 ? Math.round(h2.getBoundingClientRect().top * 10) / 10 : null;
          return {
            secTop: secTop, h2Top: h2Top,
            topH: Math.round(top.getBoundingClientRect().height * 10) / 10,
            token: getComputedStyle(document.documentElement).getPropertyValue('--top-h').trim(),
            scrollPad: getComputedStyle(document.documentElement).scrollPaddingTop,
            covered: Math.round(Math.max(0, top.getBoundingClientRect().height - (h2Top === null ? secTop : h2Top)))
          };
        })()`);
        rows.push({ w: c.w, id: id, expectSec: c.topH, expectH2: c.topH + 34, ...m });
      }
    }

    console.log('宽度  锚点        区块顶(期望)    h2顶(期望)    --top-h  scroll-padding  标题被盖');
    const fail = [];
    rows.forEach((r) => {
      if (r.note) { console.log(String(r.w).padStart(5), r.id.padEnd(12), r.note); return; }
      const okSec = Math.abs(r.secTop - r.expectSec) <= 1;
      const okH2 = Math.abs(r.h2Top - r.expectH2) <= 1;
      console.log(
        String(r.w).padStart(5), r.id.padEnd(12),
        (String(r.secTop) + ' (' + r.expectSec + ')').padEnd(14),
        (String(r.h2Top) + ' (' + r.expectH2 + ')').padEnd(14),
        String(r.token).padEnd(9), String(r.scrollPad).padEnd(6), r.covered
      );
      if (!okSec) fail.push(r.w + ' ' + r.id + ' 区块顶 ' + r.secTop + ' ≠ ' + r.expectSec);
      if (!okH2) fail.push(r.w + ' ' + r.id + ' h2顶 ' + r.h2Top + ' ≠ ' + r.expectH2);
      if (r.covered > 0) fail.push(r.w + ' ' + r.id + ' 标题被盖 ' + r.covered + 'px');
    });
    console.log('\n失败项:', fail.length ? fail : '无');
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
