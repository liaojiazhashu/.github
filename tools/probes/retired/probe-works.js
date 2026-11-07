/* ==========================================================================
   作品库功能探针
   --------------------------------------------------------------------------
   断言筛选 / 检索 / 排序 / 分页四条链路，并顺带核对特效画布确实画了东西。

   数据背景：`js/works-data.js` 共 18 条示例，期次分布 21 期 ×3 / 22 期 ×3 /
   23 期 ×3 / 24 期 ×3 / 25 期 ×6。**期次筛选项在 v1.3 已从 9–13 精简为
   21–25**，本探针统一用第 25 期，不再引用已删除的「第 13 期」。
   首屏只渲染 9 张卡，「加载更多」后为 18 张。

   用法：
     PAGES=works WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-works.js node tools/shoot.cjs
   ========================================================================== */
(async () => {
  const out = {};
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => [...document.querySelectorAll(s)];
  const grid = $('[data-works-grid]');
  const count = () => (grid ? grid.querySelectorAll('.work-card').length : -1);
  const headCount = () => (($('[data-works-count]') || {}).textContent || '').trim();
  const firstTitle = () => {
    const t = grid && grid.querySelector('.work-card__title');
    return t ? t.textContent.trim() : '（无）';
  };

  out['0_期次筛选项'] = $$('input[data-filter="issue"]').map((c) => c.value).join(',');
  out['1_初始卡片数'] = count() + ' / 统计「' + headCount() + '」';

  /* 勾选第 25 期（当前期，共 6 篇） */
  const cb = $('input[data-filter="issue"][value="25"]');
  if (!cb) { out['2_选第25期'] = '⚠ 找不到期次筛选控件'; return out; }
  cb.checked = true;
  cb.dispatchEvent(new Event('change', { bubbles: true }));
  out['2_选第25期'] = count() + ' 篇 / 统计「' + headCount() + '」';
  out['2_已选条件区'] = ($('[data-works-active]') || {}).textContent
    ? $('[data-works-active]').textContent.replace(/\s+/g, ' ').trim().slice(0, 60) : '（无）';

  /* 叠加子类型：赛博朋克（第 25 期内有几条，取交集） */
  const cb2 = $('input[data-filter="type"][value="赛博朋克"]');
  if (cb2) {
    cb2.checked = true;
    cb2.dispatchEvent(new Event('change', { bubbles: true }));
    out['3_叠加赛博朋克'] = count() + ' 篇 / 统计「' + headCount() + '」';
    out['3_首条标题'] = firstTitle();
  } else {
    out['3_叠加赛博朋克'] = '⚠ 找不到该子类型筛选控件';
  }

  /* 清空条件 */
  const clr = $('[data-works-clear]');
  if (clr) {
    clr.click();
    out['4_清空后'] = count() + ' 篇 / 统计「' + headCount() + '」';
    out['4_复选框已重置'] = $$('input[data-filter]').every((c) => !c.checked);
  }

  /* 关键词检索 */
  const si = $('[data-works-search]');
  if (si) {
    si.value = '潮汐';
    si.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 120));
    out['5_搜索「潮汐」'] = count() + ' 篇 → ' + firstTitle();

    /* 无结果空状态 */
    si.value = '不存在的关键词zzz';
    si.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 120));
    const emp = $('[data-works-empty]');
    out['6_无结果'] = 'grid hidden=' + (grid ? grid.hidden : '?') +
      ' 空状态可见=' + (emp ? !emp.hidden : '?');

    /* 恢复 */
    si.value = '';
    si.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 120));
  }

  /* 排序：字数最多 */
  const sortBtn = $('.sort-btn[data-sort="words-desc"]');
  if (sortBtn) {
    sortBtn.click();
    const titles = $$('.work-card__title').slice(0, 2).map((t) => t.textContent.trim());
    out['7_字数最多排序'] = '首条=' + titles[0] + ' | 第二=' + (titles[1] || '（无）');
    out['7_按钮态'] = $$('.sort-btn')
      .map((b) => b.getAttribute('data-sort') + ':' + b.getAttribute('aria-pressed')).join(' ');
  }

  /* 加载更多 */
  const more = $('[data-works-more]');
  if (more) {
    out['8_加载更多可见'] = !more.parentNode.hidden;
    more.click();
    out['8_点击后卡片数'] = count() + '（应为 18）';
  }

  /* 特效画布 */
  const cv = $('.bg-canvas');
  out['9_场景'] = document.body.getAttribute('data-scene');
  if (cv) {
    out['9_画布'] = cv.width + '×' + cv.height + '（CSS ' + cv.clientWidth + '×' + cv.clientHeight + '）';
    const c2 = document.createElement('canvas');
    c2.width = cv.width; c2.height = cv.height;
    const g = c2.getContext('2d');
    g.drawImage(cv, 0, 0);
    const d = g.getImageData(0, 0, cv.width, cv.height).data;
    let lit = 0, maxA = 0, sum = 0, n = 0;
    for (let i = 0; i < d.length; i += 4 * 97) {
      if (d[i + 3] > 8) lit++;
      if (d[i + 3] > maxA) maxA = d[i + 3];
      sum += 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
      n++;
    }
    out['9_画布非空采样'] = '点亮像素 ' + lit + ' / ' + n +
      '，最大 alpha ' + maxA + '，平均亮度 ' + (sum / n).toFixed(1);
  }
  return out;
})()
