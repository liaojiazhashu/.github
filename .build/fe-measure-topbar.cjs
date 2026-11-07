#!/usr/bin/env node
/* 只量不改：.top 高度随断点的分布 + 390 下三行构成 + 隐藏副标题的试算 */
'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

const SWEEP = [1440, 1200, 1100, 1024, 961, 960, 900, 850, 800, 768, 761, 760, 700, 640, 600, 561, 560, 500, 430, 390, 360];

const probe = `(function () {
  var top = document.querySelector('.top');
  var inner = document.querySelector('.top__in');
  var h = top ? Math.round(top.getBoundingClientRect().height * 10) / 10 : null;
  var kids = inner ? Array.prototype.map.call(inner.children, function (el) {
    var r = el.getBoundingClientRect();
    return {
      cls: (el.className || el.tagName).toString().slice(0, 22),
      top: Math.round(r.top), h: Math.round(r.height), w: Math.round(r.width), left: Math.round(r.left)
    };
  }) : [];
  // 按 top 值聚成行
  var rows = {};
  kids.forEach(function (k) { var key = Math.round(k.top / 4) * 4; (rows[key] = rows[key] || []).push(k.cls); });
  return { topH: h, innerH: inner ? Math.round(inner.getBoundingClientRect().height) : null, kids: kids, rows: rows };
})()`;

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9360, width: 1440, height: 900 });
  try {
    await p.goto('/library/terms/2450', '.top');
    await p.evaluate(`document.readyState`);

    console.log('width  .top高  行数  每行成员');
    const heights = {};
    for (const w of SWEEP) {
      await p.session.send('Emulation.setDeviceMetricsOverride', {
        width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500
      });
      await sleep(160);
      const r = await p.evaluate(probe);
      heights[w] = r.topH;
      const rowList = Object.keys(r.rows).map(function (k) { return r.rows[k].join('+'); });
      console.log(String(w).padStart(5), String(r.topH).padStart(7), String(rowList.length).padStart(5), '  ', rowList.join(' | '));
    }

    // 390 详细构成
    await p.session.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await sleep(200);
    const at390 = await p.evaluate(probe);
    console.log('\n=== 390 明细 ===');
    console.log('top 高:', at390.topH, '| top__in 高:', at390.innerH);
    at390.kids.forEach(function (k) { console.log('  ', k.cls.padEnd(22), 'top=' + String(k.top).padStart(4), 'h=' + String(k.h).padStart(3), 'w=' + String(k.w).padStart(4), 'left=' + k.left); });

    // 试算：隐藏 .brand__sub（装饰副标题）后总高
    const noSub = await p.evaluate(`(function () {
      var sub = document.querySelector('.brand__sub');
      var before = document.querySelector('.top').getBoundingClientRect().height;
      var rowsBefore = Array.prototype.map.call(document.querySelectorAll('.top__in > *'), function (el) {
        var r = el.getBoundingClientRect(); return { cls: (el.className || '').toString().slice(0, 20), top: Math.round(r.top), w: Math.round(r.width) };
      });
      if (sub) sub.style.display = 'none';
      var after = document.querySelector('.top').getBoundingClientRect().height;
      var rowsAfter = Array.prototype.map.call(document.querySelectorAll('.top__in > *'), function (el) {
        var r = el.getBoundingClientRect(); return { cls: (el.className || '').toString().slice(0, 20), top: Math.round(r.top), w: Math.round(r.width) };
      });
      if (sub) sub.style.display = '';
      return { before: Math.round(before * 10) / 10, after: Math.round(after * 10) / 10, rowsBefore: rowsBefore, rowsAfter: rowsAfter };
    })()`);
    console.log('\n=== 隐藏 .brand__sub 试算（已还原） ===');
    console.log('现在:', noSub.before + 'px → 隐藏后:', noSub.after + 'px');
    console.log('隐藏前各行 top:', JSON.stringify(noSub.rowsBefore));
    console.log('隐藏后各行 top:', JSON.stringify(noSub.rowsAfter));

    console.log('\n=== 断点区间最坏值 ===');
    const le560 = Object.keys(heights).filter(function (w) { return +w <= 560; }).map(function (w) { return heights[w]; });
    const mid = Object.keys(heights).filter(function (w) { return +w > 560 && +w <= 960; }).map(function (w) { return heights[w]; });
    const wide = Object.keys(heights).filter(function (w) { return +w > 960; }).map(function (w) { return heights[w]; });
    console.log('>960 段最大:', Math.max.apply(null, wide), '| 561–960 段最大:', Math.max.apply(null, mid), '| ≤560 段最大:', Math.max.apply(null, le560));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
