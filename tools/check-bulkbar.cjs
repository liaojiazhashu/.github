#!/usr/bin/env node
/* ==========================================================================
   后台批量条 · 确认链验证
   --------------------------------------------------------------------------
   验证的是「按下按钮之前，人有没有机会知道会发生什么」：

     · 一条都没勾时，勾选范围的动作是否被禁用（而不是点了才告诉我没选）；
     · 勾了 n 条之后，确认框里的数字是不是 n；
     · 每条危险动作的确认文案是不是在说后果（停止说「当前阶段结束后停」，
       隐藏说「详情页 404」），而不是一句空泛的「确定吗」。

   做法：CDP 打开真页面，把 window.confirm 换成记录器并**一律返回 false** ——
   所以无论文案怎么写，都不会真的提交，不会动到任何数据。

   用法：ADMIN_PASS=xxx node tools/check-bulkbar.cjs
   ========================================================================== */
'use strict';

const { openAdmin } = require('./lib/browser.cjs');

const fails = [];
function check(ok, msg, ...args) {
  let i = 0;
  const text = msg.replace(/%[ds]/g, () => String(args[i++]));
  console.log((ok ? '  PASS ' : '  FAIL ') + text);
  if (!ok) fails.push(text);
}

async function main() {
  const page = await openAdmin({ pass: process.env.ADMIN_PASS });

  try {
    const ready = await page.goto('/admin/works', '[data-bulkbar]');
    check(ready, '作品列表页打开、批量条渲染出来');

    console.log('== 1. 未勾选时的初始状态');
    const init = await page.evaluate(`(function () {
      var bar = document.querySelector('[data-bulkbar]');
      var btns = [].slice.call(document.querySelectorAll('button[form="bulkIds"]'));
      var filterBtns = [].slice.call(document.querySelectorAll('button[form="bulkFilter"]'));
      return {
        ids: btns.length,
        idsOff: btns.filter(function (b) { return b.disabled; }).length,
        filterOn: filterBtns.filter(function (b) { return !b.disabled; }).length,
        empty: bar.classList.contains('is-empty'),
        count: document.querySelector('[data-count]').textContent.trim()
      };
    })()`);

    check(init.ids > 0, '页面里有勾选范围的动作按钮（%d 个）', init.ids);
    check(init.idsOff === init.ids, '一条未勾时全部禁用（%d/%d）', init.idsOff, init.ids);
    check(init.empty && init.count === '0', '批量条处于空态、计数为 0');
    check(init.filterOn > 0, '按筛选的动作仍然可用（%d 个，不依赖勾选）', init.filterOn);

    console.log('== 2. 勾一条之后');
    const picked = await page.evaluate(`(function () {
      var cb = document.querySelector('input[data-check]');
      cb.checked = true;
      cb.dispatchEvent(new Event('change', { bubbles: true }));
      var btns = [].slice.call(document.querySelectorAll('button[form="bulkIds"]'));
      return {
        count: document.querySelector('[data-count]').textContent.trim(),
        on: btns.filter(function (b) { return !b.disabled; }).length,
        ids: btns.length
      };
    })()`);
    check(picked.count === '1', '计数跟着变（%s）', picked.count);
    check(picked.on === picked.ids, '动作按钮全部解禁（%d/%d）', picked.on, picked.ids);

    console.log('== 3. 各动作的确认文案（confirm 一律返回 false，不会真的提交）');
    const messages = await page.evaluate(`(function () {
      var map = {};
      var seq = 0;
      var orig = window.confirm;
      window.confirm = function (t) { map['#' + (++seq)] = t; return false; };
      var btns = [].slice.call(document.querySelectorAll('button[data-confirm]'))
        .filter(function (b) { return b.getAttribute('form') === 'bulkIds'; });
      btns.forEach(function (b) {
        var before = seq;
        b.click();
        map[b.value] = seq > before ? map['#' + seq] : null;
      });
      window.confirm = orig;
      return map;
    })()`);

    const has = (v) => typeof v === 'string' && v.length > 0;
    check(has(messages.enqueue) && messages.enqueue.indexOf('1') >= 0,
      '开始拆解：带条数与费用提示 → ' + messages.enqueue);
    check(has(messages.reenqueue) && messages.reenqueue.indexOf('1') >= 0,
      '重跑：带条数与覆盖提示 → ' + messages.reenqueue);
    check(has(messages.cancel) && messages.cancel.indexOf('当前阶段') >= 0,
      '停止：说明排队与在跑的区别 → ' + messages.cancel);
    check(has(messages.hide) && messages.hide.indexOf('404') >= 0,
      '隐藏作品：说明前台后果（404）→ ' + messages.hide);
    check(has(messages['hide-deck']) && messages['hide-deck'].indexOf('作品本身还在') >= 0,
      '隐藏拆解结果：与隐藏作品区分清楚 → ' + messages['hide-deck']);

    console.log('== 4. 取消勾选后回到初始态');
    const back = await page.evaluate(`(function () {
      var cbs = [].slice.call(document.querySelectorAll('input[data-check]'));
      cbs.forEach(function (cb) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); });
      var btns = [].slice.call(document.querySelectorAll('button[form="bulkIds"]'));
      return {
        count: document.querySelector('[data-count]').textContent.trim(),
        off: btns.filter(function (b) { return b.disabled; }).length,
        ids: btns.length
      };
    })()`);
    check(back.count === '0' && back.off === back.ids, '全部取消后重新禁用（%d/%d）', back.off, back.ids);
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
