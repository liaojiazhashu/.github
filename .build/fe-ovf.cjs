'use strict';
/* 390 宽下 document.scrollWidth 395 —— 那 5px 是谁撑出来的？
   逐个元素看右边界，并打印祖先链上的 overflow，区分「真溢出」与「被上层裁掉的装饰」。 */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8129, DEBUG = 9384;
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
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-ovf-'));
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
  const r = await ev(`(function(){
    var de = document.documentElement, cw = de.clientWidth;
    function name(el){ return el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\\s+/).join('.') : ''); }
    var inner = document.querySelector('.site-header__inner');
    var ics = getComputedStyle(inner);
    var kids = [];
    for (var i = 0; i < inner.children.length; i++) {
      var el = inner.children[i], rect = el.getBoundingClientRect(), cs = getComputedStyle(el);
      kids.push({ el: name(el), left: +rect.left.toFixed(2), right: +rect.right.toFixed(2), w: +rect.width.toFixed(2), display: cs.display, flex: cs.flex, whiteSpace: cs.whiteSpace, minWidth: cs.minWidth });
    }
    return {
      clientW: cw, scrollW: de.scrollWidth, overflow: de.scrollWidth - cw,
      inner: { w: +inner.getBoundingClientRect().width.toFixed(2), padding: ics.paddingLeft + ' / ' + ics.paddingRight, gap: ics.gap, justify: ics.justifyContent, display: ics.display },
      lastKidRight: kids.length ? kids[kids.length - 1].right : null,
      kids: kids,
      actionsKids: (function () {
        var box = document.querySelector('.site-header__actions'), out = [];
        if (!box) return out;
        var bc = getComputedStyle(box), br = box.getBoundingClientRect();
        out.push({ el: '@actions', w: +br.width.toFixed(2), left: +br.left.toFixed(2), right: +br.right.toFixed(2), gap: bc.gap, padding: bc.paddingLeft + '/' + bc.paddingRight, display: bc.display, flex: bc.flex });
        for (var j = 0; j < box.children.length; j++) {
          var e = box.children[j], rr = e.getBoundingClientRect(), cc = getComputedStyle(e);
          out.push({ el: name(e), w: +rr.width.toFixed(2), left: +rr.left.toFixed(2), right: +rr.right.toFixed(2), display: cc.display, flex: cc.flex, margin: cc.marginLeft + '/' + cc.marginRight, padding: cc.paddingLeft + '/' + cc.paddingRight, fontSize: cc.fontSize, text: (e.textContent || '').trim().slice(0, 12) });
        }
        return out;
      })()
    };
  })()`);
  console.log(JSON.stringify(r, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
