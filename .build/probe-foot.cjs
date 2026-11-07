#!/usr/bin/env node
/* 核验 fe 的分解：脚注「底边」的行盒 vs 行内盒，差是否 = 上下边框 + 半个行距。 */
'use strict';
const fs = require('fs'), http = require('http'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8196, DBG = 9396;
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
const MEASURE = `(() => {
  const dlg = document.getElementById('invite-modal');
  if (!dlg.open) dlg.showModal();
  const foot = dlg.querySelector('.invite-modal__foot');
  const a = foot.querySelector('a');
  const pr = foot.getBoundingClientRect(), ar = a.getBoundingClientRect(), dr = dlg.getBoundingClientRect();
  const cs = getComputedStyle(foot);
  return {
    dlgH: +dr.height.toFixed(2), clientH: dlg.clientHeight,
    border: +((dr.height - dlg.clientHeight)).toFixed(2),
    pBottom: +pr.bottom.toFixed(2), aBottom: +ar.bottom.toFixed(2),
    pH: +pr.height.toFixed(2), aH: +ar.height.toFixed(2),
    lh: cs.lineHeight, fs: cs.fontSize,
    diff: +(pr.bottom - ar.bottom).toFixed(2),
    halfLead: +((parseFloat(cs.lineHeight) - ar.height) / 2).toFixed(2)
  };
})()`;
async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const server = await startServer(), profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-p4-'));
  const env = { ...process.env }; Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${DBG}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });
  let target = null;
  for (let i = 0; i < 60; i++) { try { const list = await (await fetch(`http://127.0.0.1:${DBG}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {} await sleep(300); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const evalIn = async (e) => { const r = await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 200)); return r.result.value; };
  for (const w of [390, 414, 479, 480]) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: 844, deviceScaleFactor: 1, mobile: true });
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
    await sleep(650);
    await evalIn(`(async()=>{const im=document.querySelector('.invite-modal__qr');if(im&&!im.complete)await new Promise(r=>{im.onload=im.onerror=r;setTimeout(r,1200)});return 1})()`);
    const d = await evalIn(MEASURE);
    console.log(`${w}: 边框 ${d.border} | p 底 ${d.pBottom} / a 底 ${d.aBottom} → 差 ${d.diff} | p 高 ${d.pH} / a 高 ${d.aH} | line-height ${d.lh} | 半个行距 ${d.halfLead} | 边框+半行距 ${(d.border + d.halfLead).toFixed(2)}`);
  }
  child.kill(); server.close(); await sleep(200); try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}
main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
