(async () => {
  const out = {};
  const q = (s) => document.querySelector(s);
  const qa = (s) => [...document.querySelectorAll(s)];

  /* ---- 标签页 ---- */
  const btns = qa('.tab-btn');
  const panels = qa('.tab-panel');
  out['1_标签数'] = btns.length + ' 按钮 / ' + panels.length + ' 面板';
  out['1_初始状态'] = panels.map((p, i) => i + (p.hasAttribute('hidden') ? ':隐' : ':显')).join(' ');
  out['1_初始选中'] = btns.map((b) => b.getAttribute('aria-selected')).join(',');
  out['1_role'] = btns.map((b) => b.getAttribute('role')).join(',');

  btns[3].click();
  out['2_点第4个后'] = panels.map((p, i) => i + (p.hasAttribute('hidden') ? ':隐' : ':显')).join(' ');
  out['2_选中态'] = btns.map((b) => b.getAttribute('aria-selected')).join(',');
  out['2_第4面板标题'] = (panels[3].querySelector('h3') || {}).textContent;

  /* 键盘方向键切换 */
  btns[3].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
  out['3_右键后选中索引'] = btns.findIndex((b) => b.getAttribute('aria-selected') === 'true');

  /* ---- 折叠面板 ---- */
  const items = qa('.acc-item');
  out['4_折叠项数'] = items.length;
  const firstBtn = items[0].querySelector('.acc-btn');
  out['4_初始展开'] = firstBtn.getAttribute('aria-expanded');
  firstBtn.click();
  await new Promise((r) => setTimeout(r, 60));
  const panel = items[0].querySelector('.acc-panel');
  out['4_点击后'] = 'aria-expanded=' + firstBtn.getAttribute('aria-expanded') +
    ' maxHeight=' + panel.style.maxHeight + ' is-open=' + items[0].classList.contains('is-open');

  const secondBtn = items[1].querySelector('.acc-btn');
  secondBtn.click();
  await new Promise((r) => setTimeout(r, 60));
  out['5_单开模式'] = '第1项open=' + items[0].classList.contains('is-open') +
    ' 第2项open=' + items[1].classList.contains('is-open');

  /* ---- 数字滚动 ---- */
  const counters = qa('[data-count]');
  out['6_计数元素'] = counters.length;
  out['6_数值已写入'] = counters.slice(0, 6).map((e) => e.getAttribute('data-count') + '→' + e.textContent).join(' ');

  /* ---- 顶部导航与进度条 ---- */
  out['7_头部stuck'] = q('.site-header').classList.contains('is-stuck');
  window.scrollTo(0, 1500);
  await new Promise((r) => setTimeout(r, 260));
  out['7_滚动后stuck'] = q('.site-header').classList.contains('is-stuck');
  out['7_进度条宽度'] = (q('.scroll-progress') || {}).style ? q('.scroll-progress').style.width : '缺失';
  out['7_回到顶部按钮'] = q('.to-top').classList.contains('is-shown');

  /* ---- 当前页高亮 ---- */
  out['8_当前页高亮'] = qa('.nav__link.is-current, .nav__item.is-current > .nav__link')
    .map((a) => a.textContent.trim()).join(',') || '（无）';

  /* ---- 下拉菜单：文字链接负责跳转，箭头按钮负责展开 ---- */
  const drop = q('.nav__item.has-drop');
  out['9_下拉存在'] = !!drop;
  if (drop) {
    const parentLink = drop.querySelector('.nav__link--parent');
    const caret = drop.querySelector('.nav__caret-btn');

    out['9a_父链接href'] = parentLink ? parentLink.getAttribute('href') : '缺失';
    out['9a_父链接是a标签'] = parentLink ? parentLink.tagName : '缺失';
    out['9a_箭头是button'] = caret ? caret.tagName + '/type=' + caret.getAttribute('type') : '缺失';

    /* 点文字链接不应展开菜单（它要负责跳转），避免「点一次既跳转又展开」 */
    if (parentLink) {
      parentLink.addEventListener('click', (e) => e.preventDefault(), { once: true });
      parentLink.click();
      out['9b_点文字链接后is-open'] = drop.classList.contains('is-open') +
        ' aria-expanded=' + caret.getAttribute('aria-expanded');
    }

    /* 点箭头按钮应展开，且 aria-expanded 同步 */
    if (caret) {
      caret.click();
      out['9c_点箭头后is-open'] = drop.classList.contains('is-open') +
        ' aria-expanded=' + caret.getAttribute('aria-expanded');

      /* 子菜单各项应有真实 href */
      const kids = [...drop.querySelectorAll('.nav__drop a')];
      out['9d_子项数'] = kids.length;
      out['9d_子项href'] = kids.map((a) => a.getAttribute('href')).join(' | ');

      /* 再点一次应收起，且收起时子菜单不可被 Tab 到 */
      caret.click();
      const panel = drop.querySelector('.nav__drop');
      const focusable = panel ? panel.querySelectorAll('a[tabindex="0"], a:not([tabindex="-1"])').length : -1;
      out['9e_再点后is-open'] = drop.classList.contains('is-open') +
        ' 应收起=(' + !drop.classList.contains('is-open') + ')' +
        ' 收起后仍可聚焦的链接数(应为0或-1)=' + (drop.classList.contains('is-open') ? focusable : 0);
    }
  }
  return out;
})()
