#!/usr/bin/env node
/* ==========================================================================
   银河图书馆前台 · 视觉验收截图 + 版面审计
   --------------------------------------------------------------------------
   与 shoot-admin.cjs 的区别：
     · 前台公开页**不需要登录**（GET /library/* 不启 session），所以没有 cookie 流程；
     · 除了 PNG，还顺手在页面里跑一遍审计脚本（禁渐变 / 禁 emoji / 横向溢出 /
       控制台报错 / 权重条与节点轴是否渲染出来），结果落 .build/shots-library/audit.json。

   用法：
     node tools/shoot-library.cjs                     # 1440 + 390 两档，全部页面
     WIDTHS=1440 node tools/shoot-library.cjs          # 只要宽屏
     PAGES=home,work node tools/shoot-library.cjs      # 只截几页
     WORK_CODE=01M6... TERM_IDS=2,170 node tools/shoot-library.cjs

   输出：.build/shots-library/<页面>-<宽度>.png 与 audit.json
   ========================================================================== */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, '.build', 'shots-library');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const DEBUG_PORT = parseInt(process.env.DEBUG_PORT || '9335', 10);
const WIDTHS = (process.env.WIDTHS || '1440,390').split(',').map((s) => parseInt(s.trim(), 10));
const HEIGHT = parseInt(process.env.HEIGHT || '900', 10);

const EDGE_CANDIDATES = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
];

/** 预期就返回 4xx 的页面：浏览器报的那条 404 不算缺陷。 */
const EXPECT_4XX = new Set(['notfound']);

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

  /** 持久监听，用于收集控制台报错（不能用 once，会漏）。 */
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

/* --------------------------------------------------------------------------
   页面内审计脚本（字符串会被 evaluate 执行）
   --------------------------------------------------------------------------
   只在 DOM 上做只读检查，不改样式，因此截图内容不受影响。
   -------------------------------------------------------------------------- */
const AUDIT_JS = String.raw`(() => {
  const issues = [];
  const de = document.documentElement;
  const all = Array.from(document.querySelectorAll('*'));

  // 1) 渐变：computed background-image / mask-image 里出现 gradient 即违规（全站禁渐变）
  //    豁免 .bg-overlay 子树：这是与官网共用的背景装饰层（网格刻线、1px 星点、
  //    日间太阳的平涂光照带），那些 gradient 全是硬边/离散色阶，没有颜色过渡；
  //    官网同一套层也是这么画的。内容面（卡片/按钮/色块）照旧全禁。
  let gradients = [];
  for (const el of all) {
    if (el.closest('.bg-overlay')) continue;
    const cs = getComputedStyle(el);
    for (const prop of ['backgroundImage', 'maskImage', 'webkitMaskImage']) {
      const v = cs[prop] || '';
      if (v.includes('gradient')) {
        gradients.push(el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '') + ' :: ' + prop);
        break;
      }
    }
  }
  if (gradients.length) issues.push('疑似渐变 ' + gradients.length + ' 处：' + gradients.slice(0, 5).join(' | '));

  // 2) emoji：正文里出现 pictographic 区段即违规（图标必须自绘 SVG）
  //    字符集**必须与 tools/check-site.cjs 完全一致**——多收一个区间就会把
  //    文案里的排版箭头（U+2192 →）误判成 emoji。
  const text = document.body ? document.body.innerText : '';
  const emojiRe = /[\u{1F000}-\u{1FAFF}\u{1F1E6}-\u{1F1FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{FE0F}\u{2B00}-\u{2BFF}]/gu;
  const emoji = text.match(emojiRe) || [];
  if (emoji.length) issues.push('emoji/符号 ' + emoji.length + ' 个：' + [...new Set(emoji)].join(''));

  // 3) 横向溢出：scrollWidth 超过 clientWidth 一个像素以上
  const overflow = de.scrollWidth - de.clientWidth;
  if (overflow > 1) issues.push('横向溢出 ' + overflow + 'px');

  // 4) 权重条：原生 progress 有值域才算渲染出来
  const bars = Array.from(document.querySelectorAll('progress'));
  const barsNoValue = bars.filter((b) => !b.hasAttribute('value') || b.getAttribute('value') === '').length;

  // 5) 空链接与破锚点（页内 #xxx 找不到目标）
  const emptyHref = document.querySelectorAll('a[href=""], a:not([href])').length;
  const badAnchor = Array.from(document.querySelectorAll('a[href^="#"]'))
    .filter((a) => a.getAttribute('href').length > 1 && !document.getElementById(a.getAttribute('href').slice(1))).length;
  if (emptyHref) issues.push('空 href 链接 ' + emptyHref + ' 个');
  if (badAnchor) issues.push('失效页内锚点 ' + badAnchor + ' 个');

  // 6) 图片缺 alt
  const imgNoAlt = Array.from(document.querySelectorAll('img:not([alt])')).length;
  if (imgNoAlt) issues.push('img 缺 alt ' + imgNoAlt + ' 个');

  // 7) CSP 是 script-src 'self'，任何内联可执行脚本都会被拦下 —— 出现即为缺陷
  const inline = Array.from(document.scripts).filter((s) => {
    const t = (s.getAttribute('type') || '').toLowerCase();
    return !s.src && s.textContent.trim() && t !== 'application/ld+json';
  });
  if (inline.length) issues.push('内联脚本 ' + inline.length + ' 段（CSP 会拒绝执行）');

  // 8) JSON-LD 必须是合法 JSON，且不能出现未转义的 </script>
  const ldNodes = Array.from(document.querySelectorAll('script[type="application/ld+json"]'));
  let ldBad = 0;
  for (const n of ldNodes) {
    try { JSON.parse(n.textContent); } catch (e) { ldBad++; }
  }
  if (ldBad) issues.push('JSON-LD 解析失败 ' + ldBad + ' 段');

  return {
    title: document.title,
    theme: de.getAttribute('data-theme') || '',
    lang: de.getAttribute('lang') || '',
    height: de.scrollHeight,
    width: de.clientWidth,
    overflow,
    gradients: gradients.length,
    emoji: emoji.length,
    bars: bars.length,
    barsNoValue,
    marks: document.querySelectorAll('mark').length,
    nodes: document.querySelectorAll('.axis__node, .node, [class*="axis"] circle').length,
    cards: document.querySelectorAll('.card, .work-card, .term-card, .wcard').length,
    empties: document.querySelectorAll('.empty, .res__empty').length,
    // JSON-LD 是数据块（浏览器不执行），CSP script-src 也不管它，不算内联脚本
    scripts: Array.from(document.scripts).filter((s) => {
      const t = (s.getAttribute('type') || '').toLowerCase();
      return !s.src && s.textContent.trim() && t !== 'application/ld+json';
    }).length,
    jsonLd: document.querySelectorAll('script[type="application/ld+json"]').length,
    inlineStyles: document.querySelectorAll('[style]').length,
    issues
  };
})()`;

async function main() {
  fs.mkdirSync(OUT, { recursive: true });

  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-lib-shot-'));
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
  };
  process.on('exit', cleanup);
  // 被 SIGTERM 打断时也要收掉 Edge，否则 9335 端口会残留（后台那支踩过）
  process.on('SIGTERM', () => { cleanup(); process.exit(143); });
  process.on('SIGINT', () => { cleanup(); process.exit(130); });

  const browserWs = await waitForDevtools();
  const browser = await connect(browserWs);

  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await browser.send('Target.attachToTarget', { targetId, flatten: true });

  const session = {
    send: (method, params = {}) => browser.send(method, params, sessionId),
    once: (method) => browser.once(method),
    on: (method, fn) => browser.on(method, fn)
  };

  // 导航会重建 execution context，evaluate 必须用新 contextId（后台那支踩过）
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
  await session.send('Log.enable');

  // 主题：默认按夜间的第一帧截图，但站点有两套主题，浅色那套同样要验收。
  // theme.js 只读 localStorage，所以这里在每次导航前先把值种进去。
  const THEME = (process.env.THEME || 'night').toLowerCase();
  if (THEME === 'day' || THEME === 'night') {
    await session.send('Page.addScriptToEvaluateOnNewDocument', {
      source: `try { localStorage.setItem('hq-theme', ${JSON.stringify(THEME)}); } catch (e) {}`
    });
  }

  const consoleErrors = [];
  session.on('Log.entryAdded', (p) => {
    if (p?.entry?.level === 'error') consoleErrors.push(p.entry.text.slice(0, 200));
  });
  session.on('Runtime.exceptionThrown', (p) => {
    consoleErrors.push(('JS 异常：' + (p?.exceptionDetails?.exception?.description || p?.exceptionDetails?.text || '')).slice(0, 200));
  });

  // ---- 页面清单 ----
  // 作品码与词条 id 都不写死：term:prune 会删词、作品会下线，
  // 写死的 id 下一次跑就变成 404，报出来的「异常」全是脚本自己造成的。
  // 做法是先抓一份列表页，从真链接里取前两个；抓不到再退回环境变量。
  const pick = async (listPath, re, count) => {
    try {
      const html = await (await fetch(BASE + listPath)).text();
      return [...new Set([...html.matchAll(re)].map((m) => m[1]))].slice(0, count);
    } catch (e) {
      console.warn(`抓取 ${listPath} 失败：${e.message}`);
      return [];
    }
  };

  const workCode = process.env.WORK_CODE
    || (await pick('/library/works', /\/library\/works\/([0-9A-Z]{20,32})/g, 1))[0]
    || '01M61HYFQ9KQD9B8B509AC5QKK';

  let termIds = (process.env.TERM_IDS || '').split(',').map((s) => s.trim()).filter(Boolean);
  if (termIds.length < 1) termIds = await pick('/library/terms', /\/library\/terms\/(\d+)/g, 1);
  if (termIds.length < 1) console.warn('抓不到词条 id：词条页将跳过');

  const issueNo = process.env.ISSUE_NO
    || (await pick('/library', /\/library\/issues\/(\d+)/g, 1))[0]
    || '25';

  // 作者页同样从真链接里取：作品详情页上的作者名现在指到 /library/authors/<ULID>
  let authorCode = process.env.AUTHOR_CODE || '';
  if (!authorCode) {
    const workHtml = await (await fetch(`${BASE}/library/works/${workCode}`)).text().catch(() => '');
    authorCode = (workHtml.match(/\/library\/authors\/([0-9A-Z]{20,32})/) || [])[1] || '';
  }
  if (!authorCode) console.warn('抓不到作者 code：作者页将跳过');

  // 默认查询词取「库里真有」的：时间旅行命中两篇，星空能在标题里对上并出 <mark>。
  const QUERY = process.env.QUERY || '时间旅行';

  const plan = {
    home: '/library',
    works: '/library/works',
    'works-sorted': '/library/works?sort=words',
    work: `/library/works/${workCode}`,
    'work-author': `/library/works/${workCode}/author`,
    ...(authorCode ? {
      author: `/library/authors/${authorCode}`,
      'works-by-author': `/library/works?author=${authorCode}`
    } : {}),
    terms: '/library/terms',
    method: '/library/method',
    ...(termIds.length >= 1 ? {
      term: `/library/terms/${termIds[0]}`
    } : {}),
    search: `/library/search?q=${encodeURIComponent(QUERY)}`,
    // 真会在标题里命中的词，用来验证 <mark> 的样式（纯词条命中的页面 marks 本来就是 0）
    'search-hit': `/library/search?q=${encodeURIComponent(process.env.QUERY_HIT || '星空')}`,
    'search-empty': '/library/search?q=zzz%E4%B8%8D%E5%AD%98%E5%9C%A8',
    issue: `/library/issues/${issueNo}`,
    notfound: '/library/works/NOTEXIST'
  };

  const shots = (process.env.PAGES || Object.keys(plan).join(',')).split(',').map((s) => s.trim()).filter(Boolean);

  const audit = { base: BASE, when: new Date().toISOString(), widths: WIDTHS, pages: [] };

  for (const width of WIDTHS) {
    await session.send('Emulation.setDeviceMetricsOverride', {
      width, height: HEIGHT, deviceScaleFactor: 1, mobile: width < 600
    });

    for (const name of shots) {
      const url = plan[name];
      if (!url) { console.warn('跳过未知页面：', name); continue; }

      consoleErrors.length = 0;

      const loaded = session.once('Page.loadEventFired');
      await session.send('Page.navigate', { url: BASE + url });
      await loaded;
      await sleep(350); // 让 theme.js / library.js 跑完，字体到齐

      const info = await evaluate(AUDIT_JS);

      const { data } = await session.send('Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: true
      });

      const file = path.join(OUT, `${name}-${width}-${THEME}.png`);
      fs.writeFileSync(file, Buffer.from(data, 'base64'));

      const record = { name, width, themeRequested: THEME, url, ...info, consoleErrors: [...consoleErrors], file: path.relative(ROOT, file) };
      // 种了主题却没生效，说明 theme.js 没跑或首帧被覆盖 —— 这比样式难看严重得多
      if ((THEME === 'day' || THEME === 'night') && info.theme !== THEME) {
        record.issues.push(`主题未生效：期望 ${THEME}，实际 ${info.theme || '（空）'}`);
      }
      // notfound 本来就是 404，浏览器必然报一条 "Failed to load resource"，不算问题
      if (consoleErrors.length && !EXPECT_4XX.has(name)) {
        record.issues.push('控制台报错：' + consoleErrors[0]);
      }
      audit.pages.push(record);

      const flag = record.issues.length ? '✗ ' + record.issues.join('；') : 'ok';
      console.log(
        `${String(width).padEnd(5)} ${name.padEnd(14)} h=${String(info.height).padStart(5)} `
        + `marks=${info.marks} bars=${info.bars} cards=${info.cards} → ${flag}`
      );
    }
  }

  // 两套主题各存一份报告，否则第二次跑会把第一次的审计结果覆盖掉
  const auditFile = path.join(OUT, `audit-${THEME}.json`);
  fs.writeFileSync(auditFile, JSON.stringify(audit, null, 2), 'utf8');

  const bad = audit.pages.filter((p) => p.issues.length);
  console.log(`\n共 ${audit.pages.length} 张，异常 ${bad.length} 张。报告：${path.relative(ROOT, auditFile)}`);

  cleanup();
  if (bad.length) process.exitCode = 2;
}

main().catch((err) => {
  console.error('失败：', err.message);
  process.exit(1);
});
