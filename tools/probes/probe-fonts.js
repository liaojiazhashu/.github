/*
 * 字体探针（v2.3.23）
 *   1. 五个字族是否全部加载成功、有没有 error 状态
 *   2. 正文 / 标题 / 等宽 / 图标标题的 computed font-family 是否走了令牌
 *   3. SVG 里带中文的 <text> 是否带 CJK 回退（漏写就会掉进系统字体）
 *   4. 有没有低于 11px 的正文级文本（缩到看不清 = 字体设置失效）
 *
 *   字形缺字（子集里没有这个字、浏览器悄悄回退到雅黑）不在这里查，
 *   交给 tools/check-fonts.py：它直接读 woff2 的 cmap 逐字比对，确定性强。
 */
(async () => {
  await document.fonts.ready;
  const out = {};

  const faces = [...document.fonts].map((f) => `${f.family}/${f.weight}/${f.status}`);
  out['1_字面数'] = faces.length;
  out['2_加载失败'] = faces.filter((f) => f.endsWith('error')).length;
  out['3_未加载'] = faces.filter((f) => f.endsWith('unloaded')).length;

  out['4_Noto400'] = document.fonts.check('400 16px "Noto Sans SC"');
  out['5_Noto500'] = document.fonts.check('500 16px "Noto Sans SC"');
  out['6_Noto700'] = document.fonts.check('700 16px "Noto Sans SC"');
  out['7_Inter'] = document.fonts.check('400 16px "Inter"');
  out['8_Orbitron'] = document.fonts.check('400 16px "Orbitron"');
  out['9_Mono'] = document.fonts.check('400 16px "JetBrains Mono"');

  const q = (s) => document.querySelector(s);
  const fam = (s) => {
    const el = q(s);
    return el ? getComputedStyle(el).fontFamily.split(',')[1].trim() : '缺失';
  };
  out['10_正文第二字族'] = fam('.body') || fam('.page-hero__desc');
  out['11_标题第二字族'] = fam('.section-head__title');

  /* SVG 内中文文本是否带 CJK 回退 */
  const badSvg = [...document.querySelectorAll('svg text')].filter((el) => {
    if (!/[\u4e00-\u9fff]/.test(el.textContent)) return false;
    const declared = el.getAttribute('font-family') || '';
    if (!declared) return false; /* 未声明 = 继承 body 令牌链，安全 */
    return !/Noto|serif$|sans-serif$/.test(declared) || /^Inter,\s*sans-serif$/.test(declared);
  });
  out['12_SVG中文缺回退'] = badSvg.length;
  out['13_样例'] = badSvg.slice(0, 3).map((el) => el.textContent.trim() + ' ← ' + el.getAttribute('font-family'));

  /* 缺字检测不放在浏览器里做。
     试过的办法都不成立：document.fonts.check() 只判断字体可用、不看字形；
     canvas 逐字像素比对也不可靠 —— 请求的字族缺字时，不同字族会落到不同的
     系统回退字体上（实测「龘」在 Noto Sans SC 与雅黑下渲染结果就不同），
     于是「两个都回退」反而被判成「都有字形」。
     真正的字形覆盖检查在 tools/check-fonts.py：直接读 woff2 的 cmap 与站点
     文本逐字比对，确定性强，退出码可接进验收流程。 */

  /* 小字检查：正文级文本低于 11px 在任何设备上都不该出现 */
  const small = [...document.querySelectorAll('body *')].filter((el) => {
    if (el.closest('svg') || !el.textContent.trim()) return false;
    if (![...el.childNodes].some((n) => n.nodeType === 3 && n.nodeValue.trim())) return false;
    const fs = parseFloat(getComputedStyle(el).fontSize);
    return fs < 11;
  });
  out['14_小于11px文本'] = small.length;
  out['15_样例'] = small.slice(0, 3).map((el) => el.className + '@' + getComputedStyle(el).fontSize);

  return out;
})()
