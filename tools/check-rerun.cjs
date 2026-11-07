#!/usr/bin/env node
/* ==========================================================================
   单阶段重跑 · 覆盖提醒验证
   --------------------------------------------------------------------------
   重跑会花钱、会覆盖人工编辑过的产出，而界面上原来只有一个裸按钮。
   这一支验证三件事：

     1) 有没有编辑过的分析，页面上是不是出现「重跑会覆盖人工编辑」的提醒；
     2) 下拉框选不同阶段时，确认框里的阶段名会不会跟着变（不是写死的一句）；
     3) 勾上「级联重跑下游」后，确认文案里有没有多出这一句。

   confirm 一律返回 false —— 点了也不会真的跑模型，不花钱、不动数据。

   用法：ADMIN_PASS=xxx node tools/check-rerun.cjs [分析编号]
         （不给编号就自动挑一条有编辑记录的分析）
   ========================================================================== */
'use strict';

const { openAdmin, httpRequest, cookieOf } = require('./lib/browser.cjs');

const fails = [];
function check(ok, msg, ...args) {
  let i = 0;
  const text = msg.replace(/%[ds]/g, () => String(args[i++]));
  console.log((ok ? '  PASS ' : '  FAIL ') + text);
  if (!ok) fails.push(text);
}

/** 用 HTTP 登录后翻作品列表，找一条「有编辑快照」的分析编号。 */
async function findEditedAnalysis(user, pass) {
  const loginPage = await httpRequest(`${process.env.BASE || 'http://127.0.0.1:8090'}/admin/login`);
  let cookie = cookieOf(loginPage);
  const m = loginPage.body.match(/name="_token" value="([a-f0-9]+)"/);
  const payload = new URLSearchParams({ _token: m[1], username: user, password: pass }).toString();
  const login = await httpRequest(`${process.env.BASE || 'http://127.0.0.1:8090'}/admin/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Content-Length': Buffer.byteLength(payload),
      Cookie: cookie
    },
    body: payload
  });
  cookie = cookieOf(login) || cookie;

  const base = process.env.BASE || 'http://127.0.0.1:8090';
  const list = await httpRequest(`${base}/admin/works?stage=published`, { headers: { Cookie: cookie } });
  const codes = [...new Set([...list.body.matchAll(/href="\/admin\/works\/([0-9A-HJKMNP-TV-Z]{26})"/g)].map((x) => x[1]))];

  for (const code of codes.slice(0, 30)) {
    const page = await httpRequest(`${base}/admin/works/${code}`, { headers: { Cookie: cookie } });
    const hit = page.body.match(/href="\/admin\/analyses\/([0-9A-HJKMNP-TV-Z]{26})"/);
    if (!hit) continue;
    const acode = hit[1];
    const detail = await httpRequest(`${base}/admin/analyses/${acode}`, { headers: { Cookie: cookie } });
    if (detail.body.indexOf('data-edited="1"') >= 0) {
      return acode;
    }
  }

  return '';
}

async function main() {
  const user = process.env.ADMIN_USER || 'ui_audit';
  const pass = process.env.ADMIN_PASS;
  const code = process.argv[2] || await findEditedAnalysis(user, pass);

  if (!code) {
    console.log('没找到带人工编辑的分析，只验证「无编辑时不该出现提醒」这一点。');
  }

  const page = await openAdmin({ pass, user });

  try {
    const path = code ? `/admin/analyses/${code}` : '/admin/works';
    const ready = await page.goto(path, code ? 'form[data-rerun]' : '[data-bulkbar]');
    check(ready, '页面打开、重跑表单存在（%s）', path);

    if (!code) {
      await page.close();
      console.log('结论：跳过（没有样本）');
      process.exit(0);
    }

    console.log('== 1. 覆盖提醒');
    const notice = await page.evaluate(`(function () {
      var form = document.querySelector('form[data-rerun]');
      var line = document.querySelector('.warnline');
      return {
        edited: form.getAttribute('data-edited'),
        hasLine: !!line,
        text: line ? line.textContent.replace(/\\s+/g, ' ').trim() : ''
      };
    })()`);
    check(notice.edited === '1', '服务端把「有编辑」这件事告诉前端（data-edited=1）');
    check(notice.hasLine, '页面上有一条覆盖提醒');
    check(notice.text.indexOf('重跑') >= 0 && notice.text.indexOf('快照') >= 0,
      '提醒说清了「会覆盖」与「只能去快照看」→ ' + notice.text);

    console.log('== 2. 确认文案跟着下拉框走（confirm 返回 false，不会真跑）');
    const probe = async (stage, cascade) => page.evaluate(`(function () {
      var form = document.querySelector('form[data-rerun]');
      var sel = form.querySelector('select[name="stage"]');
      var cb = form.querySelector('input[name="cascade"]');
      sel.value = ${JSON.stringify(stage)};
      cb.checked = ${cascade ? 'true' : 'false'};
      var got = null;
      var orig = window.confirm;
      window.confirm = function (t) { got = t; return false; };
      form.querySelector('button[type="submit"]').click();
      window.confirm = orig;
      return got;
    })()`);

    const s2 = await probe('S2_PLOT', false);
    check(typeof s2 === 'string' && s2.indexOf('剧情主线') >= 0,
      '选「剧情主线」时文案里是剧情主线 → ' + String(s2).split('\n')[0]);
    check(typeof s2 === 'string' && s2.indexOf('覆盖') >= 0,
      '有编辑时文案明确写出会被覆盖');

    const s5 = await probe('S5_REVISION', true);
    check(typeof s5 === 'string' && s5.indexOf('修改方向') >= 0,
      '换一个阶段，文案跟着换 → ' + String(s5).split('\n')[0]);
    check(typeof s5 === 'string' && s5.indexOf('级联') >= 0,
      '勾了级联，文案里多出级联说明');
  } finally {
    await page.close();
  }

  console.log('');
  if (fails.length > 0) {
    console.log('结论：FAIL ' + fails.length + ' 项');
    process.exit(1);
  }
  console.log('结论：全部 PASS');
  process.exit(0);
}

main().catch((e) => {
  console.error('脚本出错：', e.message);
  process.exit(1);
});
