#!/usr/bin/env node
/* ==========================================================================
   UI 审计 · 量化「看起来不对」的界面问题
   --------------------------------------------------------------------------
   截图能看出「空」「挤」「不齐」，但说不出差多少。这支脚本在页面里量：
     1) 对比度：正文 < 4.5:1 / 大字 < 3:1（解析真实背景，逐层合成半透明色）
     2) 裁切：overflow:hidden 但内容溢出，或 nowrap 被切掉
     3) 触控目标：独立按钮/链接小于 28px（移动端 36px）
     4) 重复 CTA：同文字同 href 的按钮在一页出现多次
     5) 卡片底部悬空：卡片内最后一个元素下方留白 > 36px（内容短就露馅）
     6) 行宽：正文字符数 > 42（中文行长上限）
     7) 字号：可见文本 < 11px

   用法：
     TARGET=library node tools/ui-audit.cjs
     TARGET=admin ADMIN_PASS=xxx node tools/ui-audit.cjs
     TARGET=site  node tools/ui-audit.cjs
     TARGET=library WIDTHS=1440 PAGES=home,works node tools/ui-audit.cjs

   输出：.build/ui-audit-<target>.json 与控制台摘要
   ========================================================================== */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, '.build');
const TARGET = (process.env.TARGET || 'library').toLowerCase();
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const SITE_PORT = parseInt(process.env.SITE_PORT || '8123', 10);
const DEBUG_PORT = parseInt(process.env.DEBUG_PORT || '9336', 10);
const WIDTHS = (process.env.WIDTHS || '1440,390').split(',').map((s) => parseInt(s.trim(), 10));
const HEIGHT = parseInt(process.env.HEIGHT || '900', 10);
// 不设默认账号：默认值 'admin' 会让审计脚本在没人注意时直接拿主账号登录生产后台。
// 审计账号必须是专用的、可随时停用的一个（例如 ui_audit），并且只在这条命令里出现。
const USER = process.env.ADMIN_USER || '';
const PASS = process.env.ADMIN_PASS || '';

const EDGE_CANDIDATES = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
];

function findEdge() {
  for (const p of EDGE_CANDIDATES) if (fs.existsSync(p)) return p;
  throw new Error('找不到 Microsoft Edge');
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function httpJson(url, method = 'GET') {
  return new Promise((resolve, reject) => {
    const req = http.request(url, { method }, (res) => {
      let body = '';
      res.on('data', (c) => { body += c; });
      res.on('end', () => {
        try { resolve(JSON.parse(body)); } catch { reject(new Error('非 JSON: ' + body.slice(0, 80))); }
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
    } catch { /* 未就绪 */ }
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
        if (this.pending.has(id)) { this.pending.delete(id); reject(new Error('CDP 超时: ' + method)); }
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

/* ------------------------------------------------------------------ 页面内审计 */
const AUDIT_JS = String.raw`(() => {
  const issues = [];
  const de = document.documentElement;
  const VW = de.clientWidth;
  const isMobile = VW < 620;

  const sel = (el) => {
    const parts = [];
    let n = el;
    for (let i = 0; i < 3 && n && n.nodeType === 1; i++) {
      let s = n.tagName.toLowerCase();
      if (n.id) { parts.unshift(s + '#' + n.id); break; }
      const cls = (typeof n.className === 'string' ? n.className : '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
      if (cls.length) s += '.' + cls.join('.');
      parts.unshift(s);
      n = n.parentElement;
    }
    return parts.join('>');
  };

  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) return false;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.15) return false;
    return true;
  };

  const parseColor = (str) => {
    const m = String(str || '').match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(/[,\s\/]+/).filter(Boolean).map(parseFloat);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  };
  const blend = (fg, bg) => ({
    r: fg.r * fg.a + bg.r * (1 - fg.a),
    g: fg.g * fg.a + bg.g * (1 - fg.a),
    b: fg.b * fg.a + bg.b * (1 - fg.a),
    a: 1
  });
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
  };
  const ratio = (a, b) => {
    const l1 = lum(a), l2 = lum(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  };

  const rootBg = (() => {
    let c = parseColor(getComputedStyle(de).backgroundColor);
    if (!c || c.a < 1) c = parseColor(getComputedStyle(document.body).backgroundColor);
    if (!c || c.a < 1) c = { r: 255, g: 255, b: 255, a: 1 };
    return c;
  })();

  const backdropOf = (el) => {
    const stack = [];
    let n = el;
    while (n && n.nodeType === 1) {
      if (n === document.documentElement) break;
      const c = parseColor(getComputedStyle(n).backgroundColor);
      if (c && c.a > 0) stack.push(c);
      if (c && c.a >= 1) break;
      n = n.parentElement;
    }
    let bg = rootBg;
    for (let i = stack.length - 1; i >= 0; i--) bg = blend(stack[i], bg);
    return bg;
  };

  // ---- 1) 对比度 ----
  const lowContrast = [];
  const tinyText = [];
  const all = Array.from(document.querySelectorAll('body *'));
  for (const el of all) {
    if (el.closest('.bg-overlay, .bg, .stars, canvas, svg')) continue;
    if (el.closest('[aria-hidden="true"]')) continue;
    const own = Array.from(el.childNodes)
      .filter((n) => n.nodeType === 3)
      .map((n) => n.textContent.trim())
      .join('');
    if (!own) continue;
    if (!visible(el)) continue;

    const cs = getComputedStyle(el);
    const size = parseFloat(cs.fontSize);
    if (size < 11) tinyText.push({ el: sel(el), size: Math.round(size * 10) / 10, text: own.slice(0, 16) });

    const fgRaw = parseColor(cs.color);
    if (!fgRaw || fgRaw.a < 0.05) continue;
    // 文字自身的 alpha 与背景合成后再算
    const bg = backdropOf(el);
    const fg = fgRaw.a < 1 ? blend(fgRaw, bg) : fgRaw;
    const r = ratio(fg, bg);
    const large = size >= 24 || (size >= 18.66 && (cs.fontWeight === 'bold' || parseInt(cs.fontWeight, 10) >= 700));
    const need = large ? 3 : 4.5;
    if (r < need) {
      lowContrast.push({
        el: sel(el),
        ratio: Math.round(r * 100) / 100,
        need,
        size: Math.round(size),
        color: cs.color,
        bg: 'rgb(' + Math.round(bg.r) + ',' + Math.round(bg.g) + ',' + Math.round(bg.b) + ')',
        text: own.slice(0, 24)
      });
    }
  }
  if (lowContrast.length) {
    lowContrast.sort((a, b) => a.ratio - b.ratio);
    issues.push({ type: 'contrast', sev: lowContrast.some((x) => x.ratio < 3) ? 'P0' : 'P1', n: lowContrast.length, items: lowContrast.slice(0, 12) });
  }
  if (tinyText.length) issues.push({ type: 'tiny', sev: 'P1', n: tinyText.length, items: tinyText.slice(0, 8) });

  // ---- 2) 裁切 ----
  const clipped = [];
  for (const el of all) {
    if (!visible(el)) continue;
    const cs = getComputedStyle(el);
    const oy = cs.overflowY, ox = cs.overflowX;
    const hiddenY = oy === 'hidden' || oy === 'clip';
    const hiddenX = ox === 'hidden' || ox === 'clip';
    if (hiddenY && el.clientHeight > 0 && el.scrollHeight - el.clientHeight > 2) {
      clipped.push({ el: sel(el), kind: 'Y', over: el.scrollHeight - el.clientHeight, text: (el.innerText || '').trim().slice(0, 20) });
    } else if (hiddenX && cs.whiteSpace === 'nowrap' && el.scrollWidth - el.clientWidth > 2) {
      clipped.push({ el: sel(el), kind: 'X', over: el.scrollWidth - el.clientWidth, text: (el.innerText || '').trim().slice(0, 20) });
    }
  }
  if (clipped.length) issues.push({ type: 'clip', sev: 'P0', n: clipped.length, items: clipped.slice(0, 10) });

  // ---- 3) 触控目标 ----
  // 阈值按 WCAG 2.2 SC 2.5.8（最小 24×24）取，移动端再留一点余量。
  // 一开始按 36 卡，把 32px 的 chip 云全报了 —— 那不是缺陷，是密度。
  const minSide = isMobile ? 28 : 24;
  const smallTargets = [];
  for (const el of document.querySelectorAll('a[href], button, summary, [role="button"], input[type="submit"]')) {
    if (!visible(el)) continue;
    if (el.closest('.footer, .site-footer')) continue;
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    // 纯行内文本链接不算按钮，只要行高够读就行
    if (cs.display === 'inline' && !cs.borderTopWidth.match(/[1-9]/) && cs.backgroundColor === 'rgba(0, 0, 0, 0)') continue;
    if (r.height < minSide || r.width < minSide) {
      smallTargets.push({ el: sel(el), w: Math.round(r.width), h: Math.round(r.height), text: (el.innerText || '').trim().slice(0, 14) });
    }
  }
  if (smallTargets.length) issues.push({ type: 'target', sev: 'P1', n: smallTargets.length, items: smallTargets.slice(0, 10) });

  // ---- 4) 重复 CTA ----
  const cta = new Map();
  for (const el of document.querySelectorAll('a[href], button')) {
    if (!visible(el)) continue;
    const t = (el.innerText || '').trim();
    if (!t || t.length > 14) continue;
    const key = t + '|' + (el.getAttribute('href') || '');
    cta.set(key, (cta.get(key) || 0) + 1);
  }
  const dup = [...cta.entries()].filter(([, n]) => n > 1).map(([k, n]) => ({ text: k.split('|')[0], n }));
  if (dup.length) issues.push({ type: 'dupCta', sev: 'P2', n: dup.length, items: dup.slice(0, 8) });

  // ---- 5) 卡片底部悬空 ----
  const hollow = [];
  for (const card of document.querySelectorAll('.card, .wcard, .work-card, .term-card, .kpi, .stat, .res__item')) {
    if (!visible(card)) continue;
    const kids = Array.from(card.children).filter(visible);
    if (!kids.length) continue;
    const cs = getComputedStyle(card);
    const cb = card.getBoundingClientRect();
    const lb = kids[kids.length - 1].getBoundingClientRect();
    const gap = Math.round(cb.bottom - parseFloat(cs.paddingBottom || 0) - lb.bottom);
    if (gap > 36) hollow.push({ el: sel(card), gap, h: Math.round(cb.height) });
  }
  if (hollow.length) issues.push({ type: 'hollow', sev: 'P2', n: hollow.length, items: hollow.slice(0, 10) });

  // ---- 6) 行宽 ----
  const wide = [];
  for (const p of document.querySelectorAll('p, .lead, .prose p')) {
    if (!visible(p)) continue;
    const cs = getComputedStyle(p);
    const size = parseFloat(cs.fontSize);
    const cpl = Math.round(p.clientWidth / size);
    if (cpl > 42 && (p.innerText || '').trim().length > 40) {
      wide.push({ el: sel(p), cpl, size: Math.round(size) });
    }
  }
  if (wide.length) issues.push({ type: 'lineWidth', sev: 'P2', n: wide.length, items: wide.slice(0, 6) });

  return { height: de.scrollHeight, width: VW, overflow: de.scrollWidth - de.clientWidth, issues };
})()`;

/* ------------------------------------------------------------------ 工具 */
function httpRequest(url, opts = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const req = http.request({
      hostname: u.hostname,
      port: u.port,
      path: u.pathname + u.search,
      method: opts.method || 'GET',
      headers: opts.headers || {}
    }, (res) => {
      let body = '';
      res.on('data', (c) => { body += c; });
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
    });
    req.on('error', reject);
    if (opts.body) req.write(opts.body);
    req.end();
  });
}

/* ------------------------------------------------------------------ 页面清单 */
async function buildPlan() {
  if (TARGET === 'site') {
    const pages = (process.env.PAGES || 'index,about,community,tools,library,contact').split(',').map((s) => s.trim());
    const plan = {};
    for (const p of pages) plan[p] = `http://127.0.0.1:${SITE_PORT}/${p}.html`;
    return { plan, cookie: '' };
  }

  if (TARGET === 'admin') {
    // 只列路径，不在这里带 cookie 去抓作品编号 —— 后台的会话必须在**浏览器里**建立
    // （见主流程里的说明），所以 work / analysis 两个页面是登录之后在页面里补进来的。
    // 早先这里写的是 /admin/new、/admin/documents、/admin/authors，三条都不存在，
    // 审计看到的是 404 页却记成「无问题」。
    return {
      plan: {
        dashboard: '/admin',
        works: '/admin/works',
        new: '/admin/works/new',
        progress: '/admin/progress',
        queue: '/admin/queue',
        terms: '/admin/terms',
        source: '/admin/source',
        'source-activity': '/admin/source/activity',
        hidden: '/admin/hidden',
        audit: '/admin/audit'
      },
      cookie: ''
    };
  }

  // library
  const pick = async (p, re, n) => {
    try {
      const html = await (await fetch(BASE + p)).text();
      return [...new Set([...html.matchAll(re)].map((m) => m[1]))].slice(0, n);
    } catch { return []; }
  };
  const workCode = (await pick('/library/works', /\/library\/works\/([0-9A-Z]{20,32})/g, 1))[0] || '';
  const termId = (await pick('/library/terms', /\/library\/terms\/(\d+)/g, 1))[0] || '';
  const issueNo = (await pick('/library', /\/library\/issues\/(\d+)/g, 1))[0] || '25';
  let authorCode = '';
  if (workCode) {
    const html = await (await fetch(`${BASE}/library/works/${workCode}`)).text().catch(() => '');
    authorCode = (html.match(/\/library\/authors\/([0-9A-Z]{20,32})/) || [])[1] || '';
  }

  const plan = {
    home: '/library',
    works: '/library/works',
    'works-sorted': '/library/works?sort=words',
    terms: '/library/terms',
    method: '/library/method',
    search: `/library/search?q=${encodeURIComponent(process.env.QUERY || '时间旅行')}`,
    'search-empty': '/library/search?q=zzz%E4%B8%8D%E5%AD%98%E5%9C%A8',
    issue: `/library/issues/${issueNo}`,
    notfound: '/library/works/NOTEXIST'
  };
  if (workCode) {
    plan.work = `/library/works/${workCode}`;
    plan['work-author'] = `/library/works/${workCode}/author`;
  }
  if (termId) plan.term = `/library/terms/${termId}`;
  if (authorCode) plan.author = `/library/authors/${authorCode}`;
  return { plan, cookie: '' };
}

/* ------------------------------------------------------------------ 静态服务器 */
function serveStatic() {
  const MIME = {
    '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg',
    '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.ico': 'image/x-icon', '.json': 'application/json'
  };
  const server = http.createServer((req, res) => {
    const urlPath = decodeURIComponent(req.url.split('?')[0]);
    let file = path.join(ROOT, urlPath === '/' ? 'index.html' : urlPath);
    if (!file.startsWith(ROOT) || file.includes('server' + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (err, data) => {
      if (err) { res.writeHead(404).end('404'); return; }
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' });
      res.end(data);
    });
  });
  return new Promise((resolve) => server.listen(SITE_PORT, '127.0.0.1', () => resolve(server)));
}

/* ------------------------------------------------------------------ 主流程 */
async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  const { plan, cookie } = await buildPlan();
  const shots = (process.env.PAGES || Object.keys(plan).join(',')).split(',').map((s) => s.trim()).filter(Boolean);

  let siteServer = null;
  if (TARGET === 'site') siteServer = await serveStatic();

  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-ui-audit-'));
  const edge = findEdge();
  const maxWidth = Math.max(...WIDTHS);
  const child = spawn(edge, [
    '--headless=new',
    `--remote-debugging-port=${DEBUG_PORT}`,
    `--user-data-dir=${profile}`,
    '--no-first-run',
    '--disable-gpu',
    '--hide-scrollbars',
    `--window-size=${maxWidth},${HEIGHT}`,
    'about:blank'
  ], { stdio: 'ignore' });

  const cleanup = () => {
    try { child.kill(); } catch { /* ignore */ }
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* ignore */ }
    if (siteServer) { try { siteServer.close(); } catch { /* ignore */ } }
  };
  process.on('exit', cleanup);
  process.on('SIGTERM', () => { cleanup(); process.exit(143); });
  process.on('SIGINT', () => { cleanup(); process.exit(130); });

  const browserWs = await waitForDevtools();
  const browser = await connect(browserWs);
  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await browser.send('Target.attachToTarget', { targetId, flatten: true });
  const session = {
    send: (m, p) => browser.send(m, p, sessionId),
    on: (m, fn) => { const l = browser.events.get(m) || []; l.push(fn); browser.events.set(m, l); },
    once: (m) => new Promise((resolve) => {
      const l = browser.events.get(m) || [];
      const h = (p) => { const i = l.indexOf(h); if (i >= 0) l.splice(i, 1); resolve(p); };
      l.push(h); browser.events.set(m, l);
    })
  };

  let contextId = null;
  session.on('Runtime.executionContextsCleared', () => { contextId = null; });
  session.on('Runtime.executionContextCreated', (p) => {
    if (p?.context?.auxData?.isDefault) contextId = p.context.id;
  });

  const evaluate = async (expression) => {
    const call = (withContext) => session.send('Runtime.evaluate', {
      expression, returnByValue: true, awaitPromise: true,
      ...(withContext && contextId ? { contextId } : {})
    });
    let r;
    try { r = await call(true); } catch { contextId = null; r = await call(false); }
    return r.result ? r.result.value : undefined;
  };

  await session.send('Page.enable');
  await session.send('Runtime.enable');

  // 前台有夜间/日间两套主题，theme.js 只读 localStorage。
  // 不种这个值，永远只审到夜间那一套。
  const THEME = (process.env.THEME || '').toLowerCase();
  if (THEME === 'day' || THEME === 'night') {
    await session.send('Page.addScriptToEvaluateOnNewDocument', {
      source: `try { localStorage.setItem('hq-theme', ${JSON.stringify(THEME)}); } catch (e) {}`
    });
  }

  if (cookie) {
    // 用 Network.setCookie 逐条种，而不是 setExtraHTTPHeaders 塞一个 Cookie 头：
    // 后者只在「发请求」这一层生效，页面里后续的子资源与二次导航各有一份自己的
    // 状态；而且这套写法与 tools/lib/browser.cjs 保持一致，两处不再各有一套登录。
    await session.send('Network.enable');
    for (const kv of String(cookie).split('; ').filter(Boolean)) {
      const idx = kv.indexOf('=');
      await session.send('Network.setCookie', {
        name: kv.slice(0, idx), value: kv.slice(idx + 1), domain: '127.0.0.1', path: '/'
      });
    }
  }

  /*
   * 后台登录改在页面里做。
   *
   * 起因是个更难看的坑：这支脚本的默认账号是 admin，而当时传进来的口令是
   * ui_audit 的 —— 登录返回 422，之后的每一页都 302 到 /admin/login，
   * 而登录页高度刚好等于视口、也没有可量的缺陷，脚本于是逐页打印「ok」，
   * 汇总「无问题」。**一分钱证据都没有，还看不出是假的。**
   *
   * 所以这里做两件事：
   *   1) 在页面里填表提交 —— 失败会立刻读到「用户名或密码不正确」，当场抛错；
   *   2) 顺带用页面自己的 fetch 找「有分析结果的作品」，不再把 cookie 在
   *      Node 与浏览器之间搬来搬去。跑之前记得 ADMIN_USER=ui_audit。
   */
  if (TARGET === 'admin') {
    if (!USER || !PASS) {
      throw new Error('后台审计必须显式给 ADMIN_USER / ADMIN_PASS：没有默认账号，别拿主账号跑审计。');
    }
    await session.send('Network.enable');
    const loaded = session.once('Page.loadEventFired');
    await session.send('Page.navigate', { url: BASE + '/admin/login' });
    await loaded;

    await evaluate(`(function () {
      var u = document.querySelector('#username');
      var p = document.querySelector('#password');
      if (!u || !p) { return 'no-form'; }
      u.value = ${JSON.stringify(USER)};
      p.value = ${JSON.stringify(PASS)};
      document.querySelector('form[method="post"]').submit();
      return 'submitted';
    })()`);
    await sleep(1500);

    const landed = await evaluate('location.pathname');
    if (landed === '/admin/login') {
      const why = await evaluate('(document.querySelector(".flash")||{}).textContent||"（无提示）"');
      throw new Error('页面内登录失败，仍停在登录页：' + why);
    }
    console.log('后台登录：已进入', landed);

    // 同源 fetch：找一个「有分析结果」的作品，补两个最长的页面进审计清单
    const found = await evaluate(`(async function () {
      var html = await (await fetch('/admin/works')).text();
      var codes = Array.from(new Set(Array.from(html.matchAll(/href="\\/admin\\/works\\/([0-9A-HJKMNP-TV-Z]{26})"/g)).map(function (m) { return m[1]; }))).slice(0, 12);
      for (var i = 0; i < codes.length; i++) {
        var d = await (await fetch('/admin/works/' + codes[i])).text();
        var a = d.match(/href="\\/admin\\/analyses\\/([0-9A-HJKMNP-TV-Z]{26})"/);
        if (a) { return { work: codes[i], analysis: a[1] }; }
      }
      return { work: codes[0] || '', analysis: '' };
    })()`);

    if (found && found.work) {
      plan.work = '/admin/works/' + found.work;
      if (!shots.includes('work')) shots.push('work');
    }
    if (found && found.analysis) {
      plan.analysis = '/admin/analyses/' + found.analysis;
      if (!shots.includes('analysis')) shots.push('analysis');
    }
    console.log('后台取样：作品', (found && found.work) || '（无）', '· 分析', (found && found.analysis) || '（无）');
  }

  const report = { target: TARGET, when: new Date().toISOString(), widths: WIDTHS, pages: [] };

  for (const width of WIDTHS) {
    await session.send('Emulation.setDeviceMetricsOverride', {
      width, height: HEIGHT, deviceScaleFactor: 1, mobile: width < 620
    });

    for (const name of shots) {
      const url = plan[name];
      if (!url) continue;
      const full = /^https?:/i.test(url) ? url : BASE + url;
      const loaded = session.once('Page.loadEventFired');
      await session.send('Page.navigate', { url: full });
      await loaded;
      await sleep(name === 'progress' ? 1500 : 400);

      const info = await evaluate(AUDIT_JS);
      if (!info) { console.warn('审计无返回：', name); continue; }
      report.pages.push({ name, width, ...info });
      const tag = info.issues.length ? info.issues.map((i) => `${i.type}:${i.n}`).join(' ') : 'ok';
      // 页面高度正好等于视口高有两种可能：这页本来就短（新建表单），
      // 或者审计看到的根本不是那一页（登录页 / 404 / 内容没渲染）。
      // 后者会让「无问题」变成假证据，所以只在这种情况吭声。
      if (info.height <= HEIGHT + 2) {
        const where = await evaluate('JSON.stringify({url: location.pathname + location.search, title: document.title})');
        if (/login|不存在|登录/.test(String(where))) {
          console.warn(`  ! ${name}@${width} 打开的是登录页或错误页：${where}`);
        }
      }
    }
  }

  const file = path.join(OUT, `ui-audit-${TARGET}.json`);
  fs.writeFileSync(file, JSON.stringify(report, null, 2), 'utf8');

  const totals = {};
  for (const p of report.pages) for (const i of p.issues) totals[i.type] = (totals[i.type] || 0) + i.n;
  console.log('\n汇总：', Object.entries(totals).map(([k, v]) => `${k}=${v}`).join(' · ') || '无问题');
  console.log('报告：', path.relative(ROOT, file));
  process.exit(0);
}

main().catch((e) => { console.error(e); process.exit(1); });
