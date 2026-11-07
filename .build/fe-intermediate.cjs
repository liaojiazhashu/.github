'use strict';
/* 验证注释里 "162.3 → 112.3" 是中间态还是旧值：
   在 390 下分别量 (a) 现状 (b) 只藏副标题、间距回退到 18/160 (c) 全回退 */
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<30;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9393, width: 390, height: 900 });
  try {
    await p.session.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
    await p.goto('/library/terms/2450', '.top'); await ready(p);
    const r = await p.evaluate(`(function () {
      function h() { return Math.round(document.querySelector('.top').getBoundingClientRect().height * 10) / 10; }
      function rows() {
        var kids = document.querySelector('.top__in').children, out = [];
        for (var i = 0; i < kids.length; i++) {
          var b = kids[i].getBoundingClientRect();
          out.push((kids[i].className || '').toString().split(' ')[0] + '@' + Math.round(b.top) + '/' + Math.round(b.width));
        }
        return out.join(' ');
      }
      var sub = document.querySelector('.brand__sub');
      var inner = document.querySelector('.top__in');
      var find = document.querySelector('.find');

      var a = { h: h(), rows: rows(), sub: getComputedStyle(sub).display, gap: getComputedStyle(inner).columnGap, findBasis: getComputedStyle(find).flexBasis };

      // 只藏副标题：把间距/基准回退成宽屏值（模拟"只藏 .brand__sub 一步"）
      inner.style.gap = '12px 18px';
      find.style.flex = '1 1 160px';
      void document.body.offsetHeight;
      var b = { h: h(), rows: rows(), sub: getComputedStyle(sub).display, gap: getComputedStyle(inner).columnGap, findBasis: getComputedStyle(find).flexBasis };

      // 连副标题也不藏（完全回退到 >442 的状态）
      sub.style.display = 'block';
      void document.body.offsetHeight;
      var c = { h: h(), rows: rows(), sub: getComputedStyle(sub).display };

      // 还原
      inner.style.gap = '';
      find.style.flex = '';
      sub.style.display = '';
      void document.body.offsetHeight;
      var d = { h: h(), rows: rows() };
      return { now: a, onlyHideSub: b, noCollapse: c, restored: d };
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
