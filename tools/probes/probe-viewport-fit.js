/*
 * v2.3.8 首屏满屏探针：断言「页顶时看不到下一屏内容」。
 * 检查项：
 *   1. 首屏容器（.hero / .page-hero）底边是否 ≥ 视口高度（允许 1px 误差）
 *   2. 首屏之后的第一个区块顶边是否 ≥ 视口高度（即不露头）
 *   3. 文档总高 ≥ 视口高度（短页面也要填满一屏）
 */
(async () => {
  const out = {};
  const vh = window.innerHeight;
  const first = document.querySelector('.hero') || document.querySelector('.page-hero');
  out['1_视口高'] = vh;
  if (!first) { out['1_首屏容器'] = '缺失'; return out; }
  out['1_首屏容器'] = first.className.split(' ')[0];
  const r = first.getBoundingClientRect();
  out['2_首屏底边'] = Math.round(r.bottom) + '（应 ≥ ' + vh + '）';
  out['2_是否满屏'] = r.bottom >= vh - 1;

  const next = first.nextElementSibling;
  if (next) {
    const nr = next.getBoundingClientRect();
    out['3_下一区块顶边'] = Math.round(nr.top) + '（应 ≥ ' + vh + '）';
    out['3_是否露头'] = nr.top < vh - 1;
  } else {
    out['3_下一区块'] = '（首屏是最后一个区块）';
  }

  const docH = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);
  out['4_文档总高'] = docH;
  out['4_是否填满一屏'] = docH >= vh;
  return out;
})()
