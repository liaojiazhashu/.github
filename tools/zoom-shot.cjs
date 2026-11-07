// 一次性：用 Edge CDP 对指定元素高清截图（clip + scale 放大）。
// 用法：node tools/zoom-shot.cjs <url> <selector> <outfile> [scale]
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');

const [, , URL_, SELECTOR, OUTFILE, SCALE, VW] = process.argv;
const VIEWW = parseInt(VW || '460', 10);
const PORT = 9337;
const OUT = path.join(__dirname, '..', '.build', 'shots-library');

function findEdge() {
  for (const p of [
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
  ]) if (fs.existsSync(p)) return p;
  throw new Error('找不到 Edge');
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const waitForDevtools = () => new Promise((res, rej) => {
  (async () => {
    for (let i = 0; i < 60; i++) {
      try {
        const list = await new Promise((r2, j2) => {
          http.get(`http://127.0.0.1:${PORT}/json/list`, (r3) => {
            let d = ''; r3.on('data', (c) => d += c);
            r3.on('end', () => { try { r2(JSON.parse(d)); } catch (e) { j2(e); } });
          }).on('error', j2);
        });
        const page = list.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
        if (page) return res(page.webSocketDebuggerUrl);
      } catch { /* 还没起来 */ }
      await sleep(300);
    }
    rej(new Error('CDP 超时'));
  })();
});

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.seq = 0; this.pending = new Map();
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
      }
    });
  }
  send(method, params = {}) {
    const id = ++this.seq;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
      setTimeout(() => { if (this.pending.has(id)) { this.pending.delete(id); reject(new Error('CDP 超时: ' + method)); } }, 30000);
    });
  }
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-zoom-'));
  const child = spawn(findEdge(), [
    '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--no-first-run', '--disable-gpu', '--hide-scrollbars', `--window-size=${VIEWW},1100`, 'about:blank'
  ], { stdio: 'ignore' });
  process.on('exit', () => { try { child.kill(); } catch { /* ignore */ } });

  const ws = new WebSocket(await waitForDevtools());
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', () => reject(new Error('WebSocket 连接失败')), { once: true });
  });
  const cdp = new Cdp(ws);

  await cdp.send('Page.enable');
  await cdp.send('Emulation.setDeviceMetricsOverride', {
    width: VIEWW, height: 1100, deviceScaleFactor: Number(SCALE || 2.2), mobile: true
  });
  await cdp.send('Page.navigate', { url: URL_ });
  await sleep(2500);

  const q = JSON.stringify(SELECTOR);
  const r = await cdp.send('Runtime.evaluate', {
    expression: `(() => { const el = document.querySelector(${q}); if (!el) return null; const r = el.getBoundingClientRect(); return { x: r.x + window.scrollX, y: r.y + window.scrollY, w: r.width, h: r.height }; })()`,
    returnByValue: true
  });
  const b = r.result.value;
  if (!b) { console.error('找不到元素', SELECTOR); process.exit(1); }
  await sleep(400);
  const shot = await cdp.send('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: true,
    clip: { x: Math.max(0, b.x - 4), y: Math.max(0, b.y - 4), width: b.w + 8, height: b.h + 8, scale: Number(SCALE || 2.2) }
  });
  fs.writeFileSync(path.join(OUT, OUTFILE), Buffer.from(shot.data, 'base64'));
  console.log('saved', OUTFILE);
  ws.close(); child.kill(); process.exit(0);
})().catch((e) => { console.error(e.message); process.exit(1); });
