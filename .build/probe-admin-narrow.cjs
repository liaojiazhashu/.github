#!/usr/bin/env node
/* 后台窄屏巡检视图验证：390 下 1) 提示条出现 2) 作品表可横向滚动 3) 关键列有真实宽度；1440 下反证。 */
'use strict';
const path = require('path');
const { openAdmin } = require(path.resolve(__dirname, '..', 'tools', 'lib', 'browser.cjs'));

const PROBE = `(() => {
  const de = document.documentElement;
  const bar = document.querySelector('.narrowbar');
  const wrap = document.querySelector('.table-wrap');
  const tbl = wrap ? wrap.querySelector('table') : null;
  const ths = tbl ? Array.from(tbl.querySelectorAll('thead th')).map((th) => ({ t: th.textContent.trim().slice(0, 6), w: Math.round(th.getBoundingClientRect().width) })) : [];
  return {
    cw: de.clientWidth,
    docOverflow: de.scrollWidth - de.clientWidth,
    barVisible: bar ? getComputedStyle(bar).display !== 'none' : null,
    barH: bar ? Math.round(bar.getBoundingClientRect().height) : null,
    wrapW: wrap ? Math.round(wrap.getBoundingClientRect().width) : null,
    wrapScrollW: wrap ? wrap.scrollWidth : null,
    tableW: tbl ? Math.round(tbl.getBoundingClientRect().width) : null,
    ths: ths
  };
})()`;

(async () => {
  for (const [w, h] of (process.env.CASE === 'desk' ? [[1440, 900]] : [[390, 844]])) {
    const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9430 + w % 7, width: w, height: h });
    await p.goto('/admin/works');
    await new Promise((r) => setTimeout(r, 600));
    const d = await p.evaluate(PROBE);
    console.log(`\n=== ${w}×${h} ===`);
    console.log(JSON.stringify(d, null, 1));
    await p.close();
  }
})().catch((e) => { console.error('FAILED', e.message); process.exit(1); });
