'use strict';
/* 官网 P0 验收：复制群号按钮 + 顺序调整
   静态站没有 /admin/login，不能用 openAdmin（它会先登录并注入 cookie），
   所以这里自带一个静态服务器 + 一份最小 CDP 启动器。
   用法：node .build/fe-invite.cjs */
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PORT = 8124;
const DEBUG = 9341;
const ORIGIN = `http://127.0.0.1:${PORT}`;
const WIDTHS = (process.env.WIDTHS || '1440,560,390').split(',').map((s) => parseInt(s, 10));

const MIME = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8', '.png': 'image/png',
  '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.woff2': 'font/woff2',
  '.ico': 'image/x-icon', '.json': 'application/json'
};

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

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function edgePath() {
  const c = ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'];
  for (const p of c) if (fs.existsSync(p)) return p;
  throw new Error('找不到 Edge');
}

async function waitDevtools(port) {
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${port}/json/version`);
      const v = await r.json();
      if (v.webSocketDebuggerUrl) return v.webSocketDebuggerUrl;
    } catch { /* 未就绪 */ }
    await sleep(300);
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
    send(method, params = {}, sessionId) {
      const id = ++seq;
      return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        const p = { id, method, params };
        if (sessionId) p.sessionId = sessionId;
        ws.send(JSON.stringify(p));
        setTimeout(() => {
          if (pending.has(id)) { pending.delete(id); reject(new Error('CDP 超时 ' + method)); }
        }, 30000);
      });
    },
    once(method) {
      return new Promise((resolve) => {
        const list = events.get(method) || [];
        list.push((p) => resolve(p));
        events.set(method, list);
      });
    },
    on(method, fn) {
      const list = events.get(method) || [];
      list.push(fn);
      events.set(method, list);
    }
  };
}

(async () => {
  const srv = await startServer();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-site-'));
  const child = spawn(edgePath(), ['--headless=new', `--remote-debugging-port=${DEBUG}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu',
    '--window-size=1440,1000', 'about:blank'], { stdio: 'ignore' });

  const cleanup = () => {
    try { child.kill(); } catch { /* ignore */ }
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* ignore */ }
    try { srv.close(); } catch { /* ignore */ }
  };
  process.on('exit', cleanup);

  const ws = new WebSocket(await waitDevtools(DEBUG));
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

  const ev = async (expr) => {
    const call = (withCtx) => S('Runtime.evaluate', {
      expression: expr, returnByValue: true, awaitPromise: true,
      ...(withCtx && ctx ? { contextId: ctx } : {})
    });
    let r;
    try { r = await call(true); } catch { ctx = null; r = await call(false); }
    if (r.exceptionDetails) {
      const d0 = r.exceptionDetails;
      const desc = (d0.exception && (d0.exception.description || d0.exception.value)) || d0.text || '';
      throw new Error('页面异常: ' + desc);
    }
    return r.result ? r.result.value : undefined;
  };

  const ready = async () => {
    for (let i = 0; i < 60; i++) {
      const ok = await ev('document.readyState === "complete" && document.styleSheets.length > 0');
      if (ok) return true;
      await sleep(200);
    }
    return false;
  };

  const openPage = async (file) => {
    await S('Page.navigate', { url: `${ORIGIN}/${file}` });
    await sleep(300);
    if (!await ready()) throw new Error('页面未就绪：' + file);
    await sleep(250);
  };

  const setW = async (w, h) => S('Emulation.setDeviceMetricsOverride',
    { width: w, height: h || 900, deviceScaleFactor: 1, mobile: w < 500 });

  const clickSel = async (sel) => {
    const box = await ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;var r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2};})()`);
    if (!box) throw new Error('点击目标不存在：' + sel);
    for (const type of ['mousePressed', 'mouseReleased']) {
      await S('Input.dispatchMouseEvent', {
        type, x: box.x, y: box.y, button: 'left', clickCount: 1
      });
    }
  };

  const shot = async (out, sel) => {
    /* 先滚到目标再截：站内 [data-reveal] 元素是 IntersectionObserver 驱动的入场
       动画，滚不到就一直是 opacity:0 —— 截出来是一张纯底色的空白图（踩过）。 */
    await ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)});
      if(e) e.scrollIntoView({block:'center',behavior:'instant'}); return !!e;})()`);
    await sleep(700);
    const box = await ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;var r=e.getBoundingClientRect();return {x:r.left+window.scrollX,y:r.top+window.scrollY,w:r.width,h:r.height};})()`);
    if (!box) throw new Error('截图目标不存在：' + sel);
    // 目标在折叠线以下时必须 captureBeyondViewport，否则截出来是空白
    const res = await S('Page.captureScreenshot', {
      format: 'png',
      captureBeyondViewport: true,
      clip: { x: Math.max(0, box.x - 10), y: Math.max(0, box.y - 10), width: box.w + 20, height: box.h + 20, scale: 2 }
    });
    fs.writeFileSync(out, Buffer.from(res.data, 'base64'));
  };

  const GEOM = `(function () {
    function R(el) { if (!el) return null; var b = el.getBoundingClientRect();
      return { top: +b.top.toFixed(2), bottom: +b.bottom.toFixed(2), left: +b.left.toFixed(2),
               right: +b.right.toFixed(2), h: +b.height.toFixed(2), w: +b.width.toFixed(2) }; }
    var p = document.querySelector('.invite-modal__group');
    var num = p ? p.querySelector('.mono') : null;
    var btn = document.querySelector('.invite-modal__group .copy-btn');
    var hint = document.querySelector('.invite-modal__hint');
    var qr = document.querySelector('.invite-modal__qr');
    var card = document.querySelector('.invite-modal__card');
    var cs = btn ? getComputedStyle(btn) : null;
    var csh = hint ? getComputedStyle(hint) : null;
    return {
      num: R(num), btn: R(btn), hint: R(hint), qr: R(qr), p: R(p), card: R(card),
      order: { numTop: num ? +num.getBoundingClientRect().top.toFixed(2) : null,
               hintTop: hint ? +hint.getBoundingClientRect().top.toFixed(2) : null,
               qrTop: qr ? +qr.getBoundingClientRect().top.toFixed(2) : null },
      btnStyle: cs ? { borderW: cs.borderTopWidth, borderStyle: cs.borderTopStyle,
                        borderColor: cs.borderTopColor, color: cs.color, bg: cs.backgroundColor,
                        radius: cs.borderTopLeftRadius, fontSize: cs.fontSize,
                        minH: cs.minHeight, padding: cs.padding, marginLeft: cs.marginLeft,
                        cursor: cs.cursor, display: cs.display, valign: cs.verticalAlign,
                        lineHeight: cs.lineHeight } : null,
      hintLines: (function(){
        var h = document.querySelector('.invite-modal__hint');
        if (!h) return null;
        var r = document.createRange(); r.selectNodeContents(h);
        var rects = r.getClientRects();
        var out = [];
        for (var i = 0; i < rects.length; i++) out.push(+rects[i].width.toFixed(2));
        return { count: rects.length, widths: out, textLen: h.textContent.length,
                 scrollW: h.scrollWidth, clientW: h.clientWidth };
      })(),
      hintStyle: csh ? { fontSize: csh.fontSize, color: csh.color, margin: csh.margin, lineHeight: csh.lineHeight, textAlign: csh.textAlign } : null,
      pStyle: p ? (function(){var c=getComputedStyle(p);return {textAlign:c.textAlign,height:c.height,lineHeight:c.lineHeight,fontSize:c.fontSize};})() : null,
      cardBg: card ? getComputedStyle(card).backgroundColor : null,
      dialogBg: (function(){ var d = document.querySelector('.invite-modal'); return d ? getComputedStyle(d).backgroundColor : null; })(),
      btnFont: btn ? getComputedStyle(btn).fontSize : null,
      wrapped: p ? (p.getBoundingClientRect().height > 40) : null,
      docOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      bodyOverflow: document.body.scrollWidth - document.body.clientWidth,
      vw: window.innerWidth, dpr: window.devicePixelRatio
    };
  })()`;

  const out = { geom: {}, copy: {}, order: {}, csp: {}, misc: {} };

  /* ---------- 1) 三档几何：同一行 + 不横向溢出 ---------- */
  for (const w of WIDTHS) {
    await setW(w);
    await openPage('index.html');

    // 弹窗前后的溢出对比（同一页、同一宽度，只差一个弹窗）
    const before = await ev('document.documentElement.scrollWidth - document.documentElement.clientWidth');
    await ev('document.getElementById("invite-modal").showModal()');
    await sleep(350);
    const g = await ev(GEOM);
    g.overflowBeforeModal = before;

    // 把按钮藏起来再量一次：溢出是否由按钮造成
    const hideDelta = await ev(`(function(){
      var b = document.querySelector('.invite-modal__group .copy-btn');
      var base = document.documentElement.scrollWidth - document.documentElement.clientWidth;
      b.style.display = 'none';
      var off = document.documentElement.scrollWidth - document.documentElement.clientWidth;
      b.style.display = '';
      return { withBtn: base, withoutBtn: off };
    })()`);
    g.hideDelta = hideDelta;

    // 折行判据：p 的高度是否等于单行行高（容差 2px）
    g.pOneLine = Math.abs(parseFloat(g.pStyle.height) - parseFloat(g.pStyle.lineHeight)) < 2;

    out.geom[w] = g;
    // 白天模式另量一遍颜色（--c-nebula 两套值差很远，只量夜间等于没量）
    await ev('document.documentElement.setAttribute("data-theme","day")');
    await sleep(200);
    g.day = await ev(`(function(){
      var b = document.querySelector('.invite-modal__group .copy-btn');
      var d = document.querySelector('.invite-modal');
      return { color: getComputedStyle(b).color, bg: getComputedStyle(d).backgroundColor,
               border: getComputedStyle(b).borderTopColor };
    })()`);
    await ev('document.documentElement.setAttribute("data-theme","night")');
    await sleep(150);
    if (w === 390 || w === 1440) await shot(path.join(ROOT, '.build', 'shots', `fe-invite-${w}.png`), '.invite-modal__card');
    await ev('document.getElementById("invite-modal").close()');
  }

  /* ---------- 2) 两条复制路径 ---------- */
  await setW(390);
  await openPage('about.html');
  await ev('document.getElementById("invite-modal").showModal()');
  await sleep(300);

  // 授予剪贴板权限，让 navigator.clipboard.writeText 这条主路径真的能跑通
  let granted = false;
  try {
    await browser.send('Browser.grantPermissions', {
      origin: ORIGIN, permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite']
    });
    granted = true;
  } catch (e) { granted = 'grant 失败: ' + e.message; }
  out.misc.clipPerm = granted;

  out.copy.env = await ev(`(function(){
    return {
      hasClipboard: !!(navigator.clipboard && navigator.clipboard.writeText),
      isSecureContext: window.isSecureContext,
      hasExecCommand: typeof document.execCommand === 'function',
      label: document.querySelector('.invite-modal__group .copy-btn').textContent
    };
  })()`);

  // 路径 A：navigator.clipboard 存在
  await clickSel('.invite-modal__group .copy-btn');
  await sleep(150);
  out.copy.pathA = await ev(`(function(){
    var b = document.querySelector('.invite-modal__group .copy-btn');
    return { text: b.textContent, isDone: b.classList.contains('is-done'),
             dataLabel: b.getAttribute('data-label'),
             ariaLabel: b.getAttribute('aria-label'),
             borderColor: getComputedStyle(b).borderTopColor,
             opacity: getComputedStyle(b).opacity };
  })()`);
  try {
    out.copy.pathA.clipboardText = await ev('navigator.clipboard.readText()');
  } catch (e) { out.copy.pathA.clipboardText = '读取失败: ' + e.message; }
  await sleep(2100);
  out.copy.pathA.after2s = await ev(`(function(){var b=document.querySelector('.invite-modal__group .copy-btn');
    return { text: b.textContent, isDone: b.classList.contains('is-done'), opacity: getComputedStyle(b).opacity,
             ariaLabel: b.getAttribute('aria-label') };})()`);

  // 路径 B：把 navigator.clipboard 抹掉，逼走 execCommand + 临时 textarea
  out.copy.pathB_inject = await ev(`(function(){
    try { Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true }); }
    catch (e) { return 'defineProperty 失败: ' + e.message; }
    return { hasClipboard: !!navigator.clipboard };
  })()`);
  const taBefore = await ev('document.querySelectorAll("textarea").length');
  await clickSel('.invite-modal__group .copy-btn');
  await sleep(150);
  out.copy.pathB = await ev(`(function(){
    var b = document.querySelector('.invite-modal__group .copy-btn');
    return { text: b.textContent, isDone: b.classList.contains('is-done'),
             textareaCountNow: document.querySelectorAll('textarea').length };
  })()`);
  out.copy.pathB.textareaBefore = taBefore;
  // execCommand 写的是系统剪贴板：把被抹掉的 navigator.clipboard 还原（删掉自有属性，
  // 原型上的 getter 就回来了），再读一次 —— 这才证明「兜底路径真的复制进去了」，
  // 而不是只证明了「按钮文案变了」。
  out.copy.pathB.clipboardProof = await ev(`(function(){
    var del = delete navigator.clipboard;
    if (!navigator.clipboard) return '还原失败（delete 返回 ' + del + '）';
    return navigator.clipboard.readText().then(function(t){
      return { restored: true, clipText: t, match: t === '280660235' };
    }, function(e){ return 'readText 失败: ' + e.message; });
  })()`);
  // execCommand 写的是系统剪贴板；用 CDP 再读一次（document.execCommand('paste') 被禁，改看按钮文案）
  await sleep(2100);
  out.copy.pathB.after2s = await ev(`document.querySelector('.invite-modal__group .copy-btn').textContent`);

  // 路径 C：两条都失败 → 失败文案保留 3s。
  // 注意必须**同时**抹掉 navigator.clipboard 并桩掉 execCommand，
  // 只桩 execCommand 的话主路径照样成功（上一版就这么假通过了）。
  out.copy.pathC = await ev(`(function(){
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    var b = document.querySelector('.invite-modal__group .copy-btn');
    var orig = document.execCommand;
    document.execCommand = function(){ return false; };
    window.__restoreExec = function(){ document.execCommand = orig; delete navigator.clipboard; };
    return { hasClipboard: !!navigator.clipboard, execStubbed: true };
  })()`);
  await clickSel('.invite-modal__group .copy-btn');
  await sleep(150);
  out.copy.pathC.afterClick = await ev(`(function(){var b=document.querySelector('.invite-modal__group .copy-btn');
    return { text: b.textContent, isDone: b.classList.contains('is-done') };})()`);
  await sleep(2200);
  out.copy.pathC.at2_2s = await ev('document.querySelector(".invite-modal__group .copy-btn").textContent');
  await sleep(1200);
  out.copy.pathC.at3_4s = await ev('document.querySelector(".invite-modal__group .copy-btn").textContent');
  await ev('window.__restoreExec && window.__restoreExec()');

  /* ---------- 3) 390 顺序：群号 → 提示 → 二维码 ---------- */
  await setW(390);
  await openPage('contact.html');
  await ev('document.getElementById("invite-modal").showModal()');
  await sleep(300);
  out.order = await ev(`(function(){
    var host = document.querySelector('.invite-modal__card');
    var list = [];
    for (var i = 0; i < host.children.length; i++) {
      var c = host.children[i];
      var top = +(c.getBoundingClientRect().top).toFixed(2);
      list.push({ cls: c.className || c.tagName, top: top });
    }
    function idx(sel) { for (var i=0;i<host.children.length;i++) if (host.children[i].matches(sel)) return i; return -1; }
    return {
      children: list,
      iGroup: idx('.invite-modal__group'),
      iHint: idx('.invite-modal__hint'),
      iQr: idx('.invite-modal__qr'),
      ordered: idx('.invite-modal__group') < idx('.invite-modal__hint') && idx('.invite-modal__hint') < idx('.invite-modal__qr')
    };
  })()`);

  /* ---------- 4) 令牌解析证据：确认 border 来自 --line-2 而不是回落 ---------- */
  out.misc.tokens = await ev(`(function(){
    var cs = getComputedStyle(document.documentElement);
    var b = getComputedStyle(document.querySelector('.invite-modal__group .copy-btn'));
    return {
      line: cs.getPropertyValue('--line').trim() || '(未定义)',
      line2: cs.getPropertyValue('--line-2').trim(),
      nebula: cs.getPropertyValue('--c-nebula').trim(),
      fsXs: cs.getPropertyValue('--fs-xs').trim(),
      r2: cs.getPropertyValue('--r-2').trim(),
      computedBorder: b.borderTopWidth + ' ' + b.borderTopStyle + ' ' + b.borderTopColor
    };
  })()`);

  /* ---------- 5) 可选实验：把群号行盒也做成 28px，看 topΔ 能不能归零、代价是几像素 ---------- */
  if (process.env.ALIGN) {
    await setW(390);
    await openPage('index.html');
    await ev('document.getElementById("invite-modal").showModal()');
    await sleep(300);
    out.align = await ev(`(function(){
      function box(sel){ var e=document.querySelector(sel); var b=e.getBoundingClientRect();
        return { top:+b.top.toFixed(2), h:+b.height.toFixed(2) }; }
      function glyph(){
        var s = document.querySelector('.invite-modal__group .mono');
        var r = document.createRange(); r.selectNodeContents(s);
        var b = r.getBoundingClientRect();
        return { top:+b.top.toFixed(2), bottom:+b.bottom.toFixed(2) };
      }
      function snap(){
        var n = box('.invite-modal__group .mono'), b = box('.invite-modal__group .copy-btn');
        var g = glyph();
        return { numTop:n.top, numH:n.h, btnTop:b.top, btnH:b.h,
                 topDelta:+(b.top-n.top).toFixed(2),
                 centerDelta:+((b.top+b.h/2)-(n.top+n.h/2)).toFixed(2),
                 glyphTop:g.top, glyphBottom:g.bottom };
      }
      var before = snap();
      var s = document.querySelector('.invite-modal__group .mono');
      s.style.minHeight = '28px';
      s.style.display = 'inline-flex';
      s.style.alignItems = 'center';
      s.style.verticalAlign = 'middle';
      var after = snap();
      var p = document.querySelector('.invite-modal__group').getBoundingClientRect();
      var afterPH = +p.height.toFixed(2);
      s.style.minHeight = ''; s.style.display = ''; s.style.alignItems = ''; s.style.verticalAlign = '';
      return { before: before, after: after, pHeightAfter: afterPH,
               glyphShift: +(after.glyphTop - before.glyphTop).toFixed(2) };
    })()`);
  }

  if (process.env.DONE) {
    await setW(390);
    await openPage('index.html');
    await ev('document.getElementById("invite-modal").showModal()');
    await sleep(300);
    await shot(path.join(ROOT, '.build', 'shots', 'fe-invite-idle-390.png'), '.invite-modal__group');
    await clickSel('.invite-modal__group .copy-btn');
    await sleep(150);
    await shot(path.join(ROOT, '.build', 'shots', 'fe-invite-done-390.png'), '.invite-modal__group');
    out.doneShot = await ev(`(function(){
      var b = document.querySelector('.invite-modal__group .copy-btn');
      return { text: b.textContent, aria: b.getAttribute('aria-label'), opacity: getComputedStyle(b).opacity };
    })()`);
  }

  /* ---------- 6) 联系页两个新按钮的几何（390） ---------- */
  if (process.env.CONTACT) {
    out.contact = {};
    for (const cw of [390, 1440]) {
    await setW(cw);
    await openPage('contact.html');
    const CGEOM = (selBtn, selNum, selBox) => `(function(){
      function R(e){ if(!e) return null; var b=e.getBoundingClientRect();
        return { top:+b.top.toFixed(2), bottom:+b.bottom.toFixed(2), left:+b.left.toFixed(2),
                 right:+b.right.toFixed(2), w:+b.width.toFixed(2), h:+b.height.toFixed(2) }; }
      var btn=document.querySelector(${JSON.stringify(selBtn)});
      var num=document.querySelector(${JSON.stringify(selNum)});
      var box=document.querySelector(${JSON.stringify(selBox)});
      var cs=btn?getComputedStyle(btn):null;
      var rb=btn?btn.getBoundingClientRect():null, rn=num?num.getBoundingClientRect():null;
      return { btn:R(btn), num:R(num), box:R(box),
        centerDelta: (rb&&rn) ? +((rb.top+rb.height/2)-(rn.top+rn.height/2)).toFixed(2) : null,
        topDelta: (rb&&rn) ? +(rb.top-rn.top).toFixed(2) : null,
        btnStyle: cs ? { display:cs.display, border:cs.borderTopWidth+' '+cs.borderTopStyle+' '+cs.borderTopColor,
                         color:cs.color, radius:cs.borderTopLeftRadius, minH:cs.minHeight,
                         margin:cs.margin, fontSize:cs.fontSize } : null,
        boxHeight: box ? +box.getBoundingClientRect().height.toFixed(2) : null,
        boxLineHeight: box ? getComputedStyle(box).lineHeight : null };
    })()`;
    const badge = await ev(CGEOM('.qq-badge .copy-btn', '.qq-badge .mono', '.qq-badge'));
    const step = await ev(CGEOM('.step__desc .copy-btn', '.step__desc .mono', '.step__desc'));
    const ovf = await ev(`(function(){
      var b=document.querySelectorAll('.copy-btn');
      var withBtn = document.documentElement.scrollWidth - document.documentElement.clientWidth;
      for (var i=0;i<b.length;i++) b[i].style.display='none';
      var without = document.documentElement.scrollWidth - document.documentElement.clientWidth;
      for (var j=0;j<b.length;j++) b[j].style.display='';
      return { withBtn: withBtn, withoutBtn: without, count: b.length };
    })()`);
    /* 断言「预期形态」而不是「当前测量值」（ux 指出）：
       另起一行居中 —— 必须同时满足 水平中心对齐 与 top 差 > 20。
       只写 |centerX 差| < 0.5 的话，哪天按钮并回同一行，断言会自己改判、永远绿；
       topΔ > 20 是「确实是两行」的正面证据，它会让并回一行变成红灯。 */
    const badgeCenterX = (badge.btn.left + badge.btn.right) / 2;
    const boxCenterX = (badge.box.left + badge.box.right) / 2;
    const centerXDiff = Math.abs(badgeCenterX - boxCenterX);
    out.contact[cw] = {
      badge, step, overflow: ovf,
      assert: {
        badgeOnOwnLineCentered: {
          expect: '|centerX差| < 0.5 且 topΔ > 20',
          centerXDiff: +centerXDiff.toFixed(2),
          topDelta: badge.topDelta,
          pass: centerXDiff < 0.5 && badge.topDelta > 20
        },
        /* 同一行只能判「中心同高」：正文行盒 16px、按钮 28px，两者底边对齐，
           顶边天然差半个高差（实测 −3.61），所以 |topΔ| < 2 这一条在
           「高度不同的两个盒子」上永远红 —— 它只在弹层那种「两边都做成 28px
           行盒」的场景才可用。这里用 centerY，并以「跨行数」作正面证据。 */
        stepSameLine: {
          expect: cw >= 1440 ? '|centerY差| < 2 且 段落只有 1 行' : '（窄宽允许折行，不判）',
          topDelta: step.topDelta,
          centerYDiff: step.centerDelta,
          oneLine: step.boxHeight < parseFloat(step.boxLineHeight) * 1.6,
          pass: cw >= 1440
            ? (Math.abs(step.centerDelta) < 2 && step.boxHeight < parseFloat(step.boxLineHeight) * 1.6)
            : null
        }
      }
    };
    if (process.env.NEGCTRL) {
      /* 负控：把徽标按钮强行并回同一行，断言必须变红。
         一条从没红过的断言，等于没有断言。 */
      out.contact[cw].negativeControl = await ev(`(function(){
        var b = document.querySelector('.qq-badge .copy-btn');
        var box = document.querySelector('.qq-badge');
        b.style.display = 'inline-flex';
        b.style.margin = '0 0 0 10px';
        var br = b.getBoundingClientRect(), bx = box.getBoundingClientRect();
        var cxDiff = Math.abs((br.left+br.right)/2 - (bx.left+bx.right)/2);
        var topDelta = +(br.top - box.querySelector('.mono').getBoundingClientRect().top).toFixed(2);
        var pass = cxDiff < 0.5 && topDelta > 20;
        b.style.display = ''; b.style.margin = '';
        return { centerXDiff:+cxDiff.toFixed(2), topDelta: topDelta, pass: pass };
      })()`);
    }
    await shot(path.join(ROOT, '.build', 'shots', `fe-badge-${cw}.png`), '.qq-badge');
    await shot(path.join(ROOT, '.build', 'shots', `fe-step-${cw}.png`), '.step__desc');
    }
  }

  /* ---------- 7) 折叠验收：多档「宽×高」，看是否需要滚动才看得到底部入口 ---------- */
  if (process.env.FOLD) {
    const pairs = JSON.parse(process.env.FOLD);
    out.fold = [];
    for (const [w, h] of pairs) {
      await setW(w, h);
      await openPage('index.html');
      await ev('document.getElementById("invite-modal").showModal()');
      await sleep(350);
      const M = `(function(){
        var d = document.querySelector('.invite-modal');
        var qr = document.querySelector('.invite-modal__qr');
        var foot = document.querySelector('.invite-modal__foot');
        var hint = document.querySelector('.invite-modal__hint');
        function m(){
          var dr = d.getBoundingClientRect(), fr = foot.getBoundingClientRect();
          var q = qr.getBoundingClientRect();
          var card = document.querySelector('.invite-modal__card');
          return {
            scrollH: d.scrollHeight, clientH: d.clientHeight,
            needScroll: d.scrollHeight - d.clientHeight,
            cardH: +card.getBoundingClientRect().height.toFixed(2),
            dialogH: +dr.height.toFixed(2),
            qrW: +q.width.toFixed(2), qrH: +q.height.toFixed(2),
            qrRatio: +(q.width / q.height).toFixed(4),
            hintH: +hint.getBoundingClientRect().height.toFixed(2),
            // 用户视角：不滚动时底部入口是否整条落在弹层可视区内
            footBottom: +fr.bottom.toFixed(2),
            dialogBottom: +dr.bottom.toFixed(2),
            footVisibleWithoutScroll: fr.bottom <= Math.min(dr.bottom, dr.top + d.clientHeight) + 0.5
          };
        }
        var after = m();
        // 同一页面内 A/B：把二维码强制回 216，量出「改之前」的样子
        qr.style.width = '216px'; qr.style.height = '216px';
        var before = m();
        qr.style.width = ''; qr.style.height = '';
        return { before216: before, after160: after };
      })()`;
      const r = await ev(M);
      r.width = w; r.height = h;
      out.fold.push(r);
      if (process.env.FOLD_SHOT && w === 390 && h === 790) {
        await shot(path.join(ROOT, '.build', 'shots', 'fe-fold-390x790.png'), '.invite-modal');
      }
      await ev('document.getElementById("invite-modal").close()');
    }
  }

  /* ---------- 8) 二分：不滚 / 入口可见 各自所需的最低视口高 ----------
     为什么要二分：我上一轮报的 740 / 770 其实是「我扫过的第一个通过点」，
     不是最小值 —— 报一个上界当阈值，下一个人会以为余量比实际小。 */
  if (process.env.BISECT) {
    const widths = JSON.parse(process.env.BISECT);
    out.bisect = [];
    for (const w of widths) {
      await setW(w, 790);
      await openPage('index.html');
      await ev('document.getElementById("invite-modal").showModal()');
      await sleep(300);
      const M = `(function(){
        var d = document.querySelector('.invite-modal');
        var foot = document.querySelector('.invite-modal__foot');
        var card = document.querySelector('.invite-modal__card');
        var dr = d.getBoundingClientRect(), fr = foot.getBoundingClientRect();
        return {
          cardH: +card.getBoundingClientRect().height.toFixed(2),
          clientH: d.clientHeight,
          needScroll: d.scrollHeight - d.clientHeight,
          footVisible: fr.bottom <= Math.min(dr.bottom, dr.top + d.clientHeight) + 0.5,
          footGap: +(Math.min(dr.bottom, dr.top + d.clientHeight) - fr.bottom).toFixed(2)
        };
      })()`;
      const at = async (h) => { await setW(w, h); await sleep(130); return ev(M); };

      const bisect = async (pred, lo, hi) => {
        // 找最小的 h 使 pred 为真；lo 必须为假、hi 必须为真
        for (let i = 0; i < 12 && hi - lo > 1; i++) {
          const mid = Math.floor((lo + hi) / 2);
          const r = await at(mid);
          if (pred(r)) hi = mid; else lo = mid;
        }
        return hi;
      };

      const base = await at(900);
      const rA = await bisect((r) => r.needScroll <= 0, 500, 900);
      const dA = await at(rA);
      const rB = await bisect((r) => r.footVisible === true, 500, 900);
      const dB = await at(rB);
      out.bisect.push({
        width: w, qr: base.cardH,
        minH_noScroll: rA, at_noScroll: dA,
        minH_footVisible: rB, at_footVisible: dB,
        cardH: base.cardH,
        offsetA: +(rA - base.cardH).toFixed(2),
        offsetB: +(rB - base.cardH).toFixed(2)
      });
      await ev('document.getElementById("invite-modal").close()');
    }
  }

  console.log(JSON.stringify(out, null, 1));
  cleanup();
  process.exit(0);
})().catch((e) => { console.error('FAILED:', e.stack || e.message); process.exit(1); });
