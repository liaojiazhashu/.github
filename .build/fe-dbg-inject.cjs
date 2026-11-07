'use strict';
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');
const ready = async (p) => { for (let i=0;i<40;i++){ if (await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()')) return true; await sleep(150);} return false; };
(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9407, width: 1440, height: 900 });
  try {
    await p.goto('/admin/analyses/01M6DXRD5H45GWVKR4G6DGJ6YD', '.secnav'); await ready(p); await sleep(300);
    const before = await p.evaluate(`(function(){var sn=document.querySelector('.secnav');return {top:getComputedStyle(sn).top, tok:getComputedStyle(document.documentElement).getPropertyValue('--topbar-h')}})()`);
    const inj = await p.evaluate(`(function(){
      var s=document.createElement('style'); s.id='preview';
      s.textContent = ':root{--topbar-h:60px} .secnav,.bulkbar{top:var(--topbar-h) !important}';
      document.head.appendChild(s);
      var sn=document.querySelector('.secnav');
      return { injected: !!document.getElementById('preview'), top: getComputedStyle(sn).top,
               tok: getComputedStyle(document.documentElement).getPropertyValue('--topbar-h'),
               sheets: document.styleSheets.length };
    })()`);
    console.log('before ' + JSON.stringify(before));
    console.log('after  ' + JSON.stringify(inj));
    await p.evaluate('(function(){window.scrollTo(0,2000);return 1})()'); await sleep(400);
    const stuck = await p.evaluate(`(function(){var sn=document.querySelector('.secnav');return {rectTop: Math.round(sn.getBoundingClientRect().top*10)/10, cssTop: getComputedStyle(sn).top}})()`);
    console.log('stuck  ' + JSON.stringify(stuck));
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
