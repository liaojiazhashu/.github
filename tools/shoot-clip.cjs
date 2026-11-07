#!/usr/bin/env node
/* ==========================================================================
   单页分段截图（按锚点/选择器滚动取景）
   --------------------------------------------------------------------------
   和 shoot-library.cjs 的关系：
     · 那个是「全页整张 + 版面审计」，一张图 9000px 高，人在预览里看不清细节；
     · 这个是「同一页按区块各取一屏」，用于人工验收某一篇的排版。
   用法：
     URL=http://127.0.0.1:8090/library/works/01M6... NAME=work node tools/shoot-clip.cjs
   输出：.build/shots-library/clip-<NAME>-<区块>.png
   ========================================================================== */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, '.build', 'shots-library');
const DEBUG_PORT = parseInt(process.env.DEBUG_PORT || '9337', 10);
const URL_ = process.env.URL || 'http://127.0.0.1:8090/library';
const NAME = process.env.NAME || 'page';
const WIDTH = parseInt(process.env.WIDTH || '1440', 10);
const VIEW_H = parseInt(process.env.VIEW_H || '1400', 10);
const THEME = (process.env.THEME || 'day').toLowerCase();

/** 取景清单：[输出后缀, 选择器]；选择器为空表示页首
 *  可用 CLIPS="top:,keys:#keys,who:#who" 覆盖 —— 读者页与作者页的锚点不同，
 *  写死一份清单必然有一边对不上（对不上就截出五张一样的首页）。 */
const DEFAULT_SPOTS = [
  ['top', ''],
  ['index', '#index'],
  ['plot', '#plot'],
  ['advice', '#advice'],
  ['revision', '#revision'],
  ['meta', '.sec--meta']
];

const SPOTS = (process.env.CLIPS || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean)
  .map((pair) => {
    const i = pair.indexOf(':');
    return i < 0 ? [pair, ''] : [pair.slice(0, i), pair.slice(i + 1)];
  });

if (SPOTS.length === 0) SPOTS.push(...DEFAULT_SPOTS);

const EDGE_CANDIDATES = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const httpJson = (url) => new Promise((resolve, reject) => {
  http.get(url, (res) => {
    let body = '';
    res.on('data', (c) => { body += c; });
    res.on('end', () => {
      try { resolve(JSON.parse(body)); } catch (e) { reject(e); }
    });
  }).on('error', reject);
});

async function waitForDevtools() {
  for (let i = 0; i < 60; i++) {
    try {
      const v = await httpJson(`http://127.0.0.1:${DEBUG_PORT}/json/version`);
      if (v.webSocketDebuggerUrl) return v.webSocketDebuggerUrl;
    } catch { /* 还没起来 */ }
    await sleep(300);
  }
  throw new Error('Edge 调试端口未就绪');
}

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.seq = 0;
    this.pending = new Map();
    this.events = new Map();
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
      } else if (msg.method && this.events.has(msg.method)) {
        this.events.get(msg.method).forEach((fn) => fn(msg.params));
      }
    });
  }

  send(method, params = {}, sessionId = undefined) {
    const id = ++this.seq;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      const payload = { id, method, params };
      if (sessionId) payload.sessionId = sessionId;
      this.ws.send(JSON.stringify(payload));
      setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          reject(new Error(`CDP 超时: ${method}`));
        }
      }, 30000);
    });
  }

  once(method) {
    return new Promise((resolve) => {
      const list = this.events.get(method) || [];
      const handler = (params) => {
        const idx = list.indexOf(handler);
        if (idx >= 0) list.splice(idx, 1);
        resolve(params);
      };
      list.push(handler);
      this.events.set(method, list);
    });
  }
}

async function connect(url) {
  const ws = new WebSocket(url);
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', () => reject(new Error('WebSocket 连接失败')), { once: true });
  });
  return new Cdp(ws);
}

async function main() {
  const edge = EDGE_CANDIDATES.find((p) => fs.existsSync(p));
  if (!edge) throw new Error('找不到 Edge');

  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hqclip-'));
  const child = spawn(edge, [
    '--headless=new',
    `--remote-debugging-port=${DEBUG_PORT}`,
    `--user-data-dir=${profile}`,
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-gpu',
    '--hide-scrollbars',
    '--force-device-scale-factor=1',
    `--window-size=${WIDTH},${VIEW_H}`,
    'about:blank'
  ], { stdio: 'ignore', windowsHide: true });

  const browser = await connect(await waitForDevtools());
  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await browser.send('Target.attachToTarget', { targetId, flatten: true });

  await browser.send('Page.enable', {}, sessionId);
  await browser.send('Runtime.enable', {}, sessionId);

  await browser.send('Page.addScriptToEvaluateOnNewDocument', {
    source: `try { localStorage.setItem('hq-theme', ${JSON.stringify(THEME)}); } catch (e) {}`
  }, sessionId);

  await browser.send('Emulation.setDeviceMetricsOverride', {
    width: WIDTH, height: VIEW_H, deviceScaleFactor: 1, mobile: false
  }, sessionId);

  const loaded = browser.once('Page.loadEventFired');
  await browser.send('Page.navigate', { url: URL_ }, sessionId);
  await loaded;
  await sleep(500);

  fs.mkdirSync(OUT, { recursive: true });

  for (const [suffix, selector] of SPOTS) {
    const expr = selector === ''
      ? 'window.scrollTo(0, 0); "top"'
      : `(function(){ var el = document.querySelector(${JSON.stringify(selector)});
           if (!el) return "missing";
           var y = el.getBoundingClientRect().top + window.scrollY - 24;
           window.scrollTo(0, Math.max(0, y));
           return String(Math.round(y)); })()`;
    const r = await browser.send('Runtime.evaluate', { expression: expr, returnByValue: true }, sessionId);
    const val = r.result ? r.result.value : '?';
    /* 页面元素是滚动进入视口才显现的（[data-reveal] 从透明+位移渐变到可见）。
       默认 350ms 只能拍到渐变中途 —— 抓到的是半透明的卡片和一片空白，
       很容易被当成排版塌了。用 WAIT 调长（如 WAIT=1200）可拍到稳定态。 */
    await sleep(parseInt(process.env.WAIT || '350', 10));

    const { data } = await browser.send('Page.captureScreenshot', { format: 'png' }, sessionId);
    const file = path.join(OUT, `clip-${NAME}-${suffix}.png`);
    fs.writeFileSync(file, Buffer.from(data, 'base64'));
    console.log(`${suffix.padEnd(9)} y=${String(val).padEnd(8)} → ${path.relative(ROOT, file)}`);
  }

  child.kill();
  await sleep(200);
  fs.rmSync(profile, { recursive: true, force: true });
}

main().catch((e) => {
  console.error('截图失败：', e.message);
  process.exit(1);
});
