#!/usr/bin/env node
/* 谁把 390 撑到 395？顺带确认 overflow-x 到底裁不裁。 */
'use strict';
const fs = require('fs'), http = require('http'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8193, DBG = 9393;
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.json': 'application/json' };
function startServer() {
  const s = http.createServer((req, res) => {
    let rel = decodeURIComponent(req.url.split('?')[0]); if (rel === '/') rel = '/index.html';
    const file = path.join(ROOT, rel);
    if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end('404'); return; }
    res.writeHead(200, { 'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' }); fs.createReadStream(file).pipe(res);
  });
  return new Promise((r) => s.listen(PORT, '127.0.0.1', () => r(s)));
}
function connect(wsUrl) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl); let seq = 0; const pending = new Map();
    ws.addEventListener('message', (ev) => { let m; try { m = JSON.parse(ev.data); } catch { return; } if (m.id && pending.has(m.id)) { const { res, rej } = pending.get(m.id); pending.delete(m.id); if (m.error) rej(new Error(JSON.stringify(m.error))); else res(m.result); } });
    ws.addEventListener('error', reject);
    ws.addEventListener('open', () => resolve({ send(method, params) { return new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params: params || {} })); }); }, close() { try { ws.close(); } catch {} } }));
  });
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const M = `(() => {
  const de = document.documentElement, cw = de.clientWidth;
  const out = [];
  document.querySelectorAll('body *').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0) return;
    if (el.closest('svg')) return;
    const over = r.right - cw;
    if (over > 0.5) out.push({ sel: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).join('.') : ''), w: +r.width.toFixed(2), right: +r.right.toFixed(2), over: +over.toFixed(2) });
  });
  out.sort((a, b) => b.over - a.over);
  const hdr = document.querySelector('.site-header');
  const kids = hdr ? Array.from(hdr.children).map((c) => ({ s: c.tagName.toLowerCase() + '.' + (typeof c.className === 'string' ? c.className.trim().split(/\\s+/)[0] : ''), w: +c.getBoundingClientRect().width.toFixed(2) })) : [];
  return {
    cw, scrollW: de.scrollWidth,
    htmlOverflowX: getComputedStyle(de).overflowX,
    bodyOverflowX: getComputedStyle(document.body).overflowX,
    top: out.slice(0, 5), hdrKids: kids,
    hdrInner: hdr ? +hdr.firstElementChild.getBoundingClientRect().width.toFixed(2) : null,
    bg: (() => { const b = document.querySelector('.bg-grid'); if (!b) return null; const r = b.getBoundingClientRect(); const cs = getComputedStyle(b); return { w: +r.width.toFixed(2), left: +r.left.toFixed(2), right: +r.right.toFixed(2), pos: cs.position, inset: cs.inset, transform: cs.transform }; })(),
    widest: (() => { let m = null; document.querySelectorAll('body > *').forEach((el) => { const r = el.getBoundingClientRect(); if (!m || r.width > m.w) m = { s: el.tagName.toLowerCase() + '.' + (typeof el.className === 'string' ? el.className.trim().split(/\s+/)[0] : ''), w: +r.width.toFixed(2) }; }); return m; })()
  };
})()`;
async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const server = await startServer(), profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-p7-'));
  const env = { ...process.env }; Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${DBG}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });
  let target = null;
  for (let i = 0; i < 60; i++) { try { const list = await (await fetch(`http://127.0.0.1:${DBG}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {} await sleep(300); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const evalIn = async (e) => { const r = await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 300)); return r.result.value; };
  for (const w of [390, 330]) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: 790, deviceScaleFactor: 1, mobile: false });
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
    await sleep(800);
    const d = await evalIn(M);
    console.log(`\n=== ${w} 宽 | clientW ${d.cw} | scrollW ${d.scrollW} | html overflow-x ${d.htmlOverflowX} | body ${d.bodyOverflowX} ===`);
    console.log('  header 内层宽', d.hdrInner);
    console.log('  header 子项', JSON.stringify(d.hdrKids));
    d.top.forEach((o) => console.log(`  越界 ${o.over} ← ${o.sel} (w ${o.w})`));
    console.log('  bg-grid', JSON.stringify(d.bg), '| body 最宽子项', JSON.stringify(d.widest));
    console.log('  body scrollW', await evalIn('document.body.scrollWidth'));
  }
  child.kill(); server.close(); await sleep(200); try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}
main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
