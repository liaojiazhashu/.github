'use strict';
/* 三件事一次验清：
   1) 390 下那个 5px 到底能不能被用户拖出来（html/body 的 overflow-x + 真滚一次看 scrollX）
   2) 溢出的子元素右边界 vs 父容器右边界（team-lead 说 container 是 390.00，我说子元素到 395.08）
   3) 隐藏 .site-header__actions 后 scrollWidth 是否回落 */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8132, DEBUG = 9387;
const W = +(process.env.W || 390), MOBILE = process.env.MOBILE === '1';
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  const srv = http.createServer((req, res) => {
    const f = path.join(ROOT, req.url.split('?')[0] === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]));
    if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    fs.createReadStream(f).pipe(res);
  }).listen(PORT, '127.0.0.1');
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-scr-'));
  const edge = ['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const child = spawn(edge, ['--headless=new', `--remote-debugging-port=${DEBUG}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let ws;
  for (let i = 0; i < 60; i++) {
    try { const v = await (await fetch(`http://127.0.0.1:${DEBUG}/json/version`)).json(); if (v.webSocketDebuggerUrl) { ws = new WebSocket(v.webSocketDebuggerUrl); break; } } catch { /* wait */ }
    await sleep(300);
  }
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  let seq = 0; const pend = new Map();
  ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { const { resolve, reject } = pend.get(m.id); pend.delete(m.id); m.error ? reject(new Error(m.error.message)) : resolve(m.result); } });
  const send = (method, params = {}, sid) => new Promise((resolve, reject) => { const id = ++seq; pend.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params, ...(sid ? { sessionId: sid } : {}) })); setTimeout(() => { if (pend.has(id)) { pend.delete(id); reject(new Error('timeout ' + method)); } }, 30000); });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const S = (m, p) => send(m, p, sessionId);
  let ctx = null;
  ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.method === 'Runtime.executionContextsCleared') ctx = null; if (m.method === 'Runtime.executionContextCreated' && m.params.context.auxData && m.params.context.auxData.isDefault) ctx = m.params.context.id; });
  await S('Page.enable'); await S('Runtime.enable');
  const ev = async (expr) => {
    const call = (c) => S('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true, ...(c && ctx ? { contextId: ctx } : {}) });
    let r; try { r = await call(true); } catch { ctx = null; r = await call(false); }
    if (r.exceptionDetails) throw new Error('JS: ' + (r.exceptionDetails.exception && r.exceptionDetails.exception.description || r.exceptionDetails.text));
    return r.result ? r.result.value : undefined;
  };
  await S('Emulation.setDeviceMetricsOverride', { width: W, height: 790, deviceScaleFactor: 1, mobile: MOBILE });
  await S('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
  for (let i = 0; i < 60; i++) { if (await ev('document.readyState==="complete" && document.styleSheets.length>0')) break; await sleep(200); }
  await sleep(400);

  const r = await ev(`(function(){
    var de = document.documentElement, body = document.body;
    var inner = document.querySelector('.site-header__inner');
    var actions = document.querySelector('.site-header__actions');
    var ir = inner.getBoundingClientRect(), ar = actions.getBoundingClientRect();
    var before = { scrollW: de.scrollWidth, clientW: de.clientWidth };
    // 真滚一次，看能不能被拖出来
    window.scrollTo(400, 0);
    var moved = window.scrollX;
    window.scrollTo(0, 0);
    // 隐藏 actions 看是否回落
    var prev = actions.style.display; actions.style.display = 'none';
    var afterHide = { scrollW: de.scrollWidth, clientW: de.clientWidth };
    actions.style.display = prev;
    return {
      htmlOverflowX: getComputedStyle(de).overflowX,
      bodyOverflowX: getComputedStyle(body).overflowX,
      innerRight: +ir.right.toFixed(2), actionsRight: +ar.right.toFixed(2),
      actionsOver: +(ar.right - ir.right).toFixed(2),
      before: before, afterHideActions: afterHide,
      scrollXMoved: moved,
      canPanHorizontally: moved > 0
    };
  })()`);
  console.log(JSON.stringify({ width: W, mobile: MOBILE, ...r }, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
