#!/usr/bin/env node
/* ==========================================================================
   银河图书馆后台 · 视觉验收截图
   --------------------------------------------------------------------------
   零依赖：Node 内置 WebSocket + 系统自带 Microsoft Edge 的 CDP。
   与静态站那支 shoot.cjs 的区别：这里不启静态服务器（动态站已跑在 8090），
   并且要**先登录**才能截到后台页面——登录用页面内 fetch，cookie 由浏览器自己管。

   用法：
     node tools/shoot-admin.cjs
     ADMIN_USER=admin ADMIN_PASS=xxx node tools/shoot-admin.cjs
     PAGES=login,dashboard ADMIN_PASS=xxx node tools/shoot-admin.cjs

   输出：.build/shots-admin/<页面>.png
   ========================================================================== */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, '.build', 'shots-admin');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const DEBUG_PORT = parseInt(process.env.DEBUG_PORT || '9334', 10);
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || '';
const WIDTH = parseInt(process.env.WIDTH || '1440', 10);
const HEIGHT = parseInt(process.env.HEIGHT || '900', 10);

const EDGE_CANDIDATES = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
];

function findEdge() {
  for (const p of EDGE_CANDIDATES) if (fs.existsSync(p)) return p;
  throw new Error('找不到 Microsoft Edge');
}

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

function httpJson(url, method = 'GET') {
  return new Promise((resolve, reject) => {
    const req = http.request(url, { method }, (res) => {
      let body = '';
      res.on('data', (c) => { body += c; });
      res.on('end', () => {
        try { resolve(JSON.parse(body)); } catch (e) { reject(new Error('非 JSON 响应: ' + body.slice(0, 120))); }
      });
    });
    req.on('error', reject);
    req.end();
  });
}

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

  /** 持久监听（不像 once 那样用完就删），用于跟踪 executionContext。 */
  on(method, fn) {
    const list = this.events.get(method) || [];
    list.push(fn);
    this.events.set(method, list);
  }
}

// ---- 用 Node 自己的 http 拿一次登录态，只为从列表页 HTML 里抠出一个作品 code ----
// （比在浏览器里 evaluate 更稳：不受 execution context 随导航重建的影响）
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

async function fetchDetailCode(cookie) {
  // 想看某一篇的具体页面（例如只有它有第二个版本）时用 WORK_CODE 指定
  if (process.env.WORK_CODE) {
    return process.env.WORK_CODE.trim();
  }

  const list = await httpRequest(`${BASE}/admin/works`, { headers: { Cookie: cookie } });
  const hit = list.body.match(/href="\/admin\/works\/([0-9A-HJKMNP-TV-Z]{26})"/);

  return hit ? hit[1] : '';
}

/** 列表页里所有作品 code（去重，按页面顺序）。 */
async function fetchWorkCodes(cookie) {
  const list = await httpRequest(`${BASE}/admin/works`, { headers: { Cookie: cookie } });
  const codes = [...list.body.matchAll(/href="\/admin\/works\/([0-9A-HJKMNP-TV-Z]{26})"/g)].map((m) => m[1]);

  return [...new Set(codes)];
}

/**
 * 找一条「已经跑过分析」的作品，返回 [workCode, analysisCode]。
 *
 * 不能只取列表第一行：最新导入的那篇通常还没跑过分析，用它截分析结果页会落空。
 */
async function findAnalysisTarget(cookie) {
  for (const workCode of await fetchWorkCodes(cookie)) {
    const page = await httpRequest(`${BASE}/admin/works/${workCode}`, { headers: { Cookie: cookie } });
    const hit = page.body.match(/href="\/admin\/analyses\/([0-9A-HJKMNP-TV-Z]{26})"/);
    if (hit) {
      return [workCode, hit[1]];
    }
  }

  return ['', ''];
}

/** 用 Node 的 HTTP 会话登录，返回可直接注入浏览器的 Cookie 头（含 PHPSESSID 那一条）。 */
async function loginViaHttp() {
  const loginPage = await httpRequest(`${BASE}/admin/login`);
  let cookie = cookieOf(loginPage);

  const tokenMatch = loginPage.body.match(/name="_token" value="([a-f0-9]+)"/);
  if (!tokenMatch) throw new Error('登录页里找不到 CSRF token');

  const payload = new URLSearchParams({
    _token: tokenMatch[1],
    username: USER,
    password: PASS
  }).toString();

  const res = await httpRequest(`${BASE}/admin/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Content-Length': Buffer.byteLength(payload),
      Cookie: cookie
    },
    body: payload
  });

  const fresh = cookieOf(res);
  if (fresh) cookie = fresh;

  return cookie;
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
  if (!PASS) {
    console.error('缺少 ADMIN_PASS（后台口令）。示例：ADMIN_PASS=xxx node tools/shoot-admin.cjs');
    process.exit(1);
  }

  fs.mkdirSync(OUT, { recursive: true });

  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-admin-shot-'));
  const edge = findEdge();

  const child = spawn(edge, [
    '--headless=new',
    `--remote-debugging-port=${DEBUG_PORT}`,
    `--user-data-dir=${profile}`,
    '--no-first-run',
    '--disable-gpu',
    '--hide-scrollbars',
    `--window-size=${WIDTH},${HEIGHT}`,
    'about:blank'
  ], { stdio: 'ignore' });

  const cleanup = () => {
    try { child.kill(); } catch { /* ignore */ }
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* ignore */ }
  };
  process.on('exit', cleanup);

  const browserWs = await waitForDevtools();
  const browser = await connect(browserWs);

  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await browser.send('Target.attachToTarget', { targetId, flatten: true });

  // flatten 模式下 sessionId 必须与 id/method **同级**，塞进 params 会被当成未知参数
  const session = {
    send: (method, params = {}) => browser.send(method, params, sessionId),
    once: (method) => browser.once(method),
    on: (method, fn) => browser.on(method, fn)
  };

  // 每次导航都会重建 execution context；evaluate 必须显式指定新的 contextId，
  // 否则默认落在旧文档上（踩过：导航到列表页却读到了登录页的 DOM，一个 <a> 都没有）
  let contextId = null;
  session.on('Runtime.executionContextsCleared', () => {
    contextId = null;
  });
  session.on('Runtime.executionContextCreated', (params) => {
    if (params?.context?.auxData?.isDefault) {
      contextId = params.context.id;
    }
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
      // context 已被导航销毁：回退到默认 context 再试一次
      contextId = null;
      r = await call(false);
    }

    return r.result ? r.result.value : undefined;
  };

  await session.send('Page.enable');
  await session.send('Runtime.enable');
  await session.send('Emulation.setDeviceMetricsOverride', {
    width: WIDTH, height: HEIGHT, deviceScaleFactor: 1, mobile: false
  });

  // ---- 登录：Node 用 HTTP 完成登录，把会话 cookie 注入浏览器 ----
  // 为什么不在页面里 fetch：那样浏览器自持一份会话，脚本里再用 HTTP 登录又会生成另一份，
  // 两边各自 regenerate 会话，很容易把对方挤掉（踩过：works 能截、detail 掉回登录页）。
  await session.send('Network.enable');

  const injectCookies = async (cookieHeader) => {
    const pairs = cookieHeader.split('; ').filter(Boolean).map((kv) => {
      const idx = kv.indexOf('=');
      return { name: kv.slice(0, idx), value: kv.slice(idx + 1) };
    });
    for (const c of pairs) {
      await session.send('Network.setCookie', { ...c, domain: '127.0.0.1', path: '/' });
    }
    return pairs.map((c) => c.name).join(',');
  };

  const cookieHeader = await loginViaHttp();
  const injected = await injectCookies(cookieHeader);
  console.log('登录：已注入会话 cookie →', injected);

  const shots = (process.env.PAGES || 'login,dashboard,works,new,detail,analysis,versions,diff').split(',').map((s) => s.trim());

  let detailCode = '';
  let analysisCode = '';
  let analysisWorkCode = '';

  if (shots.includes('detail') || shots.includes('analysis') || shots.includes('versions') || shots.includes('diff')) {
    detailCode = await fetchDetailCode(cookieHeader);
    console.log('作品 code：', detailCode || '（未取到）');
  }

  if (shots.includes('analysis')) {
    if (process.env.ANALYSIS_CODE) {
      analysisCode = process.env.ANALYSIS_CODE.trim();
      analysisWorkCode = detailCode;
      console.log('分析 code（指定）：', analysisCode);
    } else {
      [analysisWorkCode, analysisCode] = await findAnalysisTarget(cookieHeader);
      console.log('分析 code：', analysisCode || '（所有作品都没跑过分析）');
    }
    if (!detailCode) detailCode = analysisWorkCode;
  }

  const plan = {
    login: '/admin/login',
    dashboard: '/admin',
    works: '/admin/works',
    new: '/admin/works/new',
    // 一键拆解与调度台：1300 篇的主操作面，版面回归必看这两页
    oneclick: '/admin/oneclick',
    queue: '/admin/queue',
    // 拆解实况：唯一一个「会自己变」的后台页。截图前要等第一次轮询回来，
    // 否则截到的是 Twig 首屏的初值，分段条还没被脚本填宽（看起来像坏了）。
    progress: '/admin/progress',
    detail: () => (detailCode ? `/admin/works/${detailCode}` : ''),
    analysis: () => (analysisCode ? `/admin/analyses/${analysisCode}` : ''),
    versions: () => (detailCode ? `/admin/works/${detailCode}/versions` : ''),
    // 默认比最新两版；只有一版时 diff 会 303 回版本页，这时截出来的是版本页本身
    diff: () => (detailCode ? `/admin/works/${detailCode}/diff` : '')
  };

  for (const name of shots) {
    if (name === 'detail' && !detailCode) continue;
    if (name === 'analysis' && !analysisCode) continue;

    const url = typeof plan[name] === 'function' ? plan[name]() : plan[name];
    if (!url) continue;

    if (name === 'login') {
      // 已登录状态下访问登录页会 302，这里先清 cookie
      await session.send('Network.enable');
      await session.send('Network.clearBrowserCookies');
    }

    const loaded = session.once('Page.loadEventFired');
    await session.send('Page.navigate', { url: BASE + url });
    await loaded;
    // 实况页的条子与柱子在首次轮询后才成形，多给一拍。
    // PROGRESS_WAIT 可以调大，用来验「第二次轮询之后」的差值小字有没有正常更新。
    await sleep(name === 'progress' ? parseInt(process.env.PROGRESS_WAIT || '1600', 10) : 400);

    if (name === 'works') {
      console.log('作品列表页已渲染');
    }

    const { data } = await session.send('Page.captureScreenshot', {
      format: 'png',
      captureBeyondViewport: true,
      clip: undefined
    });

    const file = path.join(OUT, `${name}-${WIDTH}.png`);
    fs.writeFileSync(file, Buffer.from(data, 'base64'));
    console.log('已截图', path.relative(ROOT, file));

    if (name === 'login') {
      // 截完登录页把会话重新注入，后续页面才看得到
      await injectCookies(cookieHeader);
    }
  }

  cleanup();
  console.log('完成，输出目录：', path.relative(ROOT, OUT));
}

main().catch((err) => {
  console.error('失败：', err.message);
  process.exit(1);
});
