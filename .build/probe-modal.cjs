#!/usr/bin/env node
/* 独立核验：邀请弹层在给定宽度下的「不滚所需最低视口高」。
   不复用任何已有脚本（含 .build/ux-modal-height.cjs），自建 CDP 连接。
   用法：node .build/probe-modal.cjs */
'use strict';

const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PORT = 8199;
const DBG = 9399;

const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.json': 'application/json' };

function startServer() {
  const s = http.createServer((req, res) => {
    let rel = decodeURIComponent(req.url.split('?')[0]);
    if (rel === '/') rel = '/index.html';
    const file = path.join(ROOT, rel);
    if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end('404'); return; }
    res.writeHead(200, { 'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
  });
  return new Promise((r) => s.listen(PORT, '127.0.0.1', () => r(s)));
}

function connect(wsUrl) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl);
    let seq = 0;
    const pending = new Map();
    ws.addEventListener('message', (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.id && pending.has(m.id)) {
        const { res, rej } = pending.get(m.id); pending.delete(m.id);
        if (m.error) rej(new Error(JSON.stringify(m.error))); else res(m.result);
      }
    });
    ws.addEventListener('error', reject);
    ws.addEventListener('open', () => resolve({
      send(method, params) {
        return new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params: params || {} })); });
      },
      close() { try { ws.close(); } catch {} }
    }));
  });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const MEASURE = `(() => {
  const dlg = document.getElementById('invite-modal');
  if (!dlg) return { err: 'no dialog' };
  if (!dlg.open) dlg.showModal();
  const card = dlg.querySelector('.invite-modal__card');
  const qr = dlg.querySelector('.invite-modal__qr');
  const foot = dlg.querySelector('.invite-modal__foot');
  const r = dlg.getBoundingClientRect();
  const cr = card.getBoundingClientRect();
  const fr = foot.getBoundingClientRect();
  return {
    vw: innerWidth, vh: innerHeight,
    cardH: +cr.height.toFixed(2),
    qrW: Math.round(qr.getBoundingClientRect().width),
    qrH: Math.round(qr.getBoundingClientRect().height),
    needScroll: Math.max(0, dlg.scrollHeight - dlg.clientHeight),
    footBottomGap: +(r.bottom - fr.bottom).toFixed(2),
    dlgMaxH: getComputedStyle(dlg).maxHeight
  };
})()`;

async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  if (!edge) throw new Error('no edge');
  const server = await startServer();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-probe-'));
  const env = { ...process.env };
  Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${DBG}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });

  let target = null;
  for (let i = 0; i < 60; i++) {
    try { const list = await (await fetch(`http://127.0.0.1:${DBG}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {}
    await sleep(300);
  }
  if (!target) { child.kill(); server.close(); throw new Error('debug port not ready'); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');

  const evalIn = async (expr) => {
    const r = await cdp.send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 300));
    return r.result.value;
  };

  const at = async (w, h) => {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: true });
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
    await sleep(650);
    await evalIn(`(async () => { const im = document.querySelector('.invite-modal__qr'); if (im && !im.complete) await new Promise(r => { im.onload = im.onerror = r; setTimeout(r, 1200); }); return 1; })()`);
    return evalIn(MEASURE);
  };

  /* 二分：不滚所需最低视口高 */
  const minH = async (w) => {
    let lo = 560, hi = 900;
    const ok = async (h) => (await at(w, h)).needScroll === 0;
    if (!(await ok(hi))) return null;
    while (hi - lo > 1) { const mid = (lo + hi) >> 1; if (await ok(mid)) hi = mid; else lo = mid; }
    return hi;
  };

  console.log('宽度 | 卡片高 | 二维码 | 不滚最低视口高 | 790 高时 needScroll | 底部入口余量@790');
  for (const w of [330, 390, 414, 479, 480, 760]) {
    const mh = await minH(w);
    const d = await at(w, 790);
    console.log(`${w} | ${d.cardH} | ${d.qrW}×${d.qrH} | ${mh} | ${d.needScroll} | ${d.footBottomGap}`);
  }

  /* 边界两侧同高度对照：479/480（确认断点是哪一对） */
  console.log('\n=== 479 / 480 同高对照（790）===');
  for (const w of [478, 479, 480, 481]) {
    const d = await at(w, 790);
    console.log(`  ${w}: 卡片高 ${d.cardH} / 二维码 ${d.qrW}×${d.qrH} / needScroll ${d.needScroll}`);
  }

  child.kill(); server.close();
  await sleep(200);
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}

main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
