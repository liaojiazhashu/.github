#!/usr/bin/env node
/* 第三版判据：needScroll==0 且 弹层矩形完整落在视口内。
   （前两版只用 needScroll，330 档会假通过：dialog 高 770 > 视口 701。） */
'use strict';
const fs = require('fs'), http = require('http'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8197, DBG = 9397;
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
/* MOBILE=0 走非 mobile 模拟：布局视口 = 设备视口，等价于真机 width=device-width 的行为。
   launch 参数带 --hide-scrollbars，所以不会出现「经典滚动条吃掉 15px」那个已知假象。 */
const MOBILE = process.env.MOBILE !== '0';
const MEASURE = `(() => {
 try {
  const dlg = document.getElementById('invite-modal');
  if (!dlg) return { err: 'no dialog' };
  if (!dlg.open) dlg.showModal();
  const r = dlg.getBoundingClientRect();
  const card = dlg.querySelector('.invite-modal__card').getBoundingClientRect();
  return {
    vh: innerHeight,
    cardH: +card.height.toFixed(2),
    needScroll: Math.max(0, dlg.scrollHeight - dlg.clientHeight),
    top: +r.top.toFixed(2), bottom: +r.bottom.toFixed(2),
    fits: r.top >= -0.5 && r.bottom <= innerHeight + 0.5,
    innerW: innerWidth, innerH: innerHeight, cw: document.documentElement.clientWidth, sw: document.documentElement.scrollWidth,
    qr: Math.round(dlg.querySelector('.invite-modal__qr').getBoundingClientRect().width)
  };
 } catch (e) { return { err: String(e && e.message || e) }; }
})()`;
async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const server = await startServer(), profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-p3-'));
  const env = { ...process.env }; Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${DBG}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });
  let target = null;
  for (let i = 0; i < 60; i++) { try { const list = await (await fetch(`http://127.0.0.1:${DBG}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {} await sleep(300); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const evalIn = async (e) => { const r = await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 200)); return r.result.value; };
  const at = async (w, h) => {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: MOBILE });
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
    await sleep(600);
    await evalIn(`(async()=>{const im=document.querySelector('.invite-modal__qr');if(im&&!im.complete)await new Promise(r=>{im.onload=im.onerror=r;setTimeout(r,1200)});return 1})()`);
    return evalIn(MEASURE);
  };
  const ok = async (w, h) => { const d = await at(w, h); return d.needScroll === 0 && d.fits; };
  const minH = async (w) => {
    let lo = 560, hi = 1000;
    if (!(await ok(w, hi))) return 'NULL';
    while (hi - lo > 1) { const mid = (lo + hi) >> 1; if (await ok(w, mid)) hi = mid; else lo = mid; }
    return hi;
  };
  console.log('MOBILE=' + MOBILE);
  console.log('宽度 | 卡片高@790 | 二维码 | 最低视口高 | innerW/innerH | clientW/scrollW');
  const WIDTHS = (process.env.WIDTHS || '330,390,414,479,480').split(',').map(Number);
  for (const w of WIDTHS) {
    const d = await at(w, 790);
    const mh = await minH(w);
    console.log(`${w} | ${d.cardH} | ${d.qr} | ${mh} | ${d.innerW}/${d.innerH} | ${d.cw}/${d.sw} ${d.err ? 'ERR ' + d.err : ''}`);
  }
  child.kill(); server.close(); await sleep(200); try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}
main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
