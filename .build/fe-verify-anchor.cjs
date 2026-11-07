#!/usr/bin/env node
/* 只读验证：词条页锚点被吸顶头遮挡的真实数值，并在线试算 scroll-margin 修正（不改文件） */
'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

const WIDTHS = [
  { w: 1440, h: 900 },
  { w: 768, h: 1024 },
  { w: 390, h: 844 }
];

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9356, width: 1440, height: 900 });
  try {
    // 找一个真实词条页
    await p.goto('/library/terms', '.card, main, body');
    const term = await p.evaluate(`(location.pathname.indexOf('/library/terms/') === 0 ? location.pathname :
      (Array.from(document.querySelectorAll('a[href^="/library/terms/"]')).map(a=>a.getAttribute('href'))[0] || null))`);
    console.log('词条页:', term);
    if (!term) throw new Error('找不到词条页链接');

    for (const { w, h } of WIDTHS) {
      await p.session.send('Emulation.setDeviceMetricsOverride', {
        width: w, height: h, deviceScaleFactor: 1, mobile: w < 500
      });
      await p.goto(term, '.split__main');

      const base = await p.evaluate(`(function () {
        var top = document.querySelector('.top');
        var sec = document.querySelector('.split__main .sec[id]');
        var cs = sec ? getComputedStyle(sec) : null;
        return {
          topH: top ? Math.round(top.getBoundingClientRect().height * 10) / 10 : null,
          topPos: top ? getComputedStyle(top).position : null,
          secIds: Array.prototype.map.call(document.querySelectorAll('.split__main .sec[id]'), function (s) { return '#' + s.id; }),
          secPadTop: cs ? cs.paddingTop : null,
          scrollMargin: cs ? cs.scrollMarginTop : null,
          htmlScrollPad: getComputedStyle(document.documentElement).scrollPaddingTop
        };
      })()`);

      // 点一次锚点（真实交互路径）
      const before = await p.evaluate(`(function () {
        var a = document.querySelector('.split__aside .toc a[href="#s-works"]');
        if (!a) return null;
        a.click();
        var s = document.getElementById('s-works');
        var r = s.getBoundingClientRect();
        var t = s.querySelector('.sec__head') || s;
        return { secTop: Math.round(r.top), titleTop: Math.round(t.getBoundingClientRect().top), href: a.getAttribute('href') };
      })()`);
      await sleep(400);
      const afterNoFix = await p.evaluate(`(function () {
        var s = document.getElementById('s-works');
        var t = s.querySelector('.sec__head') || s;
        var top = document.querySelector('.top');
        var topH = top ? top.getBoundingClientRect().height : 0;
        var tt = t.getBoundingClientRect().top;
        return { secTop: Math.round(s.getBoundingClientRect().top), titleTop: Math.round(tt),
                 topH: Math.round(topH), covered: Math.round(Math.max(0, topH - tt)), scrollY: Math.round(window.scrollY) };
      })()`);

      // 在线试算：给 .sec[id] 加 scroll-margin-top = topH + 12（只改 CSSOM，不落盘）
      const fix = await p.evaluate(`(function () {
        var top = document.querySelector('.top');
        var topH = Math.round((top ? top.getBoundingClientRect().height : 0) + 12);
        Array.prototype.forEach.call(document.querySelectorAll('.split__main .sec[id]'), function (s) {
          s.style.scrollMarginTop = topH + 'px';
        });
        return topH;
      })()`);
      await p.evaluate(`(function(){ window.scrollTo(0,0); document.querySelector('.split__aside .toc a[href="#s-works"]').click(); })()`);
      await sleep(400);
      const afterFix = await p.evaluate(`(function () {
        var s = document.getElementById('s-works');
        var t = s.querySelector('.sec__head') || s;
        var top = document.querySelector('.top');
        var topH = top ? top.getBoundingClientRect().height : 0;
        var tt = t.getBoundingClientRect().top;
        return { secTop: Math.round(s.getBoundingClientRect().top), titleTop: Math.round(tt),
                 covered: Math.round(Math.max(0, topH - tt)),
                 scrollPaddingVar: getComputedStyle(document.documentElement).scrollPaddingTop };
      })()`);

      console.log('\n=== ' + w + '×' + h + ' ===');
      console.log('吸顶头:', base.topH + 'px', base.topPos);
      console.log('锚点:', JSON.stringify(base.secIds), '| sec padding-top:', base.secPadTop, '| scroll-margin-top:', base.scrollMargin, '| html scroll-padding-top:', base.htmlScrollPad);
      console.log('未修：', JSON.stringify(afterNoFix));
      console.log('试算 scroll-margin-top=' + fix + 'px →', JSON.stringify(afterFix));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
