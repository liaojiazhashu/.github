'use strict';
/* 定位「入口可见」两个定义的差：在 ux 与我分歧的那几个高度上，把几何全打出来。
   我的定义：foot 盒子的 bottom ≤ dialog 的 client 区（不含边框）
   她的数在同样高度上认为可见 → 差 5~6px，要看清差在哪个盒子上。 */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8126, DEBUG = 9381;
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  const srv = http.createServer((req, res) => {
    const f = path.join(ROOT, req.url.split('?')[0] === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]));
    if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    fs.createReadStream(f).pipe(res);
  }).listen(PORT, '127.0.0.1');
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-gap-'));
  const edge = ['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const child = spawn(edge, ['--headless=new', `--remote-debugging-port=${DEBUG}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let ws;
  for (let i = 0; i < 60; i++) {
    try { const v = await (await fetch(`http://127.0.0.1:${DEBUG}/json/version`)).json(); if (v.webSocketDebuggerUrl) { ws = new WebSocket(v.webSocketDebuggerUrl); break; } } catch { /* wait */ }
    await sleep(300);
  }
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  let seq = 0; const pend = new Map(); const evs = new Map();
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) { const { resolve, reject } = pend.get(m.id); pend.delete(m.id); m.error ? reject(new Error(m.error.message)) : resolve(m.result); }
    else if (m.method && evs.has(m.method)) evs.get(m.method).splice(0).forEach((f) => f(m.params));
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
  evs.set('Runtime.executionContextsCleared', []); evs.set('Runtime.executionContextCreated', []);
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.method === 'Runtime.executionContextsCleared') ctx = null;
    if (m.method === 'Runtime.executionContextCreated' && m.params.context.auxData && m.params.context.auxData.isDefault) ctx = m.params.context.id;
  });
  await S('Page.enable'); await S('Runtime.enable');
  const ev = async (expr) => {
    const call = (c) => S('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true, ...(c && ctx ? { contextId: ctx } : {}) });
    let r; try { r = await call(true); } catch { ctx = null; r = await call(false); }
    return r.result ? r.result.value : undefined;
  };
  await S('Emulation.setDeviceMetricsOverride', { width: 480, height: 790, deviceScaleFactor: 1, mobile: true });
  await S('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
  for (let i = 0; i < 60; i++) { if (await ev('document.readyState==="complete" && document.styleSheets.length>0')) break; await sleep(200); }
  await sleep(300);
  await ev('document.getElementById("invite-modal").showModal()');
  await sleep(300);

  const M = `(function(){
    var d = document.querySelector('.invite-modal');
    var foot = document.querySelector('.invite-modal__foot');
    var card = document.querySelector('.invite-modal__card');
    var dr = d.getBoundingClientRect(), fr = foot.getBoundingClientRect(), cr = card.getBoundingClientRect();
    // 第三段：foot 里那个 <a> 的行内盒（若 ux 量的是它而不是 <p>，底边会比我高半个行距）
    var a = foot.querySelector('a');
    var ar = a ? a.getBoundingClientRect() : null;
    var fcs = getComputedStyle(foot);
    return {
      dialogTop: +dr.top.toFixed(2), dialogBottom: +dr.bottom.toFixed(2), dialogH: +dr.height.toFixed(2),
      clientH: d.clientHeight, scrollH: d.scrollHeight, scrollTop: d.scrollTop,
      needScroll: d.scrollHeight - d.clientHeight,
      cardBottom: +cr.bottom.toFixed(2), cardH: +cr.height.toFixed(2),
      footTop: +fr.top.toFixed(2), footBottom: +fr.bottom.toFixed(2), footH: +fr.height.toFixed(2),
      footLineHeight: fcs.lineHeight, footFont: fcs.fontSize,
      aTop: ar ? +ar.top.toFixed(2) : null, aBottom: ar ? +ar.bottom.toFixed(2) : null, aH: ar ? +ar.height.toFixed(2) : null,
      gap_aBorderBox: ar ? +(dr.bottom - ar.bottom).toFixed(2) : null,
      // 三种「可见」定义
      gap_clientBox: +(dr.top + d.clientHeight - fr.bottom).toFixed(2),
      gap_borderBox: +(dr.bottom - fr.bottom).toFixed(2),
      gap_card: +(cr.bottom - fr.bottom).toFixed(2)
    };
  })()`;
  const rows = [];
  for (const h of [730, 735, 740, 745, 790]) {
    await S('Emulation.setDeviceMetricsOverride', { width: 480, height: h, deviceScaleFactor: 1, mobile: true });
    await sleep(160);
    const r = await ev(M); r.height = h; rows.push(r);
  }
  console.log(JSON.stringify(rows, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
