#!/usr/bin/env node
/* 验收：吸顶头高度 → --top-h → scroll-padding-top 是否一致（四档宽度）
   同时验证点锚点后区块盒顶边真的落在头部下方 12px。 */
'use strict';

const fs = require('fs');
const path = require('path');
const { openAdmin, httpRequest } = require('./lib/browser.cjs');

const ROOT = path.resolve(__dirname, '..');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';

const WIDTHS = (process.env.WIDTHS || '1440,960,768,560,430,390').split(',').map((s) => parseInt(s, 10));

(async () => {
  // 找一个有 enc.history 的词条（有 4 个分区才有 4 个锚点）
  const list = await httpRequest(`${BASE}/library/terms`);
  const ids = [...new Set([...list.body.matchAll(/\/library\/terms\/(\d+)/g)].map((m) => m[1]))];
  let termId = ids.find(Boolean);
  for (const id of ids.slice(0, 12)) {
    const page = await httpRequest(`${BASE}/library/terms/${id}`);
    if (page.body.includes('id="s-history"')) { termId = id; break; }
  }
  console.log('词条:', termId, '（含 s-history 四分区）');

  const rows = [];

  for (const w of WIDTHS) {
    const page = await openAdmin({
      user: process.env.ADMIN_USER || 'ui_audit',
      pass: process.env.ADMIN_PASS || 'UIAudit#2026tmp',
      port: parseInt(process.env.DEBUG_PORT || '9357', 10) + WIDTHS.indexOf(w),
      width: w,
      height: 800,
      // MOBILE=1 走设备模拟。**验断点边界必须开**：不开时滚动条占 15px，
      // 宽度 442 实际 clientWidth 427、760 实际 745，会跨到隔壁档。
      mobile: process.env.MOBILE === '1'
    });

    await page.goto(`/library/terms/${termId}`, '.sec');
    for (let i = 0; i < 30; i++) {
      const ready = await page.evaluate(`document.readyState === 'complete' && getComputedStyle(document.documentElement).getPropertyValue('--top-h').trim() !== ''`);
      if (ready) break;
      await new Promise((r) => setTimeout(r, 200));
    }

    const before = await page.evaluate(`(function(){
      var cs = getComputedStyle(document.documentElement);
      var top = document.querySelector('.top');
      return JSON.stringify({
        headerH: Math.round(top.getBoundingClientRect().height * 10) / 10,
        topH: cs.getPropertyValue('--top-h').trim(),
        padTop: cs.scrollPaddingTop,
        inlineSet: document.documentElement.style.getPropertyValue('--top-h').trim() || '(未设)'
      });
    })()`);

    // 点 #s-works 锚点，量落点
    await page.evaluate(`(function(){ location.hash = '#s-works'; })()`);
    await new Promise((r) => setTimeout(r, 500));

    const after = await page.evaluate(`(function(){
      var sec = document.querySelector('#s-works');
      var h2 = sec.querySelector('h2');
      var top = document.querySelector('.top');
      return JSON.stringify({
        secTop: Math.round(sec.getBoundingClientRect().top),
        h2Top: Math.round(h2.getBoundingClientRect().top),
        headerH: Math.round(top.getBoundingClientRect().height)
      });
    })()`);

    const b = JSON.parse(before);
    const a = JSON.parse(after);
    rows.push({ w, ...b, ...a, secGap: a.secTop - a.headerH, h2Gap: a.h2Top - a.headerH });
    console.log(`${w}px  头 ${b.headerH}  --top-h ${b.topH}  scroll-padding-top ${b.padTop}  inline ${b.inlineSet}  |  #s-works 盒顶 ${a.secTop}（头下 ${a.secTop - a.headerH}）  h2 ${a.h2Top}（头下 ${a.h2Top - a.headerH}）`);

    if (w === 1440 || w === 390) {
      const p = await page.screenshot(path.join(ROOT, '.build', 'verify-beat', `anchor-${w}.png`));
      console.log('   截图', path.relative(ROOT, p));
    }

    await page.close();
  }

  console.log('\n判定：盒顶到头部距离应 ≈12，h2 到头部距离应 ≈46');
  const bad = rows.filter((r) => Math.abs(r.secGap - 12) > 2);
  console.log(bad.length ? '不合格档位：' + bad.map((r) => r.w).join(', ') : ' 全档通过');
  fs.writeFileSync(path.join(ROOT, '.build', 'verify-beat', 'anchor.json'), JSON.stringify(rows, null, 2));
})().catch((e) => { console.error('失败:', e.message); process.exit(1); });
