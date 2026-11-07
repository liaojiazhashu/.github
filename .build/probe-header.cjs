#!/usr/bin/env node
/* 核验 ux 报的缺陷：390 下顶栏菜单按钮是否被切。 */
'use strict';
const fs = require('fs'), http = require('http'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8192, DBG = 9392;
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
  const q = (s) => document.querySelector(s);
  const r = (el) => el ? { l: +el.getBoundingClientRect().left.toFixed(2), r: +el.getBoundingClientRect().right.toFixed(2), w: +el.getBoundingClientRect().width.toFixed(2), d: getComputedStyle(el).display } : null;
  const inner = q('.site-header__inner') || q('.site-header .container');
  const acts = q('.site-header__actions');
  const tg = q('.nav-toggle') || q('[data-nav-toggle]');
  const inv = q('.site-header__actions a[href*="contact"], .site-header__actions .btn');
  const kids = acts ? Array.from(acts.children).map((c) => ({ s: c.tagName.toLowerCase() + '.' + (typeof c.className === 'string' ? c.className.trim().split(/\\s+/)[0] : ''), w: +c.getBoundingClientRect().width.toFixed(2), d: getComputedStyle(c).display })) : [];
  const hdr = document.querySelector('.site-header');
  const innerC = document.querySelector('.site-header__inner');
  const contC = document.querySelector('.site-header .container') || document.querySelector('.site-header > div');
  return {
    innerPad: innerC ? getComputedStyle(innerC).paddingInline : null,
    innerGap: innerC ? getComputedStyle(innerC).gap : null,
    contPad: contC ? getComputedStyle(contC).paddingInline : null,
    contCls: contC ? contC.className : null,
    hdrH: hdr ? +hdr.getBoundingClientRect().height.toFixed(2) : null,
    cw, scrollW: de.scrollWidth,
    inner: r(inner), acts: r(acts), toggle: r(tg), invite: r(inv),
    gap: acts ? getComputedStyle(acts).gap : null,
    kids,
    toggleClip: tg ? +(tg.getBoundingClientRect().right - cw).toFixed(2) : null
  };
})()`;
async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const server = await startServer(), profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-p8-'));
  const env = { ...process.env }; Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${DBG}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });
  let target = null;
  for (let i = 0; i < 60; i++) { try { const list = await (await fetch(`http://127.0.0.1:${DBG}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {} await sleep(300); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const evalIn = async (e) => { const r = await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 300)); return r.result.value; };
  for (const w of [368, 367, 366]) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: 800, deviceScaleFactor: 1, mobile: false });
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
    await sleep(700);
    const d = await evalIn(M);
    console.log(`${String(w).padEnd(4)} clientW ${d.cw} scrollW ${d.scrollW} | inner ${d.inner && d.inner.w} | actions ${d.acts && d.acts.w} gap ${d.gap} | toggle ${d.toggle && d.toggle.w} right ${d.toggle && d.toggle.r} 超出 ${d.toggleClip} | invite ${d.invite && d.invite.w}/${d.invite && d.invite.d} | 顶栏高 ${d.hdrH}`);
    console.log(`      inner padding ${d.innerPad} gap ${d.innerGap} | container(${d.contCls}) padding ${d.contPad}`);
    console.log('      actions 子项', JSON.stringify(d.kids));
  }
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: false });
  await cdp.send('Page.navigate', { url: `http://127.0.0.1:${PORT}/index.html` });
  await sleep(700);
  console.log('\n隐藏顶栏后：', JSON.stringify(await evalIn(`(() => { const h = document.querySelector('.site-header'); h.style.display = 'none'; return { scrollW: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }; })()`)));
  child.kill(); server.close(); await sleep(200); try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}
main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
