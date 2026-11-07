'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9415, width: 1440, height: 900 });
  try {
    for (const path of ['/admin/works', '/admin/source', '/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '/admin/audit', '/library/works']) {
      await p.goto(path, 'body'); await ready(p); await sleep(200);
      const r = await p.evaluate(`(function () {
        return fetch(location.href, { method: 'GET', credentials: 'same-origin' })
          .then(function (res) {
            var n = document.querySelectorAll('[style]'), dropped = 0;
            for (var i = 0; i < n.length; i++) if (n[i].getAttribute('style') && !n[i].style.cssText) dropped++;
            return { path: location.pathname, status: res.status,
                     csp: res.headers.get('content-security-policy'),
                     styleAttrs: n.length, dropped: dropped };
          });
      })()`);
      console.log(JSON.stringify(r));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
