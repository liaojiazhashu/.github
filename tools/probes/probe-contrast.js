/*
 * 对比度探针（v2.3.23）
 *   逐个取可见文本元素的「文字色 / 实际底色」，按 WCAG 算对比度。
 *   阈值：正文 4.5:1，大字号（≥24px 或 ≥18.66px 且 700）3:1。
 *   只检查能找到不透明底色的元素 —— 压在 SVG / 星空层上的文字算不出来，
 *   一律跳过（那些位置的对比度靠截图肉眼验收）。
 *
 *   为什么要有这一条：色块类问题最典型的表现不是「看不见」，而是
 *   「勉强能看见」—— 比如白字压冰青只有 2.1:1，页面上看着像糊了一层。
 */
(async () => {
  /* .section 带 content-visibility:auto，未滚到的区块不会被排版，
     getBoundingClientRect() 全是 0，会被当成「不可见」跳过。
     （直接测量会把整页只查出前一两屏，看不出问题。） */
  const st = document.createElement('style');
  st.textContent = '.section{content-visibility:visible!important}';
  document.head.appendChild(st);
  void document.body.offsetHeight;
  await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

  const parse = (c) => {
    const m = c.match(/[\d.]+/g);
    if (!m) return null;
    return { r: +m[0], g: +m[1], b: +m[2], a: m[3] === undefined ? 1 : +m[3] };
  };
  const lum = ({ r, g, b }) => {
    const f = (v) => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const over = (fg, bg) => ({
    r: fg.r * fg.a + bg.r * (1 - fg.a),
    g: fg.g * fg.a + bg.g * (1 - fg.a),
    b: fg.b * fg.a + bg.b * (1 - fg.a),
    a: 1
  });
  const bgOf = (el) => {
    let node = el;
    let acc = null;
    /* 一直找到 <html>：页面底色画在 html 上（body 是透明的），
       停在 body 之前会把「白底/墨底上的普通正文」全部漏掉。 */
    while (node) {
      const c = parse(getComputedStyle(node).backgroundColor);
      if (c && c.a > 0.85) return acc ? over(acc, c) : c;
      if (c && c.a > 0 && !acc) acc = c;
      node = node.parentElement;
    }
    return null;
  };

  const bad = [];
  let checked = 0;
  const els = [...document.querySelectorAll('main p, main h1, main h2, main h3, main h4, main li, main span, main b, main td, main th, main a')];
  els.forEach((el) => {
    /* 装饰性内容不计：幽灵序号、图例色点等 aria-hidden 的东西本来就不该读 */
    if (el.closest('[aria-hidden="true"]')) return;
    if (![...el.childNodes].some((n) => n.nodeType === 3 && n.nodeValue.trim())) return;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) return;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.opacity === '0') return;
    /* 标题的渐变裁切字：文字用 background-clip:text + 透明填充色画的，
       取到的 color 是透明的，算出来必然 1:1，属误报。 */
    if (cs.webkitTextFillColor === 'rgba(0, 0, 0, 0)' || cs.webkitTextFillColor === 'transparent') return;
    const bg = bgOf(el);
    if (!bg) return;
    const fg = parse(cs.color);
    if (!fg) return;
    const solid = fg.a < 1 ? over(fg, bg) : fg;
    const l1 = lum(solid);
    const l2 = lum(bg);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const size = parseFloat(cs.fontSize);
    const large = size >= 24 || (size >= 18.66 && +cs.fontWeight >= 700);
    const need = large ? 3 : 4.5;
    checked += 1;
    if (ratio < need) {
      bad.push(
        ((el.className || el.tagName) + '').slice(0, 28) +
          ' ' + ratio.toFixed(2) + ':1 <' + need + ' @' + size.toFixed(0) + 'px 「' +
          el.textContent.trim().slice(0, 12) + '」'
      );
    }
  });

  return {
    '主题': document.documentElement.getAttribute('data-theme') || 'night',
    '检查元素': checked,
    '不达标数': bad.length,
    '最差示例': bad.slice(0, 8)
  };
})()
