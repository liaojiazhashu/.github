'use strict';
/* 08:48 有人给 07-responsive.css 加了「≤399 收次要 CTA」并重建了产物。
   这里核新构建的三件事：
   (1) 各宽度还有没有横向溢出、菜单按钮是否完整在视口内；
   (2) ≤399 收掉顶栏 CTA 之后，入群/联系路径是否还有可见入口；
   (3) 顺带确认 ≤399 与 400 两侧的行为差。 */
const http = require('http'), fs = require('fs'), os = require('os'), path = require('path');
const { spawn } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), PORT = 8134, DEBUG = 9389;
const WIDTHS = (process.env.WIDTHS || '320,330,360,375,390,399,400,414,480').split(',').map(Number);
const PAGES = (process.env.PAGES || 'index').split(',');
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  const srv = http.createServer((req, res) => {
    const f = path.join(ROOT, req.url.split('?')[0] === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]));
    if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    fs.createReadStream(f).pipe(res);
  }).listen(PORT, '127.0.0.1');
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-final-'));
  const edge = ['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'].find((p) => fs.existsSync(p));
  const child = spawn(edge, ['--headless=new', `--remote-debugging-port=${DEBUG}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
  let ws;
  for (let i = 0; i < 60; i++) {
    try { const v = await (await fetch(`http://127.0.0.1:${DEBUG}/json/version`)).json(); if (v.webSocketDebuggerUrl) { ws = new WebSocket(v.webSocketDebuggerUrl); break; } } catch { /* wait */ }
    await sleep(300);
  }
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  let seq = 0; const pend = new Map();
  ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { const { resolve, reject } = pend.get(m.id); pend.delete(m.id); m.error ? reject(new Error(m.error.message)) : resolve(m.result); } });
  const send = (method, params = {}, sid) => new Promise((resolve, reject) => { const id = ++seq; pend.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params, ...(sid ? { sessionId: sid } : {}) })); setTimeout(() => { if (pend.has(id)) { pend.delete(id); reject(new Error('timeout ' + method)); } }, 30000); });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const S = (m, p) => send(m, p, sessionId);
  let ctx = null;
  ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.method === 'Runtime.executionContextsCleared') ctx = null; if (m.method === 'Runtime.executionContextCreated' && m.params.context.auxData && m.params.context.auxData.isDefault) ctx = m.params.context.id; });
  await S('Page.enable'); await S('Runtime.enable');
  const ev = async (expr) => {
    const call = (c) => S('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true, ...(c && ctx ? { contextId: ctx } : {}) });
    let r; try { r = await call(true); } catch { ctx = null; r = await call(false); }
    if (r.exceptionDetails) throw new Error('JS: ' + (r.exceptionDetails.exception && r.exceptionDetails.exception.description || r.exceptionDetails.text));
    return r.result ? r.result.value : undefined;
  };
  const M = `(function(){
    var de = document.documentElement, cw = de.clientWidth;
    var toggle = document.querySelector('.nav-toggle');
    var tr = toggle.getBoundingClientRect(), tcs = getComputedStyle(toggle);
    function vis(el){ if(!el) return false; var cs = getComputedStyle(el), r = el.getBoundingClientRect(); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }
    var opens = [].filter.call(document.querySelectorAll('[data-invite-open]'), vis);
    var contactLinks = [].filter.call(document.querySelectorAll('a[href*="contact.html#invite"]'), vis);
    return {
      clientW: cw, scrollW: de.scrollWidth, overflowW: de.scrollWidth - cw,
      toggleDisplay: tcs.display, toggleLeft: +tr.left.toFixed(2), toggleRight: +tr.right.toFixed(2),
      toggleClipped: +(tr.right - cw).toFixed(2),
      headerCtaVisible: vis(document.querySelector('.site-header__actions .btn--primary')),
      actionsW: +document.querySelector('.site-header__actions').getBoundingClientRect().width.toFixed(2),
      ctaW: (function () { var c = document.querySelector('.site-header__actions .btn--primary'); return c ? +c.getBoundingClientRect().width.toFixed(2) : null; })(),
      ctaPad: (function () { var c = document.querySelector('.site-header__actions .btn--primary'); return c ? getComputedStyle(c).paddingLeft + '/' + getComputedStyle(c).paddingRight : null; })(),
      headerH: +document.querySelector('.site-header__inner').getBoundingClientRect().height.toFixed(2),
      visibleInviteOpen: opens.length,
      firstInviteOpenText: opens.length ? (opens[0].textContent || '').trim().slice(0, 14) : null,
      visibleContactLinks: contactLinks.length,
      firstContactText: contactLinks.length ? (contactLinks[0].textContent || '').trim().slice(0, 14) : null
    };
  })()`;
  const rows = [];
  for (const page of PAGES) for (const w of WIDTHS) {
    await S('Emulation.setDeviceMetricsOverride', { width: w, height: 790, deviceScaleFactor: 1, mobile: false });
    await S('Page.navigate', { url: `http://127.0.0.1:${PORT}/${page}.html` });
    for (let i = 0; i < 60; i++) { if (await ev('document.readyState==="complete" && document.styleSheets.length>0')) break; await sleep(200); }
    await sleep(250);
    if (process.env.OVERRIDE) {
      const ov = process.env.OV || 'v1';
      const body = ov === 'v1'
        ? '.site-header__inner { gap: 12px; } .site-header__actions { gap: 8px; } .site-header__actions .btn--primary { display: inline-flex !important; }'
        : '.site-header__inner { gap: 12px; } .site-header__actions { gap: 8px; } .brand__img { width: 116px; } .site-header__actions .btn--primary { display: inline-flex !important; }';
      await ev(`(function(){
        if (document.getElementById('__fe_ov')) return;
        var st = document.createElement('style'); st.id = '__fe_ov';
        st.textContent = '@media (max-width: 479px) { ' + ${JSON.stringify(body)} + ' }';
        document.head.appendChild(st);
      })()`);
      await sleep(200);
    }
    rows.push({ page, width: w, ...(await ev(M)) });
  }
  console.log(JSON.stringify(rows, null, 1));
  ws.close(); child.kill(); srv.close(); process.exit(0);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
