#!/usr/bin/env node
/* A/B/C 三项落地的验收：阶段色、左轨、--mono、零回流 */
'use strict';
const { openAdmin } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9352, width: 1440, height: 1000 });
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

    const out = await p.evaluate(`(function () {
      var q = function (s) { return document.querySelector(s); };
      var g = function (el, k) { return el ? getComputedStyle(el)[k] : null; };
      var counts = {};
      ['start','turn','crisis','end'].forEach(function (ph) { counts[ph] = document.querySelectorAll('.beat--' + ph).length; });
      var rail = {};
      ['start','turn','crisis','end'].forEach(function (ph) {
        var el = q('.beat--' + ph);
        rail[ph] = el ? getComputedStyle(el, '::before').backgroundColor : null;
      });
      // 左轨是否真的渲染出来（宽 2px、高与行高等比）
      var b0 = q('.beat--crisis');
      var railBox = b0 ? getComputedStyle(b0, '::before') : null;
      // 零回流核对：seq 列起点应等于卡片内边距 22px
      var card = q('#plot');
      var cardPad = card ? parseFloat(getComputedStyle(card).paddingLeft) : null;
      var seq = q('.beat__seq');
      var seqLeft = (seq && card) ? Math.round((seq.getBoundingClientRect().left - card.getBoundingClientRect().left) * 10) / 10 : null;
      // 最细的一根计量条（验证 min-width:3px 生效）
      var fills = Array.prototype.slice.call(document.querySelectorAll('.beat__meter-fill'));
      var thinnest = fills.map(function (f) { return { w: Math.round(f.getBoundingClientRect().width * 10) / 10, pct: f.getAttribute('data-pct') }; })
        .sort(function (a, b) { return a.w - b.w; })[0];
      return {
        total: document.querySelectorAll('.beat').length,
        counts: counts,
        phaseColors: {
          start: g(q('.beat--start .beat__phase'), 'color'),
          turn: g(q('.beat--turn .beat__phase'), 'color'),
          crisis: g(q('.beat--crisis .beat__phase'), 'color'),
          end: g(q('.beat--end .beat__phase'), 'color')
        },
        railColors: rail,
        railSize: railBox ? { width: railBox.width, left: railBox.left, position: railBox.position, radius: railBox.borderRadius } : null,
        monoToken: getComputedStyle(document.documentElement).getPropertyValue('--mono').trim(),
        secnavFF: g(q('.secnav__n'), 'fontFamily'),
        monoFF: g(q('.mono'), 'fontFamily'),
        seqFF: g(q('.beat__seq'), 'fontFamily'),
        cardPad: cardPad,
        seqLeft: seqLeft,
        thinnestFill: thinnest,
        docOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        pageH: document.documentElement.scrollHeight
      };
    })()`);

    console.log(JSON.stringify(out, null, 1));

    const expect = { start: 'rgb(167, 139, 232)', turn: 'rgb(79, 193, 224)', crisis: 'rgb(224, 168, 78)', end: 'rgb(95, 211, 160)' };
    const fail = [];
    Object.keys(expect).forEach(function (k) {
      if (out.phaseColors[k] !== expect[k]) fail.push('阶段名 ' + k + ' = ' + out.phaseColors[k]);
      if (out.railColors[k] !== expect[k]) fail.push('左轨 ' + k + ' = ' + out.railColors[k]);
    });
    if (out.seqLeft !== out.cardPad + 1) fail.push("回流：seq 左偏移 " + out.seqLeft + " ≠ 卡片边框1+内边距 " + out.cardPad);
    if (!/JetBrains/.test(out.secnavFF || '')) fail.push('.secnav__n 未吃到 mono: ' + out.secnavFF);
    if (out.thinnestFill.w < 3) fail.push('min-width 未生效，最细条 ' + out.thinnestFill.w + 'px');
    if (out.docOverflow > 0) fail.push('横向溢出 ' + out.docOverflow);
    console.log('\n失败项:', fail.length ? fail : '无');

    await p.screenshot('D:/AITEST/huangqi-scifi/.build/fe-beats-rail.png', { selector: '#plot', pad: 10 });
    console.log('截图: .build/fe-beats-rail.png');
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
