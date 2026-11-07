#!/usr/bin/env node
/* team-lead 独立复核：有头 Edge + 可信点击，验复制是否真的写进系统剪贴板。
   本脚本只负责「点」；系统剪贴板由 PowerShell Get-Clipboard 在脚本外读。 */
'use strict';

const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PORT = 8125;
const DEBUG = 9455;
const WIDTH = parseInt(process.env.WIDTH || '390', 10);
const EDGE = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
].find((p) => fs.existsSync(p));

const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };

function startServer() {
  const s = http.createServer((req, res) => {
    let rel = decodeURIComponent(req.url.split('?')[0]);
    if (rel === '/') rel = '/index.html';
    const f = path.join(ROOT, rel);
    if (!f.startsWith(ROOT) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': MIME[path.extname(f).toLowerCase()] || 'application/octet-stream' });
    fs.createReadStream(f).pipe(res);
  });
  return new Promise((r) => s.listen(PORT, '127.0.0.1', () => r(s)));
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function cdp(wsUrl) {
  const ws = new WebSocket(wsUrl);
  let seq = 0;
  const pending = new Map();
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const { res, rej } = pending.get(m.id); pending.delete(m.id);
      m.error ? rej(new Error(JSON.stringify(m.error))) : res(m.result);
    }
  });
  await new Promise((res, rej) => { ws.addEventListener('open', res); ws.addEventListener('error', rej); });
  return {
    send: (method, params) => new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params: params || {} })); }),
    close: () => { try { ws.close(); } catch { /* ignore */ } }
  };
}

(async () => {
  const server = await startServer();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-headful-'));
  const child = spawn(EDGE, [
    `--remote-debugging-port=${DEBUG}`,
    `--user-data-dir=${profile}`,
    `--no-first-run`, '--no-default-browser-check',
    `--window-size=${WIDTH},900`,
    'http://127.0.0.1:' + PORT + '/index.html'
  ], { stdio: 'ignore' });

  let targets = null;
  for (let i = 0; i < 40; i++) {
    await sleep(400);
    try {
      const r = await fetch(`http://127.0.0.1:${DEBUG}/json/list`);
      const list = await r.json();
      const page = list.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      if (page) { targets = page; break; }
    } catch { /* 还没起来 */ }
  }
  if (!targets) throw new Error('Edge 未就绪');

  const c = await cdp(targets.webSocketDebuggerUrl);
  await c.send('Page.enable');
  await c.send('Runtime.enable');
  await c.send('Target.activateTarget', { targetId: targets.id }).catch(() => {});
  await sleep(1500);

  const evaluate = async (expr) => {
    const r = await c.send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.result ? r.result.value : undefined;
  };

  // 打开弹层（showModal 不要求用户激活，合成点击够用）
  await evaluate(`(function(){ var b=document.querySelector('[data-invite-open]'); if(b) b.click(); })()`);
  await sleep(600);

  // STUB=1：抹掉 navigator.clipboard，强制走 execCommand 兜底，验 inert 修复
  if (process.env.STUB === '1') {
    const stubbed = await evaluate(`(function(){
      try { Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true }); } catch (e) { return 'fail:' + e.message; }
      return typeof navigator.clipboard;
    })()`);
    console.log('navigator.clipboard 已置为:', stubbed);
  }

  const rect = JSON.parse(await evaluate(`(function(){
    var b = document.querySelector('.copy-btn');
    if (!b) return JSON.stringify({err:'no btn'});
    var r = b.getBoundingClientRect();
    return JSON.stringify({ x: Math.round(r.left + r.width/2), y: Math.round(r.top + r.height/2), w: Math.round(r.width), h: Math.round(r.height) });
  })()`));
  if (!rect || rect.err) throw new Error('找不到 .copy-btn');

  // 可信点击
  await c.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: rect.x, y: rect.y, button: 'none', clickCount: 0 });
  await c.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: rect.x, y: rect.y, button: 'left', clickCount: 1 });
  await c.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: rect.x, y: rect.y, button: 'left', clickCount: 1 });
  await sleep(900);

  const after = JSON.parse(await evaluate(`(function(){
    var b = document.querySelector('.copy-btn');
    return JSON.stringify({ text: b.textContent.trim(), cls: b.className, aria: b.getAttribute('aria-label'), focused: document.hasFocus() });
  })()`));

  console.log(JSON.stringify({ btn: rect, after }, null, 2));
  await sleep(300);
  c.close();
  try { child.kill(); } catch { /* ignore */ }
  server.close();
  process.exit(0);
})().catch((e) => { console.error('失败:', e.message); process.exit(1); });
