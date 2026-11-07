'use strict';
/* UX 独立复测：邀请弹层的「断点两侧对设备高度的要求是否不同」
   ------------------------------------------------------------
   要回答的问题不是「某个宽高下过不过」，而是：
     同一张页面，在相邻的两个宽度上，**要让弹层不滚所需的最低视口高**差多少？
   判据（用户视角，不是容器视角）：
     判据A  dlg.scrollHeight - dlg.clientHeight <= 0   —— 弹层容器不滚
     判据B  .invite-modal__foot a 的 bottom <= 弹层可视区 bottom（scrollTop=0）—— 底部入口在首屏
   B 才是用户要的（「已有邀请码？进入在线阅读」能不能一眼看到），A 只是必要条件。
   二分搜索各自的最低视口高（H 单调 → 二分成立；每一步都打印，便于复核）。
   用法：node .build/ux-modal-height.cjs
*/
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PORT = 8126;
const DEBUG = 9343;
const ORIGIN = `http://127.0.0.1:${PORT}`;
const WIDTHS = (process.env.WIDTHS || '390,414,479,480,481,482').split(',').map((s) => parseInt(s, 10));

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
    on(method, fn) {
      const list = events.get(method) || [];
      list.push(fn);
      events.set(method, list);
    }
  };
}

(async () => {
  const srv = await startServer();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-ux-'));
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
      const d = r.exceptionDetails;
      const desc = (d.exception && (d.exception.description || d.exception.value)) || d.text || '';
      throw new Error('页面异常: ' + desc);
    }
    return r.result ? r.result.value : undefined;
  };

  const setVp = (w, h, mobile) => S('Emulation.setDeviceMetricsOverride',
    { width: w, height: h, deviceScaleFactor: 1, mobile });

  const openDialog = async () => ev(`(function () {
    var d = document.getElementById('invite-modal');
    if (!d) return 'no-dialog';
    if (!d.open) d.showModal();
    return d.open ? 'open' : 'failed';
  })()`);

  /* 一次量完判据A/B 以及用于解释的中间量。scrollTop 先归零，
     否则「入口是否可见」量到的是上一次滚动的结果。 */
  const probe = `(function () {
    var d = document.getElementById('invite-modal');
    if (!d || !d.open) return { err: 'dialog 未打开' };
    d.scrollTop = 0;
    var card = d.querySelector('.invite-modal__card');
    var qr = d.querySelector('.invite-modal__qr');
    var foot = d.querySelector('.invite-modal__foot a');
    var db = d.getBoundingClientRect();
    var fb = foot.getBoundingClientRect();
    var qb = qr.getBoundingClientRect();
    var cs = getComputedStyle(d);
    return {
      vw: window.innerWidth, vh: window.innerHeight,
      qrW: +qb.width.toFixed(1), qrH: +qb.height.toFixed(1),
      qrCssW: cs.getPropertyValue('--x') ? '' : getComputedStyle(qr).width,
      dlgH: +db.height.toFixed(2),
      dlgTop: +db.top.toFixed(2), dlgBottom: +db.bottom.toFixed(2),
      dlgClientH: d.clientHeight, dlgScrollH: d.scrollHeight,
      cardH: +card.getBoundingClientRect().height.toFixed(2),
      cardScrollH: card.scrollHeight, cardClientH: card.clientHeight,
      footBottom: +fb.bottom.toFixed(2),
      /* 判据A：容器不滚 */
      A_noScroll: d.scrollHeight - d.clientHeight <= 0,
      /* 判据B：底部入口完整落在弹层可视区内（scrollTop=0） */
      B_footVisible: fb.bottom <= db.bottom + 0.5 && fb.top >= db.top - 0.5,
      footerOver: +(fb.bottom - db.bottom).toFixed(2),
      overflow: cs.overflowY
    };
  })()`;

  const rows = [];
  for (const w of WIDTHS) {
    await setVp(w, 900, w < 500);
    await S('Page.navigate', { url: `${ORIGIN}/index.html` });
    await sleep(350);
    for (let i = 0; i < 40; i++) {
      if (await ev('document.readyState === "complete" && document.styleSheets.length > 0')) break;
      await sleep(150);
    }
    await sleep(200);
    const st = await openDialog();
    if (st !== 'open') throw new Error(`${w}: 弹层未打开 ${st}`);

    // 复用同一个弹层调视口，避免反复导航；showModal 在 resize 后仍然有效
    const at = async (h) => { await setVp(w, h, w < 500); await sleep(90); return ev(probe); };

    // 固定量：QR 尺寸与卡片高度（与视口高无关，取 h=1000 一次）
    const ref = await at(1000);

    // 二分 H ∈ [600, 1000]，找判据A/B 首次成立的最低视口高
    const search = async (key) => {
      let lo = 600, hi = 1000;
      const hiR = await at(hi);
      if (!hiR[key]) return { min: null, note: '1000 仍不成立' };
      const loR = await at(lo);
      if (loR[key]) return { min: lo, note: '600 已成立（下界之外）' };
      while (hi - lo > 1) {
        const mid = Math.floor((lo + hi) / 2);
        const r = await at(mid);
        if (r[key]) hi = mid; else lo = mid;
      }
      return { min: hi, note: `边界 ${lo} 不成立 / ${hi} 成立` };
    };

    const a = await search('A_noScroll');
    const b = await search('B_footVisible');

    rows.push({
      width: w,
      mobileFlag: w < 500,
      qr: `${ref.qrW}×${ref.qrH}`,
      cardH: ref.cardH,
      dlgClientH_at1000: ref.dlgClientH,
      overflowY: ref.overflow,
      minH_A: a.min, noteA: a.note,
      minH_B: b.min, noteB: b.note,
      edgeB: b.min ? (await at(b.min)).footerOver : null,
      edgeB_minus1: b.min ? (await at(b.min - 1)).footerOver : null
    });
    console.log(`${w} 宽 → qr ${ref.qrW}×${ref.qrH} / cardH ${ref.cardH} / A≥${a.min} / B≥${b.min}`);
  }

  console.log('\n=== 汇总 ===');
  console.log('width | qr | cardH | overflow-y | minH(A 不滚) | minH(B 入口可见) | B 边界余量');
  for (const r of rows) {
    console.log([r.width, r.qr, r.cardH, r.overflowY, r.minH_A, r.minH_B,
      `${r.edgeB_minus1} → ${r.edgeB}`].join(' | '));
  }
  fs.writeFileSync(path.join(ROOT, '.build', 'ux-modal-height.json'), JSON.stringify(rows, null, 2));
  ws.close();
  process.exit(0);
})().catch((e) => { console.error('失败：', e.message); process.exit(1); });
