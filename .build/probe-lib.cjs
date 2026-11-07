#!/usr/bin/env node
/* 馆内页验收：横向溢出 / 顶栏（4 个导航项后是否换行）/ 作者索引列数 + 截图 */
'use strict';
const fs = require('fs'), http = require('http'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const OUT = path.resolve(__dirname, 'shots');
fs.mkdirSync(OUT, { recursive: true });
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const PORTD = 9301 + Math.floor(Math.random() * 60);

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
  const de = document.documentElement;
  const nav = document.querySelector('.nav');
  const top = document.querySelector('.top');
  const list = document.querySelector('.authlist');
  const cards = document.querySelectorAll('.authcard').length;
  const arow = document.querySelector('.authrow');
  return {
    cw: de.clientWidth, sw: de.scrollWidth,
    topH: top ? +top.getBoundingClientRect().height.toFixed(1) : null,
    navW: nav ? +nav.getBoundingClientRect().width.toFixed(1) : null,
    navH: nav ? +nav.getBoundingClientRect().height.toFixed(1) : null,
    navItems: nav ? nav.querySelectorAll('a').length : 0,
    navOn: nav && nav.querySelector('[aria-current]') ? nav.querySelector('[aria-current]').textContent.trim() : null,
    grid: list ? getComputedStyle(list).gridTemplateColumns : null,
    cards, arow: arow ? arow.children.length : 0,
    h1: (document.querySelector('h1') || {}).textContent
  };
})()`;

async function main() {
  const edge = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-lib-'));
  const env = { ...process.env }; Object.keys(env).forEach((k) => { if (/^(https?_proxy|all_proxy|no_proxy)$/i.test(k)) delete env[k]; });
  const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run', '--hide-scrollbars', '--force-prefers-reduced-motion', `--remote-debugging-port=${PORTD}`, `--user-data-dir=${profile}`, 'about:blank'], { env, stdio: 'ignore' });
  let target = null;
  for (let i = 0; i < 60; i++) { try { const list = await (await fetch(`http://127.0.0.1:${PORTD}/json/list`)).json(); target = list.find((t) => t.type === 'page'); if (target) break; } catch {} await sleep(300); }
  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable'); await cdp.send('Runtime.enable');
  const evalIn = async (e) => { const r = await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 300)); return r.result.value; };
  const shot = async (file) => { const r = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); fs.writeFileSync(path.join(OUT, file), Buffer.from(r.data, 'base64')); };

  const widths = (process.env.WIDTHS || '1440,1024,960,860,760,390').split(',').map(Number);
  // Git Bash 会把「/library」这种参数当成本机路径改写掉（→ D:/Program Files/...），
  // 所以这里接受不带前导斜杠的写法，路由前缀在脚本里补。
  const pages = (process.env.PAGES || 'library/authors,library,library/works,library/terms')
    .split(',').map((p) => (p.startsWith('/') ? p : '/' + p));
  for (const w of widths) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: false });
    for (const p of pages) {
      await cdp.send('Page.navigate', { url: BASE + p });
      await sleep(650);
      const d = await evalIn(M);
      const bad = d.sw > d.cw ? `  ⚠ 溢出 ${d.sw - d.cw}` : '';
      await shot(`${p.replace(/\//g,'_')}-${w}.png`);
      console.log(`${String(w).padEnd(4)} ${p.padEnd(18)} 溢出 ${String(d.sw - d.cw).padStart(3)} | 顶栏高 ${d.topH} nav ${d.navW}×${d.navH}(${d.navItems}项,当前 ${d.navOn}) | 卡片 ${d.cards} 作者行 ${d.arow} | 列 ${d.grid}${bad}`);
    }
    await cdp.send('Page.navigate', { url: BASE + '/library' });
    await sleep(650);
    await evalIn(`(() => { const el = document.querySelector('.authrow'); if (el) el.scrollIntoView({block: 'center'}); return 1; })()`);
    await sleep(400);
    await shot(`home-authrow-${w}.png`);
  }
  console.log('截图目录', OUT);
  child.kill(); await sleep(200); try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
}
main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
