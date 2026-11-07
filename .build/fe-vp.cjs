'use strict';
/* 核 team-lead 的更正：390 的最低不滚视口高在 mobile 模拟下 742、真机口径 752。
   机制说是「390 有 5px 横向溢出 → mobile 把布局视口撑宽 → 弹层 max-height:calc(100%-35px)
   的 100% 量的是布局视口」。两件事分开验：
   (1) 390 到底有没有横向溢出、溢在谁身上；
   (2) mobile true/false 两口径下 innerWidth / clientWidth / 弹层上限 / 最低不滚高度。
   跑法：node fe-vp.cjs        （宽度自测 330 / 390 / 414 / 479 / 480） */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8128, DEBUG = 9383;
const WIDTHS = (process.env.WIDTHS || '330,390,414,479,480').split(',').map(Number);
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  const srv = http.createServer((req, res) => {
    const f = path.join(ROOT, req.url.split('?')[0] === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]));
    if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    fs.createReadStream(f).pipe(res);
  }).listen(PORT, '127.0.0.1');
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-vp-'));
  const edge = ['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const child = spawn(edge, ['--headless=new', `--remote-debugging-port=${DEBUG}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
  let ws;
  for (let i = 0; i < 60; i++) {
    try { const v = await (await fetch(`http://127.0.0.1:${DEBUG}/json/version`)).json(); if (v.webSocketDebuggerUrl) { ws = new WebSocket(v.webSocketDebuggerUrl); break; } } catch { /* wait */ }
    await sleep(300);
  }
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  let seq = 0; const pend = new Map();
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) { const { resolve, reject } = pend.get(m.id); pend.delete(m.id); m.error ? reject(new Error(m.error.message)) : resolve(m.result); }
  });
  const send = (method, params = {}, sid) => new Promise((resolve, reject) => {
    const id = ++seq; pend.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sid ? { sessionId: sid } : {}) }));
    setTimeout(() => { if (pend.has(id)) { pend.delete(id); reject(new Error('timeout ' + method)); } }, 30000);
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const S = (m, p) => send(m, p, sessionId);
  let ctx = null;
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.method === 'Runtime.executionContextsCleared') ctx = null;
    if (m.method === 'Runtime.executionContextCreated' && m.params.context.auxData && m.params.context.auxData.isDefault) ctx = m.params.context.id;
  });
  await S('Page.enable'); await S('Runtime.enable');
  const ev = async (expr) => {
    const call = (c) => S('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true, ...(c && ctx ? { contextId: ctx } : {}) });
    let r; try { r = await call(true); } catch { ctx = null; r = await call(false); }
    if (r.exceptionDetails) throw new Error('JS: ' + (r.exceptionDetails.exception && r.exceptionDetails.exception.description || r.exceptionDetails.text));
    return r.result ? r.result.value : undefined;
  };
  await S('Emulation.setDeviceMetricsOverride', { width: 390, height: 790, deviceScaleFactor: 1, mobile: true });
  await S('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
  for (let i = 0; i < 60; i++) { if (await ev('document.readyState==="complete" && document.styleSheets.length>0')) break; await sleep(200); }
  await sleep(300);

  const setVp = async (w, h, mobile) => { await S('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile }); await sleep(150); };

  const INFO = `(function(){
    var de = document.documentElement;
    var d = document.querySelector('.invite-modal');
    // 找出右边界越过文档可视宽的元素（只报最外层的几个）
    var cw = de.clientWidth, out = [], all = document.querySelectorAll('body *');
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (el.closest('.invite-modal') || el.classList.contains('to-top') || el.classList.contains('nav-drawer')) continue;
      var r = el.getBoundingClientRect();
      if (r.width === 0) continue;
      if (r.right > cw + 0.5 || r.left < -0.5) {
        out.push({ sel: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).join('.') : ''), left: +r.left.toFixed(2), right: +r.right.toFixed(2), w: +r.width.toFixed(2) });
      }
    }
    var info = {
      innerWidth: window.innerWidth, innerHeight: window.innerHeight,
      docClientW: cw, docScrollW: de.scrollWidth, overflowW: de.scrollWidth - cw,
      visualW: window.visualViewport ? +window.visualViewport.width.toFixed(2) : null,
      bodyScrollW: document.body.scrollWidth
    };
    if (d) {
      var dr = d.getBoundingClientRect();
      info.dialogMaxH = getComputedStyle(d).maxHeight;
      info.dialogH = +dr.height.toFixed(2);
      info.clientH = d.clientHeight;
      var card = document.querySelector('.invite-modal__card');
      info.cardH = card ? +card.getBoundingClientRect().height.toFixed(2) : null;
      info.needScroll = d.scrollHeight - d.clientHeight;
    }
    info.offenders = out.slice(0, 6);
    return info;
  })()`;

  const rows = [];
  for (const mobile of [true, false]) {
    for (const w of WIDTHS) {
      await setVp(w, 790, mobile);
      await ev(`(function(){var d=document.querySelector('.invite-modal');if(!d.open)d.showModal();d.scrollTop=0;})()`);
      await sleep(150);
      const info = await ev(INFO);
      // 二分：最低不滚视口高（1px 分辨率，区间 600..900）
      let lo = 600, hi = 900;
      if (await (async () => { await setVp(w, lo, mobile); return (await ev('document.querySelector(".invite-modal").scrollHeight - document.querySelector(".invite-modal").clientHeight')); })() > 0) { /* 600 也滚，区间外 */ }
      while (lo < hi) {
        const mid = (lo + hi) >> 1;
        await setVp(w, mid, mobile);
        const ns = await ev('(function(){var d=document.querySelector(".invite-modal");return d.scrollHeight - d.clientHeight;})()');
        if (ns <= 0) hi = mid; else lo = mid + 1;
      }
      rows.push({ mobile, width: w, needScrollAt790: info.needScroll, dialogH: info.dialogH, clientH: info.clientH, maxH: info.dialogMaxH, innerWidth: info.innerWidth, docClientW: info.docClientW, docScrollW: info.docScrollW, overflowW: info.overflowW, minNoScrollH: lo, offenders: info.offenders });
      await setVp(w, 790, mobile);
    }
  }
  console.log(JSON.stringify(rows, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
