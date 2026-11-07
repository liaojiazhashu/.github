'use strict';
/* 390 档两口径对账：把 innerH / 解析后的 max-height / 卡片宽高 / needScroll 一次打全。
   争议点：team-lead 说 desktop 弹层高 705、卡片 714.55 两边相同；我先前量到两边都 716.55。
   卡片高若真是 714.55，弹层高不可能 705（弹层 ≥ 卡片 + 2px 边框）——先把这组数钉住。 */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8131, DEBUG = 9386;
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CASES = JSON.parse(process.env.CASES || '[[390,790],[390,742],[390,752],[414,752],[330,790]]');
(async () => {
  const srv = http.createServer((req, res) => {
    const f = path.join(ROOT, req.url.split('?')[0] === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]));
    if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    fs.createReadStream(f).pipe(res);
  }).listen(PORT, '127.0.0.1');
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-mode-'));
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
  await S('Emulation.setDeviceMetricsOverride', { width: 390, height: 790, deviceScaleFactor: 1, mobile: true });
  await S('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
  for (let i = 0; i < 60; i++) { if (await ev('document.readyState==="complete" && document.styleSheets.length>0')) break; await sleep(200); }
  await sleep(300);
  await ev('document.getElementById("invite-modal").showModal()');

  const M = `(function(){
    var de = document.documentElement, d = document.getElementById('invite-modal');
    var card = document.querySelector('.invite-modal__card');
    var cs = getComputedStyle(d), dr = d.getBoundingClientRect(), cr = card.getBoundingClientRect();
    return {
      innerW: window.innerWidth, innerH: window.innerHeight,
      visualW: window.visualViewport ? +window.visualViewport.width.toFixed(2) : null,
      visualH: window.visualViewport ? +window.visualViewport.height.toFixed(2) : null,
      docClientH: de.clientHeight, docScrollW: de.scrollWidth,
      maxH_declared: cs.maxHeight, maxH_resolved: cs.maxHeight,
      dialogFontSize: cs.fontSize, bodyFontSize: getComputedStyle(document.body).fontSize,
      // 100vw 到底解成哪个视口：直接影响 .invite-modal 的 min(500px, calc(100vw - 40px))
      vw100: (function () {
        var p = document.createElement('div');
        p.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:1px;visibility:hidden;pointer-events:none';
        document.body.appendChild(p);
        var w = +p.getBoundingClientRect().width.toFixed(2);
        p.remove();
        return w;
      })(),
      dialogW: +dr.width.toFixed(2),
      dialogTop: +dr.top.toFixed(2), dialogH: +dr.height.toFixed(2),
      clientH: d.clientHeight, scrollH: d.scrollHeight, needScroll: d.scrollHeight - d.clientHeight,
      cardW: +cr.width.toFixed(2), cardH: +cr.height.toFixed(2),
      cardPadB: getComputedStyle(card).paddingBottom
    };
  })()`;
  const out = [];
  for (const [w, h] of CASES) {
    for (const mobile of [true, false]) {
      await S('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile });
      await sleep(160);
      await ev('(function(){var d=document.getElementById("invite-modal");d.scrollTop=0;})()');
      const m = await ev(M);
      out.push({ case: w + 'x' + h, mobile, ...m });
    }
  }
  console.log(JSON.stringify(out, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
