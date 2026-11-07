'use strict';
/* 390 下 document.scrollWidth 395，但逐个元素查右边界时全部落在 overflow-x:hidden 的祖先里
   —— 说明撑宽的不是这些（或被裁的盒子本身不贡献）。改用「藏子树再看 scrollWidth」定位，
   并检查伪元素。 */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8130, DEBUG = 9385;
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
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-ovf2-'));
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
  await S('Emulation.setDeviceMetricsOverride', { width: W, height: 790, deviceScaleFactor: 1, mobile: MOBILE });
  await S('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
  for (let i = 0; i < 60; i++) { if (await ev('document.readyState==="complete" && document.styleSheets.length>0')) break; await sleep(200); }
  await sleep(400);

  // 一、伪元素排查：把 body 下所有元素的 ::before/::after 关掉，看 scrollWidth 是否回落
  const pseudo = await ev(`(function(){
    var de = document.documentElement, before = de.scrollWidth - de.clientWidth;
    var st = document.createElement('style'); st.id='__k';
    st.textContent = 'body *::before, body *::after { content: none !important; }';
    document.head.appendChild(st);
    var after = de.scrollWidth - de.clientWidth;
    st.remove();
    return { before: before, afterDisablingPseudo: after };
  })()`);

  // 二、逐层下钻定位：优先藏第一个能让 scrollWidth 回落的兄弟子树，进去继续，最多 8 层
  const trace = await ev(`(function(){
    var de = document.documentElement, trace = [];
    function name(el){ return el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\\s+/).join('.') : '') + (el.id ? '#' + el.id : ''); }
    var node = document.body;
    for (var depth = 0; depth < 8 && node; depth++) {
      var kids = node.children, found = null;
      for (var i = 0; i < kids.length; i++) {
        var el = kids[i], base = de.scrollWidth - de.clientWidth, prev = el.style.display;
        el.style.display = 'none';
        var now = de.scrollWidth - de.clientWidth;
        el.style.display = prev;
        if (base - now > 0) { found = { el: el, delta: base - now, base: base, afterHide: now }; break; }
      }
      if (!found) { trace.push({ level: depth, host: name(node), note: '子级里没有单独能消掉溢出的（横向 flex/grid 分配造成）' }); break; }
      var cs = getComputedStyle(found.el), r = found.el.getBoundingClientRect();
      trace.push({
        level: depth, under: name(node), el: name(found.el), delta: found.delta,
        rect: { left: +r.left.toFixed(2), right: +r.right.toFixed(2), w: +r.width.toFixed(2) },
        overflowX: cs.overflowX, display: cs.display, flex: cs.flex, width: cs.width, minWidth: cs.minWidth,
        marginRight: cs.marginRight, paddingRight: cs.paddingRight, whiteSpace: cs.whiteSpace
      });
      node = found.el;
    }
    return trace;
  })()`);
  console.log(JSON.stringify({ width: W, mobile: MOBILE, pseudo, trace }, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
