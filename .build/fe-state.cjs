'use strict';
/* 一个必须先钉住的状态问题：
   同一宽度、同一 mobile 标志，我在两次运行里量到 innerW 330 与 379 两套值。
   差别疑似「视口是在导航前设定还是导航后切换」。做法：每个 case 都重新导航，
   并分别量「弹层关着」与「弹层开着」两态。
   node fe-state.cjs */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8133, DEBUG = 9388;
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  const srv = http.createServer((req, res) => {
    const f = path.join(ROOT, req.url.split('?')[0] === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]));
    if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    fs.createReadStream(f).pipe(res);
  }).listen(PORT, '127.0.0.1');
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-state-'));
  const edge = ['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const child = spawn(edge, ['--headless=new', `--remote-debugging-port=${DEBUG}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
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
  const M = `(function(){
    var de = document.documentElement, d = document.getElementById('invite-modal');
    var card = document.querySelector('.invite-modal__card');
    var cr = card.getBoundingClientRect();
    var o = {
      innerW: window.innerWidth, innerH: window.innerHeight,
      docClientW: de.clientWidth, docClientH: de.clientHeight,
      docScrollW: de.scrollWidth, overflowW: de.scrollWidth - de.clientWidth,
      bodyOverflowX: getComputedStyle(document.body).overflowX,
      bodyOverflowY: getComputedStyle(document.body).overflowY,
      dialogOpen: !!(d && d.open),
      cardW: +cr.width.toFixed(2), cardH: +cr.height.toFixed(2)
    };
    if (d && d.open) {
      o.dialogH = +d.getBoundingClientRect().height.toFixed(2);
      o.clientH = d.clientHeight; o.scrollH = d.scrollHeight;
      o.needScroll = d.scrollHeight - d.clientHeight;
    }
    var inner = document.querySelector('.site-header__inner');
    var actions = document.querySelector('.site-header__actions');
    var toggle = document.querySelector('.nav-toggle');
    if (inner && actions) {
      var ir = inner.getBoundingClientRect(), ar = actions.getBoundingClientRect(), tr = toggle ? toggle.getBoundingClientRect() : null;
      o.innerRight = +ir.right.toFixed(2); o.actionsRight = +ar.right.toFixed(2);
      o.actionsW = +ar.width.toFixed(2); o.brandW = +document.querySelector('.brand').getBoundingClientRect().width.toFixed(2);
      o.toggleRight = tr ? +tr.right.toFixed(2) : null; o.toggleLeft = tr ? +tr.left.toFixed(2) : null;
    }
    return o;
  })()`;
  const out = [];
  for (const [w, h] of [[330, 790], [390, 790], [390, 742], [480, 740]]) {
    for (const mobile of [true, false]) {
      await S('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile });
      await S('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
      for (let i = 0; i < 60; i++) { if (await ev('document.readyState==="complete" && document.styleSheets.length>0')) break; await sleep(200); }
      await sleep(300);
      const closed = await ev(M);
      await ev('document.getElementById("invite-modal").showModal()');
      await sleep(250);
      const open = await ev(M);
      await ev('(function(){var d=document.getElementById("invite-modal");if(d.open)d.close();})()');
      out.push({ case: w + 'x' + h, mobile, closed, open });
    }
  }
  console.log(JSON.stringify(out, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
