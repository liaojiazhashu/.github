'use strict';
/* 可信点击下的复制路径（不带任何预授权）
   ------------------------------------------------------------------
   team-lead 用合成 btn.click() 得到「复制失败」：合成 click 不产生用户激活，
   writeText 与 execCommand 都要求激活，所以失败是必然的，不能据此判定真人会失败。
   这里改用 CDP Input.dispatchMouseEvent（浏览器认作真实输入），并且：
     · 全程 **不** 调 Browser.grantPermissions
     · 每次用**新起的 Edge + 新 profile**，避免上一轮的授权残留
     · Page.bringToFront + 打印 document.hasFocus()：writeText 还要求文档是聚焦的，
       不聚焦时失败与「点击不可信」长得一样，必须先排除
   顺序上只做一件事：先可信点击并读文案 → 事后再 grant + readText 看剪贴板内容。
   事后授权的写不回去，所以不影响「无授权时这一下是否成功」的结论。
   用法：node .build/fe-copy-trusted.cjs */
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PORT = 8125;
const SLEEP = (ms) => new Promise((r) => setTimeout(r, ms));

/* 系统剪贴板读写（PowerShell）。浏览器内部的 readText 只能说明「浏览器这一侧
   看见了什么」，说明不了「真粘到别处会不会有东西」；两者可能不是同一个东西。 */
function psClipboard(cmd, value) {
  const { execFileSync } = require('child_process');
  const script = cmd === 'set'
    ? `Set-Clipboard -Value '${value}'`
    : 'Get-Clipboard -Raw';
  try {
    const out = execFileSync('powershell',
      ['-NoProfile', '-NonInteractive', '-Command', script],
      { encoding: 'utf8', timeout: 15000 });
    return cmd === 'set' ? true : out.replace(/\r?\n$/, '');
  } catch (e) { return cmd === 'set' ? 'set 失败: ' + e.message : 'get 失败: ' + e.message; }
}

const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2', '.ico': 'image/x-icon', '.jpg': 'image/jpeg' };

function startServer() {
  return new Promise((resolve) => {
    const srv = http.createServer((req, res) => {
      const rel = decodeURIComponent(req.url.split('?')[0]);
      const file = path.join(ROOT, rel === '/' ? 'index.html' : rel);
      if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
        res.writeHead(404); res.end('404'); return;
      }
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
      fs.createReadStream(file).pipe(res);
    });
    srv.listen(PORT, '127.0.0.1', () => resolve(srv));
  });
}

function edgePath() {
  for (const p of ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe']) if (fs.existsSync(p)) return p;
  throw new Error('找不到 Edge');
}

async function waitDevtools(port) {
  for (let i = 0; i < 60; i++) {
    try {
      const v = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
      if (v.webSocketDebuggerUrl) return v.webSocketDebuggerUrl;
    } catch { /* 未就绪 */ }
    await SLEEP(300);
  }
  throw new Error('调试端口未就绪');
}

function makeCdp(ws) {
  let seq = 0;
  const pending = new Map();
  const events = new Map();
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const { resolve, reject } = pending.get(m.id);
      pending.delete(m.id);
      m.error ? reject(new Error(m.error.message)) : resolve(m.result);
    } else if (m.method && events.has(m.method)) {
      events.get(m.method).splice(0).forEach((fn) => fn(m.params));
    }
  });
  return {
    send(method, params = {}, sid) {
      const id = ++seq;
      return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        const p = { id, method, params };
        if (sid) p.sessionId = sid;
        ws.send(JSON.stringify(p));
        setTimeout(() => { if (pending.has(id)) { pending.delete(id); reject(new Error('超时 ' + method)); } }, 30000);
      });
    },
    on(method, fn) {
      const list = events.get(method) || [];
      list.push(fn);
      events.set(method, list);
    }
  };
}

/* 一次独立的浏览器会话：新 Edge、新 profile、无任何授权 */
async function runCase({ label, url, width, dropClipboard, grantFirst, headful, special, sel, modal, debugPort }) {
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-trust-'));
  const child = spawn(edgePath(), [
    ...(headful ? [] : ['--headless=new']),
    `--remote-debugging-port=${debugPort}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu',
    `--window-size=${width},900`, 'about:blank'], { stdio: 'ignore' });

  const result = { label, url, width };
  try {
    /* 每个用例前把系统剪贴板写成哨兵值：不这么做的话，
       「读到 280660235」可能只是上一轮留下的，等于没测。 */
    const sentinel = 'SENTINEL-' + label.slice(0, 1) + '-' + Date.now();
    result.sentinelSet = psClipboard('set', sentinel);
    result.sentinel = sentinel;
    const ws = new WebSocket(await waitDevtools(debugPort));
    await new Promise((res, rej) => {
      ws.addEventListener('open', res, { once: true });
      ws.addEventListener('error', () => rej(new Error('WS 失败')), { once: true });
    });
    const browser = makeCdp(ws);
    const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await browser.send('Target.attachToTarget', { targetId, flatten: true });
    const S = (m, p) => browser.send(m, p, sessionId);

    let ctx = null;
    browser.on('Runtime.executionContextsCleared', () => { ctx = null; });
    browser.on('Runtime.executionContextCreated', (p) => {
      if (p && p.context && p.context.auxData && p.context.auxData.isDefault) ctx = p.context.id;
    });

    await S('Page.enable');
    await S('Runtime.enable');
    await S('Emulation.setDeviceMetricsOverride',
      { width, height: 900, deviceScaleFactor: 1, mobile: width < 500 });

    const ev = async (expr) => {
      const call = (withCtx) => S('Runtime.evaluate', {
        expression: expr, returnByValue: true, awaitPromise: true,
        ...(withCtx && ctx ? { contextId: ctx } : {})
      });
      let r;
      try { r = await call(true); } catch { ctx = null; r = await call(false); }
      if (r.exceptionDetails) throw new Error('页面异常: ' + (r.exceptionDetails.text || ''));
      return r.result ? r.result.value : undefined;
    };

    await S('Page.navigate', { url });
    for (let i = 0; i < 60; i++) {
      if (await ev('document.readyState === "complete" && document.styleSheets.length > 0')) break;
      await SLEEP(200);
    }
    await SLEEP(300);
    // writeText 要求文档聚焦；不聚焦时「点击不可信」和「页面没聚焦」症状一样
    try { await browser.send('Target.activateTarget', { targetId }); } catch { /* 旧版本没有 */ }
    await S('Page.bringToFront');
    await SLEEP(250);
    // 有头模式下窗口可能没拿到系统焦点，多试两次并如实记录
    for (let i = 0; i < 8; i++) {
      if (await ev('document.hasFocus()')) break;
      try { await browser.send('Target.activateTarget', { targetId }); } catch { /* ignore */ }
      await S('Page.bringToFront');
      await SLEEP(400);
    }

    result.env = await ev(`(function(){
      return { origin: location.origin, protocol: location.protocol,
               isSecureContext: window.isSecureContext, hasFocus: document.hasFocus(),
               hasClipboard: !!(navigator.clipboard && navigator.clipboard.writeText),
               userActivationSupported: !!navigator.userActivation,
               activeBefore: navigator.userActivation ? navigator.userActivation.isActive : null };
    })()`);

    if (grantFirst) {
      await browser.send('Browser.grantPermissions', {
        origin: result.env.protocol === 'file:' ? undefined : result.env.origin,
        permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite']
      });
      result.grantBefore = true;
    }

    if (dropClipboard) {
      result.dropClipboard = await ev(`(function(){
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        return { hasClipboard: !!navigator.clipboard };
      })()`);
    }

    if (modal !== false) {
      await ev('document.getElementById("invite-modal").showModal()');
      await SLEEP(350);
    } else {
      result.noModal = true;
      await SLEEP(200);
    }
    const TARGET = sel || '.copy-btn';

    /* 特殊用例：在同一页面状态里 A/B 两处宿主，验证「临时 textarea 挂在哪里」
       决定了 execCommand 到底有没有真的写进剪贴板。
       showModal() 之后页面其余部分进 inert（top layer 之外一律不可交互），
       往 body 里塞 textarea 再 select() 是选中了个 inert 子树里的节点。 */
    if (special === 'inert') {
      const probe = (host) => `(function(){
        var host = ${host === 'dialog' ? 'document.querySelector("dialog[open]")' : 'document.body'};
        var ta = document.createElement('textarea');
        ta.value = 'PROBE-280660235';
        host.appendChild(ta);
        var sel = null, ok = null;
        try { ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length); ok = document.execCommand('copy'); }
        catch (e) { ok = 'throw: ' + e.message; }
        sel = { start: ta.selectionStart, end: ta.selectionEnd, value: ta.value,
                activeIsTa: document.activeElement === ta };
        host.removeChild(ta);
        return { host: ${JSON.stringify(host)}, ok: ok, sel: sel };
      })()`;
      result.probeBody = await ev(probe('body'));
      result.probeBody.osClipboard = psClipboard('get');
      result.probeDialog = await ev(probe('dialog'));
      result.probeDialog.osClipboard = psClipboard('get');
      result.dialogOpen = await ev('!!document.querySelector("dialog[open]") && !!document.querySelector("dialog[open]").matches(".invite-modal")');
      ws.close();
      return result;
    }

    /* 滚动与测量必须分两次 evaluate：全站 html 有 scroll-behavior: smooth，
       scrollIntoView 会走动画，同一次 evaluate 里立刻取 rect 拿到的是滚动前的坐标
       （上一版就是这么把鼠标点到了 y=1475 的虚空，按钮毫无反应）。 */
    await ev(`(function(){
      var b = document.querySelector(${JSON.stringify(TARGET)});
      if (b) b.scrollIntoView({ block: 'center', behavior: 'instant' });
      return !!b;
    })()`);
    await SLEEP(500);
    const box = await ev(`(function(){
      var b = document.querySelector(${JSON.stringify(TARGET)});
      if (!b) return null;
      var r = b.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width, h: r.height,
               text: b.textContent, top: +r.top.toFixed(2), vh: window.innerHeight,
               inViewport: r.top >= 0 && r.bottom <= window.innerHeight };
    })()`);
    result.btnBefore = box;

    // 真实输入：move → press → release
    await S('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y });
    await S('Input.dispatchMouseEvent', { type: 'mousePressed', x: box.x, y: box.y, button: 'left', clickCount: 1 });
    await S('Input.dispatchMouseEvent', { type: 'mouseReleased', x: box.x, y: box.y, button: 'left', clickCount: 1 });
    await SLEEP(200);

    result.activationAfterClick = await ev(
      'navigator.userActivation ? { active: navigator.userActivation.isActive, ever: navigator.userActivation.hasBeenActive } : "不支持"');

    result.afterClick = await ev(`(function(){
      var b = document.querySelector(${JSON.stringify(TARGET)});
      return { text: b.textContent, aria: b.getAttribute('aria-label'),
               isDone: b.classList.contains('is-done') };
    })()`);

    /* 事后再授权读剪贴板：授权不回写，这一步只回答「刚才到底写进去了没有」 */
    try {
      await browser.send('Browser.grantPermissions', {
        origin: result.env.protocol === 'file:' ? undefined : result.env.origin,
        permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite']
      });
      result.grantAfter = true;
    } catch (e) { result.grantAfter = 'grant 失败: ' + e.message; }

    result.clipboard = await ev(`(function(){
      if (${dropClipboard ? 'true' : 'false'}) { delete navigator.clipboard; }
      if (!navigator.clipboard || !navigator.clipboard.readText) return '无 readText';
      return navigator.clipboard.readText().then(function(t){
        return { text: t, match: t === '280660235' };
      }, function(e){ return 'readText 不可用: ' + e.message; });
    })()`);

    /* 独立的第三方视角：直接问操作系统。这一栏才是「粘到别处有没有东西」。 */
    result.osClipboard = psClipboard('get');
    result.osClipboardIsNumber = result.osClipboard === '280660235';
    result.osClipboardStillSentinel = result.osClipboard === sentinel;

    ws.close();
  } finally {
    try { child.kill(); } catch { /* ignore */ }
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* ignore */ }
  }
  return result;
}

(async () => {
  const srv = await startServer();
  const httpUrl = `http://127.0.0.1:${PORT}/index.html`;
  const fileUrl = 'file:///' + path.join(ROOT, 'index.html').replace(/\\/g, '/');
  const cases = process.env.CASES
    ? JSON.parse(process.env.CASES)
    : process.env.HEADFUL
    ? [
      { label: 'H1. 有头 390 无授权（writeText 主路径）', url: httpUrl, width: 390, headful: true, debugPort: 9361 },
      { label: 'H2. 有头 390 无授权（抹掉 clipboard 走兜底，textarea 挂 dialog）', url: httpUrl, width: 390, dropClipboard: true, headful: true, debugPort: 9362 }
    ]
    : [
      { label: 'A. http 127.0.0.1 · 390 · 无授权', url: httpUrl, width: 390, debugPort: 9351 },
      { label: 'B. http 127.0.0.1 · 1440 · 无授权', url: httpUrl, width: 1440, debugPort: 9352 },
      { label: 'C. file:// · 390 · 无授权', url: fileUrl, width: 390, debugPort: 9353 },
      { label: 'D. http · 390 · navigator.clipboard 抹掉（纯兜底）', url: httpUrl, width: 390, dropClipboard: true, debugPort: 9354 },
      { label: 'E. http · 390 · 先授权再走兜底（对照 D）', url: httpUrl, width: 390, dropClipboard: true, grantFirst: true, debugPort: 9355 }
    ];
  const out = [];
  for (const c of cases) {
    try { out.push(await runCase(c)); }
    catch (e) { out.push({ label: c.label, error: e.stack || e.message }); }
  }
  const outFile = path.join(ROOT, '.build', process.env.OUT || (process.env.HEADFUL ? 'fe-copy-headful.json' : 'fe-copy-trusted.json'));
  fs.writeFileSync(outFile, JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out, null, 1));
  srv.close();
  process.exit(0);
})().catch((e) => { console.error('FAILED:', e.stack || e.message); process.exit(1); });
