#!/usr/bin/env node
/* 核验「390 比 414 好过 10px」是不是布局视口被横向溢出撑出来的。 */
'use strict';
const fs = require('fs'), http = require('http'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8195, DBG = 9395;
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
  const dlg = document.getElementById('invite-modal');
  if (!dlg.open) dlg.showModal();
  const de = document.documentElement;
  return {
    innerW: innerWidth, innerH: innerHeight,
    vvW: Math.round(visualViewport.width), vvH: Math.round(visualViewport.height),
    docClientW: de.clientWidth, docClientH: de.clientHeight,
    scrollW: de.scrollWidth, scrollH: de.scrollHeight,
    maxH: getComputedStyle(dlg).maxHeight,
    dlgClientH: dlg.clientHeight,
    dlgH: +dlg.getBoundingClientRect().height.toFixed(2),
    cardH: +dlg.querySelector('.invite-modal__card').getBoundingClientRect().height.toFixed(2),
    cardW: +dlg.querySelector('.invite-modal__card').getBoundingClientRect().width.toFixed(2),
    needScroll: Math.max(0, dlg.scrollHeight - dlg.clientHeight)
  };
})()`;
async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const server = await startServer(), profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-p5-'));
  const env = { ...process.env }; Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${DBG}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });
  let target = null;
  for (let i = 0; i < 60; i++) { try { const list = await (await fetch(`http://127.0.0.1:${DBG}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {} await sleep(300); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const evalIn = async (e) => { const r = await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 200)); return r.result.value; };
  console.log('宽×高 | innerW×innerH | visualViewport | docClient | scroll | maxH | dlgClientH');
  const MOBILE = process.env.MOBILE !== '0';
  for (const [w, h] of [[390, 740], [390, 742], [390, 790]]) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: MOBILE });
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
    await sleep(700);
    await evalIn(`(async()=>{const im=document.querySelector('.invite-modal__qr');if(im&&!im.complete)await new Promise(r=>{im.onload=im.onerror=r;setTimeout(r,1200)});return 1})()`);
    const d = await evalIn(M);
    console.log(`${w}×${h} | innerH ${d.innerH} | docH ${d.docClientH} | 弹层高 ${d.dlgH} | 卡片 ${d.cardW}×${d.cardH} | need ${d.needScroll}`);
  }
  child.kill(); server.close(); await sleep(200); try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}
main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
