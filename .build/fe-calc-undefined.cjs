'use strict';
// 验证：calc 里引用未定义自定义属性 → 计算值 0px，且无报错
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9426, width: 1440, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var a = document.createElement('div'); document.body.appendChild(a);
      a.style.scrollMarginTop = 'calc(var(--nope-nope) + 12px)';
      var undef = { cssText: a.style.scrollMarginTop, computed: getComputedStyle(a).scrollMarginTop };
      var b = document.createElement('div'); document.body.appendChild(b);
      b.style.scrollMarginTop = 'calc(var(--topbar-h) + var(--secnav-h) + 12px)';
      var defined = { cssText: b.style.scrollMarginTop, computed: getComputedStyle(b).scrollMarginTop };
      var c = document.createElement('div'); document.body.appendChild(c);
      c.style.scrollMarginTop = 'calc(var(--topbar-h) + var(--secnav-h) + 12px)';
      a.remove(); b.remove(); c.remove();
      return { undefinedVar: undef, definedVar: defined, hasViolationApi: typeof document.securityPolicyViolationEvent !== 'undefined' };
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
