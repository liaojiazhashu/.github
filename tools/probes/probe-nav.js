/* ==========================================================================
   导航下拉探针
   --------------------------------------------------------------------------
   验证「文字链接负责跳转 + 箭头按钮负责展开」这一拆分是否成立：
     · 父级文字链接必须是 <a>，且 href 指向介绍区块锚点
     · 点击文字链接不得展开子菜单（避免「一次点击既跳转又展开」）
     · 点击箭头按钮才展开，且 aria-expanded 同步
     · 子菜单每项都应有真实 href
     · 键盘 Enter / Space 应能展开箭头按钮
   用法：
     PAGES=index WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-nav.js node tools/shoot.cjs
   ========================================================================== */
(async () => {
  const out = {};
  const q = (s) => document.querySelector(s);
  const qa = (s) => [...document.querySelectorAll(s)];
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const drop = q('.nav__item.has-drop');
  out['1_下拉项存在'] = !!drop;

  /* v1.6：当前项唯一性。旧版 bug：首页上「业务板块」（href=index.html#business）
     会被文件名匹配逻辑误判为当前页，与「首页」同时常亮两条底部亮条。
     v2.3.19：期望值随页面变化 —— 探针可在任意页跑，板块子页期望「业务板块」。 */
  const MAP = {
    'index.html': '首页', 'about.html': '关于荒启',
    'community.html': '业务板块', 'tools.html': '业务板块',
    'library.html': '业务板块', 'contact.html': '联系我们'
  };
  const HERE = MAP[(location.pathname.split('/').pop() || 'index.html')] || '首页';
  const curTexts = () => qa('.nav__item.is-current .nav__link')
    .map((a) => a.textContent.trim()).join(' | ');
  out['0_初始当前项'] = curTexts() + ' / 数量=' + qa('.nav__item.is-current').length + ' / 期望=仅 ' + HERE;
  location.hash = '#business';
  window.dispatchEvent(new Event('hashchange'));
  await sleep(150);
  /* #business 只存在于首页导航里；其它页设了这个 hash 也没有可匹配项，当前项不变 */
  out['0_锚点跳转后'] = curTexts() + ' / 数量=' + qa('.nav__item.is-current').length +
    ' / 期望=仅 ' + (HERE === '首页' ? '业务板块' : HERE);
  location.hash = '';
  window.dispatchEvent(new Event('hashchange'));
  await sleep(150);
  out['0_回到顶部后'] = curTexts() + ' / 数量=' + qa('.nav__item.is-current').length + ' / 期望=仅 ' + HERE;

  if (!drop) return out;

  const parentLink = drop.querySelector('.nav__link--parent');
  const caret = drop.querySelector('.nav__caret-btn');
  const panel = drop.querySelector('.nav__drop');

  out['2_父级标签'] = parentLink ? parentLink.tagName : '缺失';
  out['2_父级href'] = parentLink ? parentLink.getAttribute('href') : '缺失';
  out['2_箭头标签'] = caret ? caret.tagName + ' / type=' + caret.getAttribute('type') : '缺失';
  out['2_初始aria'] = caret ? caret.getAttribute('aria-expanded') : '缺失';
  out['2_子菜单项数'] = qa('.nav__item.has-drop .nav__drop a').length;
  out['2_子菜单href'] = qa('.nav__item.has-drop .nav__drop a')
    .map((a) => a.getAttribute('href')).join(' | ');

  /* 拦住跳转，只观察菜单是否被展开 */
  if (parentLink) {
    parentLink.addEventListener('click', (e) => e.preventDefault(), { once: true });
    parentLink.click();
    await sleep(120);
    out['3_点文字链接后'] = 'is-open=' + drop.classList.contains('is-open') +
      ' / aria=' + caret.getAttribute('aria-expanded') +
      ' / 期望=收起且aria=false';
  }

  /* 点箭头按钮：应展开 */
  if (caret) {
    caret.click();
    await sleep(120);
    out['4_点箭头后'] = 'is-open=' + drop.classList.contains('is-open') +
      ' / aria=' + caret.getAttribute('aria-expanded') +
      ' / 期望=展开且aria=true';

    /* 再点一次：应收起 */
    caret.click();
    await sleep(120);
    out['5_再点箭头后'] = 'is-open=' + drop.classList.contains('is-open') +
      ' / aria=' + caret.getAttribute('aria-expanded') +
      ' / 期望=收起且aria=false';

    /* 键盘可达性：按钮是否在 Tab 序列里 */
    out['6_箭头可聚焦'] = caret.tabIndex >= 0;
    out['6_文字链接可聚焦'] = parentLink ? parentLink.tabIndex >= 0 : false;
  }

  return out;
})()
