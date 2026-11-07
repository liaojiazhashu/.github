#!/usr/bin/env node
/* 复核 330 档的「701 就能不滚」是不是假通过：
   把 dialog 的 clientHeight / 卡片高 / 卡片是否被裁 一起打出来。 */
'use strict';
const fs = require('fs'), http = require('http'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8198, DBG = 9398;
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
  if (!dlg) return { err: 'no dialog' };
  if (!dlg.open) dlg.showModal();
  const card = dlg.querySelector('.invite-modal__card');
  const foot = dlg.querySelector('.invite-modal__foot');
  const r = dlg.getBoundingClientRect(), cr = card.getBoundingClientRect(), fr = foot.getBoundingClientRect();
  return {
    vh: innerHeight,
    dlgClientH: dlg.clientHeight,
    dlgScrollH: dlg.scrollHeight,
    cardH: +cr.height.toFixed(2),
    cardClipped: card.scrollHeight > card.clientHeight + 1,
    cardScrollH: card.scrollHeight, cardClientH: card.clientHeight,
    needScroll: Math.max(0, dlg.scrollHeight - dlg.clientHeight),
    footGap: +(r.bottom - fr.bottom).toFixed(2),
    maxH: getComputedStyle(dlg).maxHeight
  };
})()`;
async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const server = await startServer(), profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-p2-'));
  const env = { ...process.env }; Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${DBG}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });
  let target = null;
  for (let i = 0; i < 60; i++) { try { const list = await (await fetch(`http://127.0.0.1:${DBG}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {} await sleep(300); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const evalIn = async (e) => { const r = await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 200)); return r.result.value; };
  const at = async (w, h) => {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: true });
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
    await sleep(600);
    await evalIn(`(async()=>{const im=document.querySelector('.invite-modal__qr');if(im&&!im.complete)await new Promise(r=>{im.onload=im.onerror=r;setTimeout(r,1200)});return 1})()`);
    return evalIn(MEASURE);
  };
  for (const w of [330, 390]) {
    console.log(`\n=== 宽 ${w} ===`);
    console.log('  vh | dlgClientH | dlgScrollH | needScroll | 卡片高 | maxH | 卡片被裁 | footGap');
    for (const h of [660, 690, 700, 701, 710, 730, 742, 752, 770, 790, 844, 896]) {
      const d = await at(w, h);
      console.log(`  ${String(h).padEnd(4)}| ${String(d.dlgClientH).padEnd(11)}| ${String(d.dlgScrollH).padEnd(11)}| ${String(d.needScroll).padEnd(11)}| ${String(d.cardH).padEnd(7)}| ${d.maxH} | ${d.cardClipped} | ${d.footGap}`);
    }
  }
  child.kill(); server.close(); await sleep(200); try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}
main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
