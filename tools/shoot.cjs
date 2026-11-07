#!/usr/bin/env node
/* ==========================================================================
   荒启科幻官网 · 页面截图（视觉验收）
   --------------------------------------------------------------------------
   不依赖任何第三方包：
     · 用 Node 内置 http 模块起一个本地静态服务器
     · 用系统已安装的 Microsoft Edge 的 --headless=new + --remote-debugging-port
     · 通过 CDP（Chrome DevTools Protocol，Node 22 内置 WebSocket）驱动页面
     · 支持「全页截图」（captureBeyondViewport）与「视口截图」（按滚动位置）

   为什么不用 --screenshot 命令行参数：
     该方式只能截取视口，且无法在截图前注入样式。本脚本改用 CDP 后可以
     等待字体就绪、强制展开入场动画、按需截取整页。

   用法：
     node tools/shoot.cjs                        # 默认：1140/1440/375 三个宽度全页截图
     PAGES=index,works WIDTHS=1440 MODE=full node tools/shoot.cjs
     PAGES=index WIDTHS=1440 MODE=viewport SCROLLS=0,900,1800 node tools/shoot.cjs
     KEEP_EDGE=1 node tools/shoot.cjs            # 保留浏览器进程便于调试

   输出：.build/shots/<页面>-<宽度>.png
   ========================================================================== */
'use strict';

const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, '.build', 'shots');
const PORT = parseInt(process.env.PORT || '8123', 10);
const DEBUG_PORT = parseInt(process.env.DEBUG_PORT || '9333', 10);

const EDGE_CANDIDATES = [
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
];

const PAGES = (process.env.PAGES || 'index,about,community,tools,library,contact')
  .split(',').map((s) => s.trim()).filter(Boolean);

const WIDTHS = (process.env.WIDTHS || '1140,1440,375')
  .split(',').map((s) => parseInt(s, 10)).filter(Boolean);

const MODE = process.env.MODE || 'full';
const DPR = parseFloat(process.env.DPR || '1');
const SCROLLS = (process.env.SCROLLS || '0').split(',').map((s) => parseInt(s, 10));
const WAIT_AFTER_LOAD = parseInt(process.env.WAIT || '900', 10);
const MAX_HEIGHT = parseInt(process.env.MAX_HEIGHT || '24000', 10);

/* PROBE 模式：在页面内执行一段表达式并打印结果，用于排查字体、布局等运行时状态。
   表达式可直接写在 PROBE 中，也可用 PROBE_FILE 指向一个 .js 文件（避免 shell 转义问题）。 */
const PROBE = process.env.PROBE_FILE
  ? fs.readFileSync(path.resolve(ROOT, process.env.PROBE_FILE), 'utf8')
  : (process.env.PROBE || '');

/* ------------------------------------------------------------- 静态服务器 */
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.woff2': 'font/woff2',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon'
};

function startServer() {
  const server = http.createServer((req, res) => {
    let rel = decodeURIComponent(req.url.split('?')[0]);
    if (rel === '/') rel = '/index.html';
    const file = path.join(ROOT, rel);
    if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
      res.writeHead(404, { 'content-type': 'text/plain' });
      res.end('404');
      return;
    }
    res.writeHead(200, { 'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
  });
  return new Promise((resolve) => server.listen(PORT, '127.0.0.1', () => resolve(server)));
}

/* ---------------------------------------------------------------- CDP 客户端 */
function connect(wsUrl) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl);
    let seq = 0;
    const pending = new Map();
    const listeners = [];

    ws.addEventListener('message', (ev) => {
      let msg;
      try { msg = JSON.parse(ev.data); } catch { return; }
      if (msg.id && pending.has(msg.id)) {
        const { res, rej } = pending.get(msg.id);
        pending.delete(msg.id);
        if (msg.error) rej(new Error(JSON.stringify(msg.error)));
        else res(msg.result);
      } else if (msg.method) {
        listeners.forEach((fn) => fn(msg));
      }
    });

    ws.addEventListener('error', reject);
    ws.addEventListener('open', () =>
      resolve({
        send(method, params) {
          return new Promise((res, rej) => {
            const id = ++seq;
            pending.set(id, { res, rej });
            ws.send(JSON.stringify({ id, method, params: params || {} }));
          });
        },
        close() { try { ws.close(); } catch { /* 忽略 */ } }
      })
    );
  });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function fetchJson(url) {
  const res = await fetch(url);
  return res.json();
}

/* ------------------------------------------------------------------ 主流程 */
async function main() {
  const edge = EDGE_CANDIDATES.find((p) => fs.existsSync(p));
  if (!edge) throw new Error('未找到 Microsoft Edge，可用路径：\n  ' + EDGE_CANDIDATES.join('\n'));

  fs.mkdirSync(OUT, { recursive: true });

  const server = await startServer();
  console.log(`静态服务器 http://127.0.0.1:${PORT}（根目录 ${ROOT}）`);

  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-edge-'));
  const env = { ...process.env };
  /* Windows 下环境变量名不区分大小写，代理变量存在大小写重复项时
     子进程创建会失败，因此这里统一清理 */
  Object.keys(env).forEach((k) => {
    if (/^(https?_proxy|all_proxy|no_proxy|codebuddy_mcp_config)$/i.test(k)) delete env[k];
  });

  const args = [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-extensions',
    '--disable-background-networking',
    '--hide-scrollbars',
    // MOTION=1 时不强制减弱动效 —— 动画类探针（如星球滚动离场）需要真实动画
    ...(process.env.MOTION ? [] : ['--force-prefers-reduced-motion']),
    `--remote-debugging-port=${DEBUG_PORT}`,
    `--user-data-dir=${profile}`,
    'about:blank'
  ];

  const child = spawn(edge, args, { env, stdio: 'ignore', detached: false });
  child.on('error', (e) => { throw e; });

  const cleanup = async () => {
    try { child.kill(); } catch { /* 忽略 */ }
    server.close();
    await sleep(250);
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* 忽略 */ }
  };

  /* 等待调试端口就绪 */
  let target = null;
  for (let i = 0; i < 60; i++) {
    try {
      const list = await fetchJson(`http://127.0.0.1:${DEBUG_PORT}/json/list`);
      target = list.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      if (target) break;
    } catch { /* 端口尚未监听 */ }
    await sleep(300);
  }
  if (!target) { await cleanup(); throw new Error('Edge 调试端口未就绪，无法继续'); }
  console.log('浏览器已就绪\n');

  const cdp = await connect(target.webSocketDebuggerUrl);
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');

  /* THEME=day / night：导航前预置 localStorage（v2.2 昼夜模式截图用）。
     首帧内联脚本会读取它，因此主题在第一帧就已生效。 */
  if (process.env.THEME) {
    await cdp.send('Page.addScriptToEvaluateOnNewDocument', {
      source: `try{localStorage.setItem('hq-theme','${process.env.THEME}')}catch(e){}`
    });
  }

  /* 在导航前声明媒体特性。默认减弱动效，保证截图确定性；
     MOTION=1 时保留真实动画（prefers-color-scheme 仍为 dark）。 */
  await cdp.send('Emulation.setEmulatedMedia', {
    features: process.env.MOTION
      ? [{ name: 'prefers-color-scheme', value: 'dark' }]
      : [
          { name: 'prefers-reduced-motion', value: 'reduce' },
          { name: 'prefers-color-scheme', value: 'dark' }
        ]
  });

  /* 覆盖入场动画：即使脚本未执行也保证内容可见 */
  const FORCE_STYLE = `
    [data-reveal]{opacity:1!important;transform:none!important;transition:none!important}
    .title-underline::after{transform:scaleX(1)!important}
    /* v2.3.19：v2.3.19 给 .section 加了 content-visibility:auto（长页首屏渲染提速），
       它会让「还没滚到」的区块在整页截图里留白 —— 而整页截图的验收意义就是看全篇。
       截图/探针期间临时关掉，页面本身不动。 */
    .section{content-visibility:visible!important}
  `;

  let count = 0;

  for (const width of WIDTHS) {
    const viewportH = width >= 1200 ? 900 : width >= 700 ? 1000 : 780;

    for (const name of PAGES) {
      const url = `http://127.0.0.1:${PORT}/${name}.html`;

      await cdp.send('Emulation.setDeviceMetricsOverride', {
        width, height: viewportH, deviceScaleFactor: DPR, mobile: width < 700
      });

      await cdp.send('Page.navigate', { url });

      /* 等待 load 完成 */
      for (let i = 0; i < 100; i++) {
        const r = await cdp.send('Runtime.evaluate', { expression: 'document.readyState', returnByValue: true });
        if (r.result && r.result.value === 'complete') break;
        await sleep(100);
      }
      /* 等待字体就绪 */
      await cdp.send('Runtime.evaluate', { expression: 'document.fonts.ready', awaitPromise: true });
      await sleep(WAIT_AFTER_LOAD);

      await cdp.send('Runtime.evaluate', {
        expression: `(function(){
          var s=document.createElement('style');s.textContent=${JSON.stringify(FORCE_STYLE)};
          document.head.appendChild(s);
          document.querySelectorAll('.bar-fill,.score-line__fill').forEach(function(e){
            e.style.width=(e.getAttribute('data-value')||'100')+'%';
          });
          return 1;
        })()`,
        returnByValue: true
      });
      await sleep(150);

      if (PROBE) {
        const r = await cdp.send('Runtime.evaluate', {
          expression: PROBE, returnByValue: true, awaitPromise: true
        });
        console.log(`  [${name} @${width}] ` + JSON.stringify(r.result && r.result.value, null, 2));
        /* PROBE_ONLY=1 时只探测不截图；否则继续截图，用于记录交互后的状态 */
        if (process.env.PROBE_ONLY === '1') continue;
      }

      if (MODE === 'el') {
        /* MODE=el：只截某个元素并放大 EL_SCALE 倍，用于看细节（图标、SVG 线稿）。
           元素矩形取自 getBoundingClientRect，加上滚动偏移换算成文档坐标。 */
        const el = process.env.EL || '.hero-station';
        const scale = parseFloat(process.env.EL_SCALE || '3');
        const r = await cdp.send('Runtime.evaluate', {
          expression: `(function(){
            var e=document.querySelector(${JSON.stringify(el)});
            if(!e) return null;
            var b=e.getBoundingClientRect();
            return {x:b.left+window.scrollX,y:b.top+window.scrollY,w:b.width,h:b.height};
          })()`,
          returnByValue: true
        });
        const box = r.result && r.result.value;
        if (!box) { console.log(`  ! 未找到元素 ${el}`); continue; }
        const shot = await cdp.send('Page.captureScreenshot', {
          format: 'png',
          fromSurface: true,
          captureBeyondViewport: true,
          clip: { x: box.x, y: box.y, width: box.w, height: box.h, scale }
        });
        const file = `${name}-${width}-el.png`;
        fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
        console.log(`  ${file.padEnd(28)} ${Math.round(box.w)}×${Math.round(box.h)} @${scale}x`);
        count++;
      } else if (MODE === 'viewport') {
        for (const y of SCROLLS) {
          await cdp.send('Runtime.evaluate', { expression: `window.scrollTo(0,${y});1`, returnByValue: true });
          /* 视差缓动每帧收敛 9%，跳跃滚动后需要 ~2-3s 才稳定到位；
             SCROLL_WAIT_MS 可加长等待（如验证星球滚动离场时） */
          await sleep(parseInt(process.env.SCROLL_WAIT_MS || '320', 10));
          const shot = await cdp.send('Page.captureScreenshot', { format: 'png', fromSurface: true });
          const file = `${name}-${width}-y${y}.png`;
          fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
          console.log(`  ${file}`);
          count++;
        }
      } else {
        const lm = await cdp.send('Page.getLayoutMetrics');
        const size = lm.cssContentSize || lm.contentSize;
        const h = Math.min(Math.ceil(size.height), MAX_HEIGHT);
        const shot = await cdp.send('Page.captureScreenshot', {
          format: 'png',
          fromSurface: true,
          captureBeyondViewport: true,
          clip: { x: 0, y: 0, width: Math.ceil(size.width), height: h, scale: 1 }
        });
        const file = `${name}-${width}.png`;
        fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
        const kb = (fs.statSync(path.join(OUT, file)).size / 1024).toFixed(0);
        console.log(`  ${file.padEnd(28)} ${width}×${h}  ${kb} KB`);
        count++;
      }
    }
  }

  cdp.close();
  await cleanup();
  console.log(`\n共生成 ${count} 张截图，输出目录：.build/shots/`);
}

main().catch(async (err) => {
  console.error('\n截图失败：' + err.message);
  process.exit(1);
});
