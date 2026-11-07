#!/usr/bin/env node
/* ==========================================================================
   后台验收脚本共用的浏览器底座（零依赖：Node 内置 WebSocket + 系统 Edge 的 CDP）
   --------------------------------------------------------------------------
   抽出来的原因：shoot-admin / check-bulkbar / check-rerun 三支脚本各自复制一份
   启动 Edge、连 CDP、登录、注入 cookie 的代码，改一处就要改三处，
   而且「execution context 随导航重建」这种坑被复制后必然只在其中一支修好。

   导出：
     openAdmin({ pass, user, width, height, port }) -> { session, evaluate, goto, close, login }
   其中 evaluate 已经处理了 contextId 失效重试；goto 等 DOM 就绪。
   ========================================================================== */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');

const BASE = process.env.BASE || 'http://127.0.0.1:8090';

const EDGE_CANDIDATES = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
];

function findEdge() {
  for (const p of EDGE_CANDIDATES) {
    if (fs.existsSync(p)) return p;
  }
  throw new Error('找不到 Microsoft Edge');
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function httpJson(url) {
  return new Promise((resolve, reject) => {
    const req = http.request(url, (res) => {
      let body = '';
      res.on('data', (c) => { body += c; });
      res.on('end', () => {
        try { resolve(JSON.parse(body)); } catch { reject(new Error('非 JSON：' + body.slice(0, 120))); }
      });
    });
    req.on('error', reject);
    req.end();
  });
}

function httpRequest(url, { method = 'GET', headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const req = http.request({
      hostname: u.hostname,
      port: u.port || 80,
      path: u.pathname + u.search,
      method,
      headers
    }, (res) => {
      let data = '';
      res.on('data', (c) => { data += c; });
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
    });
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

function cookieOf(res) {
  const raw = res.headers['set-cookie'];
  if (!raw) return '';
  return (Array.isArray(raw) ? raw : [raw]).map((c) => c.split(';')[0]).join('; ');
}

async function waitForDevtools(port) {
  for (let i = 0; i < 60; i++) {
    try {
      const v = await httpJson(`http://127.0.0.1:${port}/json/version`);
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
        this.events.delete(msg.method);
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

  on(method, fn) {
    const list = this.events.get(method) || [];
    list.push(fn);
    this.events.set(method, list);
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

/** 用 Node 的 HTTP 完成登录，返回可直接注入浏览器的 Cookie 头。 */
async function loginViaHttp(user, pass) {
  const loginPage = await httpRequest(`${BASE}/admin/login`);
  let cookie = cookieOf(loginPage);
  const m = loginPage.body.match(/name="_token" value="([a-f0-9]+)"/);
  if (!m) throw new Error('登录页里找不到 CSRF token');

  const payload = new URLSearchParams({ _token: m[1], username: user, password: pass }).toString();
  const res = await httpRequest(`${BASE}/admin/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Content-Length': Buffer.byteLength(payload),
      Cookie: cookie
    },
    body: payload
  });

  return cookieOf(res) || cookie;
}

/**
 * 起一个无头 Edge、建标签页、登录并注入 cookie。
 * 调用方拿到 session/evaluate/goto 之后自己跑断言，结束时必须 await close()。
 */
async function openAdmin({
  user = process.env.ADMIN_USER || 'ui_audit',
  pass = process.env.ADMIN_PASS || '',
  width = 1440,
  height = 1000,
  // mobile 默认 false。**测窄屏断点必须传 true**：false 时经典滚动条占 15px，
  // WIDTHS 给 442 而 clientWidth 只有 427，量出来的是「下一档」的高度，
  // 边界测试会整档错位（本轮踩过）。
  mobile = false,
  port = parseInt(process.env.DEBUG_PORT || '9336', 10)
} = {}) {
  if (!pass) throw new Error('缺少 ADMIN_PASS');

  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-cdp-'));
  const child = spawn(findEdge(), [
    '--headless=new',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`,
    '--no-first-run',
    '--disable-gpu',
    `--window-size=${width},${height}`,
    'about:blank'
  ], { stdio: 'ignore' });

  let closed = false;
  const close = async () => {
    if (closed) return;
    closed = true;
    try { child.kill(); } catch { /* ignore */ }
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* ignore */ }
  };
  process.on('exit', () => { try { child.kill(); } catch { /* ignore */ } });

  const browser = await connect(await waitForDevtools(port));
  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await browser.send('Target.attachToTarget', { targetId, flatten: true });

  const session = {
    send: (m, p = {}) => browser.send(m, p, sessionId),
    once: (m) => browser.once(m),
    on: (m, f) => browser.on(m, f)
  };

  // 每次导航都会重建 execution context；evaluate 必须显式指定新的 contextId，
  // 否则默认落在旧文档上（踩过：导航到列表页却读到了登录页的 DOM）
  let contextId = null;
  session.on('Runtime.executionContextsCleared', () => { contextId = null; });
  session.on('Runtime.executionContextCreated', (params) => {
    if (params?.context?.auxData?.isDefault) contextId = params.context.id;
  });

  const evaluate = async (expression) => {
    const call = (withContext) => session.send('Runtime.evaluate', {
      expression,
      returnByValue: true,
      awaitPromise: true,
      ...(withContext && contextId ? { contextId } : {})
    });
    let r;
    try {
      r = await call(true);
    } catch {
      contextId = null;
      r = await call(false);
    }
    return r.result ? r.result.value : undefined;
  };

  await session.send('Page.enable');
  await session.send('Runtime.enable');
  await session.send('Network.enable');
  await session.send('Emulation.setDeviceMetricsOverride', {
    width, height, deviceScaleFactor: 1, mobile
  });

  const cookieHeader = await loginViaHttp(user, pass);
  const names = [];
  for (const kv of cookieHeader.split('; ').filter(Boolean)) {
    const idx = kv.indexOf('=');
    names.push(kv.slice(0, idx));
    await session.send('Network.setCookie', {
      name: kv.slice(0, idx), value: kv.slice(idx + 1), domain: '127.0.0.1', path: '/'
    });
  }

  /** 导航到某个后台路径并等指定选择器出现、且样式表就绪（最多 ~15s）。 */
  const goto = async (pathName, selector) => {
    await session.send('Page.navigate', { url: BASE + pathName });
    for (let i = 0; i < 60; i++) {
      await sleep(250);
      // 只等 DOM 会踩坑：选择器一出现就返回，此时样式表可能还没解析完，
      // getComputedStyle 读到的是浏览器默认值 —— 字号全读成 16px、--mono 读成空串、
      // ::before 读成 static，症状极像「CSS 没生效」，害人不浅（本轮踩过两次）。
      // 所以「就绪」必须同时满足：目标元素在 + readyState complete + 至少一张样式表。
      const ok = await evaluate(
        `(function () {
          if (!document.querySelector(${JSON.stringify(selector)})) return false;
          return document.readyState === 'complete' && document.styleSheets.length > 0;
        })()`
      );
      if (ok) return true;
    }
    return false;
  };

  /**
   * 截一张图落地。不给 selector 就是整页；给了就只截那个元素的外框
   * （外扩 pad 像素，免得把边框和投影切掉）。
   * 长页截图一律建议给 selector —— 9000px 的整页图在预览里什么也看不清。
   */
  const screenshot = async (outPath, { selector = null, pad = 8 } = {}) => {
    let clip;
    if (selector) {
      const box = await evaluate(`(function () {
        var el = document.querySelector(${JSON.stringify(selector)});
        if (!el) return null;
        var r = el.getBoundingClientRect();
        return { x: r.left + window.scrollX, y: r.top + window.scrollY, w: r.width, h: r.height };
      })()`);
      if (!box) throw new Error('截图目标不存在：' + selector);
      clip = {
        x: Math.max(0, box.x - pad),
        y: Math.max(0, box.y - pad),
        width: Math.min(width, box.w + pad * 2),
        height: box.h + pad * 2,
        scale: 1
      };
      await session.send('Emulation.setDeviceMetricsOverride', {
        width, height: Math.ceil(Math.max(height, clip.y + clip.height + 20)), deviceScaleFactor: 1, mobile: false
      });
    }

    const shot = await session.send('Page.captureScreenshot', {
      format: 'png',
      ...(clip ? { clip, captureBeyondViewport: true } : { captureBeyondViewport: true })
    });
    fs.writeFileSync(outPath, Buffer.from(shot.data, 'base64'));
    return outPath;
  };

  return { session, evaluate, goto, screenshot, close, cookies: names, base: BASE };
}

module.exports = { openAdmin, httpRequest, cookieOf, sleep, BASE };
