'use strict';
/* scope="col" 渲染影响 A/B：带属性 vs 去掉属性，逐 th 比几何 */
const { openAdmin, sleep } = require('D:/AITEST/huangqi-scifi/tools/lib/browser.cjs');

const URL_AUTHOR = '/library/works/01M6BWP2T0JXKMGX30T7KKNAFT/author';

const ready = async (p) => {
  for (let i = 0; i < 30; i++) {
    const ok = await p.evaluate('(function(){return document.readyState==="complete" && document.styleSheets.length>0})()');
    if (ok) return true;
    await sleep(200);
  }
  return false;
};

(async () => {
  const p = await openAdmin({ user: 'ui_audit', pass: 'UIAudit#2026tmp', port: 9386, width: 1440, height: 900 });
  try {
    for (const w of [1440, 390]) {
      await p.session.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 500 });
      await p.goto(URL_AUTHOR, '.table');
      await ready(p);

      const r = await p.evaluate(`(function () {
        function snap() {
          var t = document.querySelector('.table');
          var out = [];
          var ths = t.querySelectorAll('thead th');
          for (var i = 0; i < ths.length; i++) {
            var th = ths[i], b = th.getBoundingClientRect(), cs = getComputedStyle(th);
            out.push({ i: i, t: th.textContent.trim(), w: Math.round(b.width * 100) / 100, h: Math.round(b.height * 100) / 100,
                       l: Math.round(b.left * 100) / 100, fs: cs.fontSize, pad: cs.paddingTop + '/' + cs.paddingBottom,
                       disp: cs.display, clip: th.scrollWidth - th.clientWidth, lines: cs.lineHeight });
          }
          var tw = Math.round(t.getBoundingClientRect().width * 100) / 100;
          var rows = t.querySelectorAll('tbody tr');
          var cells = rows.length ? rows[0].querySelectorAll('td').length : 0;
          return { tableW: tw, ths: out, rowCount: rows.length, firstRowCells: cells,
                   docH: document.documentElement.scrollHeight,
                   docOv: document.documentElement.scrollWidth - document.documentElement.clientWidth };
        }
        var before = snap();
        var ths = document.querySelectorAll('.table thead th');
        for (var i = 0; i < ths.length; i++) ths[i].removeAttribute('scope');
        void document.body.offsetHeight;
        var after = snap();
        return { before: before, after: after };
      })()`);

      const b = r.before, a = r.after;
      console.log('\n=== ' + w + '×900 · ' + URL_AUTHOR + ' ===');
      console.log('表格宽 ' + b.tableW + ' → ' + a.tableW + ' | 行数 ' + b.rowCount + ' | 首行 td ' + b.firstRowCells +
                  ' | 页高 ' + b.docH + ' → ' + a.docH + ' | 横向溢出 ' + b.docOv);
      let diff = 0;
      b.ths.forEach((x, i) => {
        const y = a.ths[i];
        const same = x.w === y.w && x.h === y.h && x.l === y.l;
        if (!same) diff++;
        console.log('   ' + (same ? 'OK  ' : 'DIFF') + ' "' + x.t + '" scope=col: w=' + x.w + ' h=' + x.h + ' left=' + x.l +
                    ' | 去属性后: w=' + y.w + ' h=' + y.h + ' left=' + y.l +
                    (x.disp === 'none' ? ' | display:none（≤560 隐藏第3列，设计如此）' : '') +
                    ' | 裁切=' + x.clip + ' 字号=' + x.fs + ' padding=' + x.pad);
      });
      console.log('   几何差异列数: ' + diff + (diff === 0 ? '（scope="col" 零渲染影响）' : ''));
    }
  } finally { await p.close(); }
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
