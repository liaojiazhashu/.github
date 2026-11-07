#!/usr/bin/env node
/* fe 验证：后台字体 + 节点轴四阶段色彩 */
'use strict';
const { openAdmin } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

const ULID = /\/admin\/(?:works|analyses)\/[0-9A-HJKMNP-TV-Z]{26}/;

const log = (...a) => console.log(...a);

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9338, width: 1440, height: 1000 });
  try {
    const okList = await p.goto('/admin/works', 'table');
    log('列表页:', okList);

    const hrefs = await p.evaluate(`Array.from(document.querySelectorAll('a[href]')).map(a=>a.getAttribute('href')).filter(h=>/^\\/admin\\/works\\/[0-9A-HJKMNP-TV-Z]{26}$/.test(h))`);
    log('作品链接数:', hrefs.length, hrefs.slice(0, 3));

    let analysisPath = null;
    let tried = 0;
    for (const h of hrefs) {
      tried++;
      const ok = await p.goto(h, '.card');
      if (!ok) continue;
      const a = await p.evaluate(`Array.from(document.querySelectorAll('a[href]')).map(a=>a.getAttribute('href')).filter(h=>/^\\/admin\\/analyses\\/[0-9A-HJKMNP-TV-Z]{26}$/.test(h))[0] || null`);
      if (a) { analysisPath = a; break; }
      if (tried >= 8) break;
    }
    log('拆解结果页:', analysisPath);
    if (!analysisPath) throw new Error('没找到拆解结果页链接');

    const okA = await p.goto(analysisPath, '.beat');
    log('拆解页就绪:', okA);

    // 等字体真正加载完
    await p.evaluate(`(async () => { await document.fonts.ready; return true; })()`);

    const out = await p.evaluate(`(function () {
      const g = (el, k) => el ? getComputedStyle(el)[k] : null;
      const q = (s) => document.querySelector(s);
      const counts = {};
      ['start','turn','crisis','end'].forEach(ph => {
        counts[ph] = document.querySelectorAll('.beat--' + ph).length;
      });
      const bodyFF = g(document.body, 'fontFamily');
      const phaseFF = g(q('.beat__phase'), 'fontFamily');
      const monoFF = g(q('.mono'), 'fontFamily');
      const startP = g(q('.beat--start .beat__phase'), 'color');
      const turnP  = g(q('.beat--turn .beat__phase'), 'color');
      const crisisP = g(q('.beat--crisis .beat__phase'), 'color');
      const endP   = g(q('.beat--end .beat__phase'), 'color');
      const startM = g(q('.beat--start .beat__meter-fill'), 'backgroundColor');
      const turnM  = g(q('.beat--turn .beat__meter-fill'), 'backgroundColor');
      const crisisM = g(q('.beat--crisis .beat__meter-fill'), 'backgroundColor');
      const endM   = g(q('.beat--end .beat__meter-fill'), 'backgroundColor');
      const seqColors = {};
      ['start','turn','crisis','end'].forEach(ph => {
        const el = q('.beat--' + ph + ' .beat__seq');
        seqColors[ph] = el ? getComputedStyle(el).color : null;
      });
      return {
        total: document.querySelectorAll('.beat').length,
        counts,
        bodyFF, phaseFF, monoFF,
        fontChecks: {
          jetbrains: document.fonts.check('12px "JetBrains Mono"'),
          inter: document.fonts.check('12px "Inter"'),
          noto: document.fonts.check('12px "Noto Sans SC"')
        },
        phaseColors: { start: startP, turn: turnP, crisis: crisisP, end: endP },
        meterColors: { start: startM, turn: turnM, crisis: crisisM, end: endM },
        seqColors,
        pageH: document.documentElement.scrollHeight,
        loaded: Array.from(document.fonts).filter(f => f.status === 'loaded').map(f => f.family + ' ' + f.weight)
      };
    })()`);

    log('\n===== 实测 =====');
    log('节点总数:', out.total, '| 页面高:', out.pageH);
    log('四阶段 class 计数:', JSON.stringify(out.counts));
    log('body font-family:', out.bodyFF);
    log('.beat__phase font-family:', out.phaseFF);
    log('.mono font-family:', out.monoFF);
    log('document.fonts.check:', JSON.stringify(out.fontChecks));
    log('已加载字体:', JSON.stringify(out.loaded));
    log('阶段名颜色:', JSON.stringify(out.phaseColors));
    log('计量条背景:', JSON.stringify(out.meterColors));
    log('序号颜色(应全同):', JSON.stringify(out.seqColors));

    const expect = { crisis: 'rgb(224, 168, 78)', end: 'rgb(95, 211, 160)' };
    const fail = [];
    if (!out.fontChecks.jetbrains) fail.push('JetBrains Mono 未生效');
    if (out.phaseColors.crisis !== expect.crisis) fail.push('crisis 名色 = ' + out.phaseColors.crisis);
    if (out.phaseColors.end !== expect.end) fail.push('end 名色 = ' + out.phaseColors.end);
    if (out.meterColors.crisis !== expect.crisis) fail.push('crisis 条色 = ' + out.meterColors.crisis);
    if (out.meterColors.end !== expect.end) fail.push('end 条色 = ' + out.meterColors.end);
    if (out.phaseColors.crisis === out.phaseColors.end) fail.push('危机与结局同色');
    log('\n断言失败项:', fail.length ? fail : '无');

    // 截图：节点轴那张卡
    const shotPath = 'D:/AITEST/huangqi-scifi/.build/fe-beats.png';
    await p.screenshot(shotPath, { selector: '#plot', pad: 10 });
    log('截图:', shotPath);

    // 顺带看看字体生效后有没有溢出/换行问题
    const layout = await p.evaluate(`(function(){
      const bad = [];
      document.querySelectorAll('.table-fixed td, .table-fixed th, .beat__pos .mono, .card__title').forEach(function(el){
        if (el.scrollWidth > el.clientWidth + 2) bad.push((el.className||el.tagName) + ' 溢出 ' + (el.scrollWidth - el.clientWidth) + 'px: ' + (el.textContent||'').trim().slice(0,24));
      });
      const doc = document.documentElement;
      return { overflowX: doc.scrollWidth > doc.clientWidth, bad: bad.slice(0, 10) };
    })()`);
    log('横向溢出:', layout.overflowX, '| 裁切元素:', JSON.stringify(layout.bad));
  } finally {
    await p.close();
  }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
