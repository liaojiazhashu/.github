'use strict';
/* 回归 2：works 列表非空 + author 页 .table 表头（scope=col 后） */
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

const ready = async (p) => {
  for (let i = 0; i < 30; i++) {
    const ok = await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()');
    if (ok) return true;
    await sleep(200);
  }
  return false;
};

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9385, width: 1440, height: 900 });
  try {
    for (const w of [1440, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto('/library/works?sort=title', '.lede');
      await ready(p);
      const r = await p.evaluate(`(function () {
        var all = document.querySelectorAll('a[href]');
        var n = 0, sample = null;
        for (var i = 0; i < all.length; i++) {
          var h = all[i].getAttribute('href') || '';
          if (h.indexOf('/library/works/') === 0) { n++; if (!sample) sample = all[i].textContent.trim().slice(0, 20); }
        }
        return { workLinks: n, sample: sample,
                 docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                 hasTable: !!document.querySelector('.table') };
      })()`);
      console.log('/library/works @' + w + ': ' + JSON.stringify(r));
    }

    await p.session.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await p.goto('/library/works', '.lede');
    await ready(p);
    const workHref = await p.evaluate(`(function () {
      var all = document.querySelectorAll('a[href]');
      for (var i = 0; i < all.length; i++) {
        var h = all[i].getAttribute('href') || '';
        if (h.indexOf('/library/works/') === 0) return h;
      }
      return null;
    })()`);
    console.log('work:', workHref);
    await p.goto(workHref, '.wrap');
    await ready(p);
    const authorHref = await p.evaluate(`(function () {
      var all = document.querySelectorAll('a[href]');
      var out = [];
      for (var i = 0; i < all.length; i++) {
        var h = all[i].getAttribute('href') || '';
        if (h.indexOf('/library/authors/') === 0 || h.indexOf('/library/author/') === 0) out.push(h);
      }
      return out.length ? out[0] : null;
    })()`);
    console.log('author:', authorHref);
    

    for (const w of [1440, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      const ok = await p.goto('/library/works/01M6BWP2T0JXKMGX30T7KKNAFT/author', 'body');
      await ready(p);
      const tb = await p.evaluate(`(function () {
        var t = document.querySelector('.table');
        if (!t) return { noTable: true };
        var ths = [];
        var list = t.querySelectorAll('thead th');
        for (var i = 0; i < list.length; i++) {
          var th = list[i], cs = getComputedStyle(th), b = th.getBoundingClientRect();
          var lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.4;
          ths.push({ t: th.textContent.trim().slice(0, 10), scope: th.getAttribute('scope'),
                     w: Math.round(b.width), lines: Math.round(b.height / lh),
                     clip: th.scrollWidth - th.clientWidth, ta: cs.textAlign, ws: cs.whiteSpace });
        }
        var rows = t.querySelectorAll('tbody tr');
        var wr = t.closest('.table-wrap') || t.parentElement;
        return { n: ths.length, thens: ths, rowCount: rows.length,
                 cells: rows.length ? rows[0].querySelectorAll('td').length : 0,
                 wrapOv: wr.scrollWidth - wr.clientWidth,
                 docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth };
      })()`);
      if (tb.noTable) { console.log(authorHref + ' @' + w + ': 无 .table'); continue; }
      console.log('\n' + authorHref + ' @' + w + ' 列=' + tb.n + ' 行=' + tb.rowCount + ' 首行td=' + tb.cells + ' 容器溢出=' + tb.wrapOv + ' 页面溢出=' + tb.docOv + ' goto=' + ok);
      tb.thens.forEach((x) => {
        console.log('   ' + (x.clip > 1 || x.lines > 1 ? 'FAIL' : 'OK  ') + ' "' + x.t + '" scope=' + x.scope +
                    ' w=' + x.w + ' 行数=' + x.lines + ' 裁切=' + x.clip + ' 对齐=' + x.ta + ' ws=' + x.ws);
      });
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
