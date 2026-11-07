'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9410, width: 1440, height: 900 });
  try {
    await p.goto('/admin/works', '.bulkbar'); await ready(p); await sleep(300);
    const r = await p.evaluate(`(function () {
      var probe = document.createElement('div');
      probe.id = 'csp-probe';
      probe.textContent = 'x';
      document.body.appendChild(probe);
      var before = { color: getComputedStyle(probe).color, sheets: document.styleSheets.length };
      var st = document.createElement('style');
      st.textContent = '#csp-probe{color:rgb(1,2,3)}';
      document.head.appendChild(st);
      var afterAppend = { sheets: document.styleSheets.length, hasSheet: !!st.sheet, rules: st.sheet ? st.sheet.cssRules.length : -1 };
      var afterColor = getComputedStyle(probe).color;
      // 对照：CSSOM 设置
      probe.style.color = 'rgb(4,5,6)';
      var cssomColor = getComputedStyle(probe).color;
      probe.remove(); st.remove();
      return { before: before, afterAppend: afterAppend, afterColor: afterColor, cssomColor: cssomColor };
    })()`);
    console.log(JSON.stringify(r, null, 1));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
