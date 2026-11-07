'use strict';
/* 330 档（≤359 极小屏）两种模拟模式的对照：
   mobile:true（布局视口被横向溢出撑大） vs mobile:false（布局视口高 = 设备高，真机口径）。
   逐 1px 扫 760–820，每档等 320ms，翻转档再等 400ms 复读一次。 */
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8133, DEBUG = 9350;
const ORIGIN = `http://127.0.0.1:${PORT}`;
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const W = 330, H0 = 760, H1 = 820;

function startServer() {
  return new Promise((res) => {
    const srv = http.createServer((req, r) => {
      const rel = decodeURIComponent(req.url.split('?')[0]);
      const f = path.join(ROOT, rel === '/' ? 'index.html' : rel);
      if (!f.startsWith(ROOT) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { r.writeHead(404); r.end('404'); return; }
      r.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
      fs.createReadStream(f).pipe(r);
    });
    srv.listen(PORT, '127.0.0.1', () => res(srv));
  });
}
function edgePath() {
  for (const p of ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe']) if (fs.existsSync(p)) return p;
  throw new Error('找不到 Edge');
}
async function waitDevtools(port) {
  for (let i = 0; i < 60; i++) {
    try { const r = await fetch(`http://127.0.0.1:${port}/json/version`); const v = await r.json(); if (v.webSocketDebuggerUrl) return v.webSocketDebuggerUrl; } catch { /* 未就绪 */ }
    await sleep(300);
  }
  throw new Error('调试端口未就绪');
}
function makeCdp(ws) {
  let seq = 0; const pending = new Map(), events = new Map();
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { const { resolve, reject } = pending.get(m.id); pending.delete(m.id); m.error ? reject(new Error(m.error.message)) : resolve(m.result); }
    else if (m.method && events.has(m.method)) events.get(m.method).splice(0).forEach((fn) => fn(m.params));
  });
  return {
    send(method, params = {}, sessionId) {
      const id = ++seq;
      return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        const p = { id, method, params }; if (sessionId) p.sessionId = sessionId;
        ws.send(JSON.stringify(p));
        setTimeout(() => { if (pending.has(id)) { pending.delete(id); reject(new Error('CDP 超时 ' + method)); } }, 30000);
      });
    },
    on(m, f) { const l = events.get(m) || []; l.push(f); events.set(m, l); }
  };
}

(async () => {
  const srv = await startServer();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-330-'));
  const child = spawn(edgePath(), ['--headless=new', `--remote-debugging-port=${DEBUG}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', '--window-size=1440,1000', 'about:blank'], { stdio: 'ignore' });
  process.on('exit', () => { try { child.kill(); } catch { /* ignore */ } try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* ignore */ } try { srv.close(); } catch { /* ignore */ } });

  const ws = new WebSocket(await waitDevtools(DEBUG));
  await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', () => rej(new Error('WS 失败')), { once: true }); });
  const browser = makeCdp(ws);
  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await browser.send('Target.attachToTarget', { targetId, flatten: true });
  const S = (m, p) => browser.send(m, p, sessionId);

  let ctx = null;
  browser.on('Runtime.executionContextsCleared', () => { ctx = null; });
  browser.on('Runtime.executionContextCreated', (p) => { if (p && p.context && p.context.auxData && p.context.auxData.isDefault) ctx = p.context.id; });
  await S('Page.enable'); await S('Runtime.enable');

  const ev = async (expr) => {
    const call = (withCtx) => S('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true, ...(withCtx && ctx ? { contextId: ctx } : {}) });
    let r;
    try { r = await call(true); } catch { ctx = null; r = await call(false); }
    if (r.exceptionDetails) throw new Error('页面异常: ' + r.exceptionDetails.text);
    return r.result ? r.result.value : undefined;
  };

  const probe = `(function(){
    var d=document.getElementById('invite-modal'); d.scrollTop=0;
    var c=d.querySelector('.invite-modal__card'); var q=d.querySelector('.invite-modal__qr');
    var f=d.querySelector('.invite-modal__foot a');
    var db=d.getBoundingClientRect(), fb=f.getBoundingClientRect(), qb=q.getBoundingClientRect();
    var de=document.documentElement;
    return {
      A: d.scrollHeight-d.clientHeight<=0,
      B: fb.bottom<=db.bottom+0.5,
      need: d.scrollHeight-d.clientHeight,
      iw: window.innerWidth, ih: window.innerHeight,
      sw: de.scrollWidth, cw: de.clientWidth,
      qr: qb.width+'×'+qb.height,
      cardH: +c.getBoundingClientRect().height.toFixed(2),
      dlgH: +db.height.toFixed(2)
    };
  })()`;

  await S('Emulation.setDeviceMetricsOverride', { width: W, height: 800, deviceScaleFactor: 1, mobile: true });
  await S('Page.navigate', { url: `${ORIGIN}/index.html` });
  await sleep(700);
  await ev(`(function(){var d=document.getElementById('invite-modal');if(!d.open)d.showModal();return d.open})()`);
  await sleep(300);

  for (const mode of [true, false]) {
    console.log(`\n--- ${W} 宽，mobile:${mode} ---`);
    let prevA, prevB, aMin = null, bMin = null;
    for (let h = H0; h <= H1; h++) {
      await S('Emulation.setDeviceMetricsOverride', { width: W, height: h, deviceScaleFactor: 1, mobile: mode });
      await sleep(320);
      let r = await ev(probe);
      const flipped = (prevA !== undefined && r.A !== prevA) || (prevB !== undefined && r.B !== prevB);
      if (h === H0 || flipped) { await sleep(400); r = await ev(probe); }
      if (prevA !== undefined && r.A && !prevA) aMin = h;
      if (prevB !== undefined && r.B && !prevB) bMin = h;
      if (h === H0 || flipped) console.log(`  ${h}: innerW ${r.iw} innerH ${r.ih} (scrollW ${r.sw}/clientW ${r.cw}) qr ${r.qr} cardH ${r.cardH} dlgH ${r.dlgH} need ${r.need} A=${r.A} B=${r.B}`);
      prevA = r.A; prevB = r.B;
    }
    console.log(`  首次 A 通过 ${aMin ?? '未通过（上界之外）'} ｜ 首次 B 通过 ${bMin ?? '未通过（上界之外）'}`);
  }

  ws.close();
  process.exit(0);
})().catch((e) => { console.error('失败：', e.message); process.exit(1); });
