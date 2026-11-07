'use strict';
/* 两件事一起量：
   A. 390 那 10px 到底来自「卡片换行」还是「上限（innerH−35）」——两口径下 cardH 是否相同。
   B. 横向越界到底是谁：列出 rect.right 超过 clientWidth 的元素（不是猜 bg-grid）。 */
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8134, DEBUG = 9351;
const ORIGIN = `http://127.0.0.1:${PORT}`;
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CASES = [[390, 752], [414, 752]];

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
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-mx-'));
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

  for (const [w, h] of CASES) {
    for (const mode of [true, false]) {
      await S('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: mode });
      await S('Page.navigate', { url: `${ORIGIN}/index.html` });
      await sleep(700);
      await ev(`(function(){var d=document.getElementById('invite-modal');if(!d.open)d.showModal();return d.open})()`);
      await sleep(350);

      const r = await ev(`(function(){
        var de=document.documentElement;
        var d=document.getElementById('invite-modal');
        var card=d.querySelector('.invite-modal__card');
        var case_=getComputedStyle(d).maxHeight;
        var db=d.getBoundingClientRect();
        // 列出越界元素（rect.right > clientWidth），按超出量降序，取前 6
        var over=[];
        document.querySelectorAll('body *').forEach(function(el){
          var b=el.getBoundingClientRect();
          if(b.width>0 && b.right>de.clientWidth+0.5 && el.tagName!=='HTML' && el.tagName!=='BODY'){
            over.push({sel: el.tagName.toLowerCase()+(el.className&&typeof el.className==='string'?'.'+el.className.trim().split(/\\s+/).join('.'):''), right:+b.right.toFixed(2), w:+b.width.toFixed(2)});
          }
        });
        over.sort(function(a,b){return b.right-a.right;});
        var inner=document.querySelector('.site-header__inner');
        var brand=document.querySelector('a.brand');
        var acts=document.querySelector('.site-header__actions');
        return {
          innerW: window.innerWidth, innerH: window.innerHeight,
          scrollW: de.scrollWidth, clientW: de.clientWidth,
          cap: case_,
          cardH: +card.getBoundingClientRect().height.toFixed(2),
          cardW: +card.getBoundingClientRect().width.toFixed(2),
          dlgH: +db.height.toFixed(2), dlgCH: d.clientHeight, need: d.scrollHeight-d.clientHeight,
          headerRow: inner ? { w:+inner.getBoundingClientRect().width.toFixed(2), scrollW: inner.scrollWidth, gap: getComputedStyle(inner).columnGap } : null,
          brand: brand ? +brand.getBoundingClientRect().width.toFixed(2) : null,
          actions: acts ? +acts.getBoundingClientRect().width.toFixed(2) : null,
          over: over.slice(0,6)
        };
      })()`);

      console.log(`\n=== ${w}×${h} mobile:${mode} ===`);
      console.log(`innerW ${r.innerW} innerH ${r.innerH} | scrollW ${r.scrollW} clientW ${r.clientW} | max-height ${r.cap}`);
      console.log(`弹出层: dlgH ${r.dlgH} clientH ${r.dlgCH} need ${r.need} | 卡片 ${r.cardW}×${r.cardH}`);
      console.log(`header__inner W ${r.headerRow?.w} scrollW ${r.headerRow?.scrollW} gap ${r.headerRow?.gap} | brand ${r.brand} | actions ${r.actions}`);
      console.log('越界的元素（前 6）:');
      r.over.forEach((o) => console.log(`   right ${o.right} (w ${o.w})  ${o.sel}`));
    }
  }

  ws.close();
  process.exit(0);
})().catch((e) => { console.error('失败：', e.message); process.exit(1); });
