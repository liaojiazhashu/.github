/* ==========================================================================
   荒启科幻 · 页面构建脚本
   --------------------------------------------------------------------------
   把 .build/pages/<name>.html（仅页面主体内容）套入统一外壳（head / 导航 /
   页脚 / 抽屉 / 脚本），输出为站点根目录下的独立静态 HTML。

   这样做的原因：站点是纯静态的，但导航与页脚必须在 7 个页面间完全一致；
   用统一外壳生成可避免手工复制造成的不一致，也便于后续批量调整。

   用法：node tools/build-pages.cjs
   ========================================================================== */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SRC_DIR = path.join(ROOT, '.build', 'pages');

/* ------------------------------------------------------------------ 图标 */
const I = {
  arrowRight: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  chevronDown: '<path d="M6 9l6 6 6-6"/>',
  chevronRight: '<path d="M9 6l6 6-6 6"/>',
  close: '<path d="M6 6l12 12M18 6L6 18"/>',
  search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
  filter: '<path d="M4 6h16M7 12h10M10 18h4"/>',
  users: '<circle cx="9" cy="8" r="3.4"/><path d="M2.8 20a6.2 6.2 0 0 1 12.4 0"/><path d="M16.5 5.4a3.4 3.4 0 0 1 0 6.6"/><path d="M18 14.4A6.2 6.2 0 0 1 21.2 20"/>',
  user: '<circle cx="12" cy="8" r="3.4"/><path d="M4.6 20a7.4 7.4 0 0 1 14.8 0"/>',
  /* 齿轮：外圈 8 齿 + 内环 + 中心孔，齿根与内环相接，避免画成「太阳」 */
  gear: '<circle cx="12" cy="12" r="2.2"/><circle cx="12" cy="12" r="5.4"/><path d="M20.6 12h-3.2M12 20.6v-3.2M3.4 12h3.2M12 3.4v3.2M18.08 18.08l-2.26-2.26M5.92 18.08l2.26-2.26M5.92 5.92l2.26 2.26M18.08 5.92l-2.26 2.26"/>',
  graph: '<circle cx="12" cy="5" r="2.2"/><circle cx="5" cy="17" r="2.2"/><circle cx="19" cy="17" r="2.2"/><path d="M10.4 6.6 6.6 15M13.6 6.6 17.4 15M7.2 17h9.6"/>',
  document: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
  chart: '<path d="M4 20h16"/><path d="M7 20V11M12 20V5M17 20v-6"/>',
  shield: '<path d="M12 3l7 3v6c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6z"/><path d="M9.2 12.2 11.4 14.4 15 10.6"/>',
  layers: '<path d="M12 3 3 7.6l9 4.6 9-4.6z"/><path d="M3 12.4 12 17l9-4.6"/><path d="M3 16.8 12 21.4l9-4.6"/>',
  globe: '<circle cx="12" cy="12" r="8.6"/><path d="M3.4 12h17.2"/><path d="M12 3.4c2.4 2.4 3.6 5.4 3.6 8.6s-1.2 6.2-3.6 8.6c-2.4-2.4-3.6-5.4-3.6-8.6S9.6 5.8 12 3.4Z"/>',
  check: '<path d="M5 12.5 9.6 17 19 7"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7.5 12 13l8-5.5"/>',
  calendar: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3"/>',
  spark: '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6.3 6.3l2.8 2.8M14.9 14.9l2.8 2.8M17.7 6.3l-2.8 2.8M9.1 14.9l-2.8 2.8"/>',
  book: '<path d="M4 4.5h6a3 3 0 0 1 3 3V20a2.4 2.4 0 0 0-2.4-2.4H4z"/><path d="M20 4.5h-6a3 3 0 0 0-3 3V20a2.4 2.4 0 0 1 2.4-2.4H20z"/>',
  code: '<path d="M9 8 5 12l4 4M15 8l4 4-4 4"/>',
  flow: '<rect x="3" y="3.5" width="6.5" height="5" rx="1.4"/><rect x="14.5" y="3.5" width="6.5" height="5" rx="1.4"/><rect x="8.75" y="15.5" width="6.5" height="5" rx="1.4"/><path d="M6.25 8.5v3.5h11.5V8.5M12 12v3.5"/>',
  target: '<circle cx="12" cy="12" r="8.4"/><circle cx="12" cy="12" r="4.4"/><circle cx="12" cy="12" r="1.1" fill="currentColor" stroke="none"/>',
  compass: '<circle cx="12" cy="12" r="8.6"/><path d="M15.6 8.4 13.9 13.9 8.4 15.6l1.7-5.5z"/>',
  clipboard: '<rect x="5" y="4.5" width="14" height="16" rx="2"/><path d="M9 4.5V3.4A1.4 1.4 0 0 1 10.4 2h3.2A1.4 1.4 0 0 1 15 3.4v1.1z"/><path d="M9 10.5h6M9 14.5h4"/>',
  clock: '<circle cx="12" cy="12" r="8.4"/><path d="M12 7.4V12l3.2 2"/>',
  sliders: '<path d="M4 8h9M17 8h3M4 16h3M11 16h9"/><circle cx="15" cy="8" r="2"/><circle cx="9" cy="16" r="2"/>',
  database: '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>',
  link: '<path d="M10 13.4a4 4 0 0 0 5.6 0l2.6-2.6a4 4 0 0 0-5.6-5.6L11.5 6"/><path d="M14 10.6a4 4 0 0 0-5.6 0L5.8 13.2a4 4 0 0 0 5.6 5.6L12.5 18"/>',
  refresh: '<path d="M20 11.5A8 8 0 0 0 6.3 6.4L4 8.6"/><path d="M20 4.5v4h-4"/><path d="M4 12.5a8 8 0 0 0 13.7 5.1l2.3-2.2"/><path d="M4 19.5v-4h4"/>',
  gauge: '<path d="M4.5 17a8.5 8.5 0 0 1 15 0"/><path d="M12 17 15.5 11"/><circle cx="12" cy="17" r="1.4"/>',
  award: '<path d="M12 3.5 14.6 9l6 .8-4.4 4.2 1.1 6-5.3-2.9-5.3 2.9 1.1-6L3.4 9.8 9.4 9z"/>',
  orbit: '<circle cx="12" cy="12" r="3.4"/><ellipse cx="12" cy="12" rx="9" ry="4.2" transform="rotate(-24 12 12)"/>',
  cpu: '<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M10 3.5v3.5M14 3.5v3.5M10 17v3.5M14 17v3.5M3.5 10H7M3.5 14H7M17 10h3.5M17 14h3.5"/>',
  flag: '<path d="M6 21V4.5"/><path d="M6 5h11l-2 3.5 2 3.5H6"/>',
  userCheck: '<circle cx="10" cy="8" r="3.4"/><path d="M3.6 20a6.4 6.4 0 0 1 12.8 0"/><path d="M16.5 12.5 18.4 14.4 21.5 11"/>',
  tree: '<rect x="9" y="3" width="6" height="4.4" rx="1.2"/><rect x="3" y="16.6" width="6" height="4.4" rx="1.2"/><rect x="15" y="16.6" width="6" height="4.4" rx="1.2"/><path d="M12 7.4v4.4M6 16.6v-2.4h12v2.4"/>',
  shieldCheck: '<path d="M12 3l7 3v6c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6z"/><path d="M9.2 12 11.3 14.1 15 10.4"/>',
  waves: '<path d="M3 8.5c2-1.6 4-1.6 6 0s4 1.6 6 0 4-1.6 6 0"/><path d="M3 13c2-1.6 4-1.6 6 0s4 1.6 6 0 4-1.6 6 0"/><path d="M3 17.5c2-1.6 4-1.6 6 0s4 1.6 6 0 4-1.6 6 0"/>',
  send: '<path d="M21 3 10.5 13.5"/><path d="M21 3l-6.8 18-3.7-7.5L3 9.8z"/>'
};

function icon(name, cls) {
  const d = I[name];
  if (!d) throw new Error('未知图标: ' + name);
  return `<svg class="ico ${cls || 'ico-20'}" viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
}

/* ---------------------------------------------------------------- 导航表 */
/* 阅读站已停用（2026-11-08），官网与图书馆任何位置都不出现其入口。
   曾有一个 READER_URL = '/h5/' 常量指向它，2026-11-08 一并删除：
   那个路径在线上是 404（真正的阅读站在 www.huangqisf.com/h5/），
   留着常量等于给后来的人留一条「写上去就静默生成死链」的路 ——
   check-site.cjs 对站内绝对路径一律跳过，抓不到。 */

/* 银河图书馆站地址（v2.4）。
   官网只负责「讲清楚这座馆是什么」并把人送进去，馆里的内容一律在站内实时查，
   不再往官网搬一份静态拷贝 —— 两份数据必然漂，而漂的那一份永远没人维护。
   写 `/library` 是同域部署的正确写法：Nginx 把 /library 交给 PHP，
   官网与馆同一个域名，读者不用跳站。
   本机预览时由 js/main.js 把 /library 改指到 127.0.0.1:8090（见 LOCAL_LIBRARY），
   生产环境不会命中那段逻辑。正文里只写 {{LIBRARY_URL}}，地址不写死。 */
const LIBRARY_URL = process.env.LIBRARY_URL || '/library';

const NAV = [
  { href: 'index.html', label: '首页', key: 'home' },
  { href: 'about.html', label: '关于我们', key: 'about' },
  {
    /* href 指向首页的板块介绍段落：父级文字点击后落到介绍，箭头负责展开子菜单 */
    href: 'index.html#business',
    label: '三大板块', key: 'biz', drop: [
      { href: 'community.html', label: '科幻社群', desc: '成员自主运营的公益写作社群，每年九期练笔', icon: 'users' },
      { href: 'tools.html', label: '数字化产品开发', desc: '社区、教育、AI 与定制开发，源码可交付', icon: 'gear' },
      { href: 'library.html', label: '银河图书馆', desc: '免费开放的在线索引馆，一篇两页：读者一页、作者一页', icon: 'graph' }
    ]
  },
  { href: 'contact.html', label: '联系我们', key: 'contact' }
];

const FOOTER_BIZ = [
  { href: 'community.html', label: '科幻社群' },
  { href: 'tools.html', label: '数字化产品开发' },
  { href: 'library.html', label: '银河图书馆' }
];

const FOOTER_LINKS = [
  { href: 'contact.html#start', label: '客户端下载' },
  { href: LIBRARY_URL, label: '进入银河图书馆', ext: true },
  { href: 'about.html', label: '关于我们' },
  { href: 'contact.html', label: '联系我们' }
];

/* 客户端下载（v2.3.11）：三平台占位安装包，
   正式客户端打包后替换同名文件即可，下载入口直接生效 */
const APP_DOWNLOAD = {
  href: '/dt/荒启科幻.exe',
  label: '下载客户端',
  versions: [
    { href: '/dt/荒启科幻.exe', label: '电脑端 Windows', ext: '.exe', icon: 'cpu' },
    { href: '/dt/Huangqisf.apk', label: '安卓端 Android', ext: '.apk', icon: 'send' },
    { href: '/dt/huangqisf.mobileconfig', label: 'iOS 端 iPhone / iPad', ext: '.ipa', icon: 'globe' }
  ]
};

/* 三平台下载按钮组（弹窗 / 首页 / 联系页共用） */
function appDownloadButtons(cls) {
  return APP_DOWNLOAD.versions.map((v) =>
    `          <a class="btn ${cls || 'btn--outline'}" href="${v.href}" download>` +
    `${icon(v.icon, 'ico-18')}${v.label}<span class="app-dl__ext">${v.ext}</span></a>`
  ).join('\n');
}

/* 邀请码提示弹窗（v2.3.22）
   口径：注册必须邀请码。
   弹窗的第一动作是「获取邀请码」（指向联系页真正讲邀请码的那一节），
   下载安装包为次要动作。 */
function buildInviteModal() {
  return `  <dialog class="invite-modal" id="invite-modal" aria-labelledby="invite-modal-title">
    <div class="invite-modal__card">
      <button class="invite-modal__close" type="button" data-invite-close aria-label="关闭弹窗">
        ${icon('close', 'ico-20')}
      </button>
      <p class="invite-modal__eyebrow">邀请码准入</p>
      <h2 class="invite-modal__title" id="invite-modal-title">注册与阅读之前，先拿邀请码</h2>
      <p class="invite-modal__desc">
        客户端安装包可以直接下载安装；<b>注册账号</b>需要邀请码 ——
        加入交流 QQ 群、在加群申请里回答「科幻的定义是什么？」即可获取，不收费，也不需要填表。
      </p>
      <p class="invite-modal__group">群号 <span class="mono">280660235</span><button class="copy-btn" type="button" data-copy="280660235" aria-label="复制群号 280660235">复制群号</button></p>
      <p class="invite-modal__hint">电脑上扫二维码；手机上复制群号去搜索。</p>
      <img class="invite-modal__qr" loading="lazy" decoding="async" src="assets/qq/qq-group-qr.png"
           alt="荒启科幻交流群二维码，群号 280660235" width="216" height="216">
      <div class="invite-modal__actions invite-modal__actions--stack">
        <a class="btn btn--primary btn--sm" href="contact.html#invite">${icon('shieldCheck', 'ico-18')}获取邀请码</a>
        ${appDownloadButtons('btn--outline btn--sm')}
      </div>
    </div>
  </dialog>
`;
}

/* ---------------------------------------------------------------- 外壳 */
function buildHeader(current, scene) {
  const navItems = NAV.map((item) => {
    if (!item.drop) {
      const cur = item.key === current ? ' is-current' : '';
      return `        <li class="nav__item${cur}">
          <a class="nav__link" href="${item.href}">${item.label}</a>
        </li>`;
    }
    const cur = current === item.key ? ' is-current' : '';
    const kids = item.drop.map((d) => `            <a class="drop-item" href="${d.href}">
              <span class="drop-item__ico">${icon(d.icon, 'ico-18')}</span>
              <span>
                <span class="drop-item__title">${d.label}</span>
                <span class="drop-item__desc">${d.desc}</span>
              </span>
            </a>`).join('\n');
    /* 带下拉的分组里，父级文字是「链接」、右侧小箭头是「展开按钮」，两者
       职责分开：
         · 只想看板块概览的人点文字，直接跳到首页的 #business 段落；
         · 想直达某一板块的人点箭头展开子菜单。
       以前父级是一枚 <button>，点击只能展开菜单，没有任何入口能到板块介绍，
       键盘用户更是完全无法通过它跳转 —— 这正是本次要修的问题。
       两个控件都必须可聚焦，所以不能再把箭头塞进链接里面。 */
    return `        <li class="nav__item has-drop${cur}">
          <span class="nav__group">
            <a class="nav__link nav__link--parent" href="${item.href}">${item.label}</a>
            <button class="nav__caret-btn" type="button" aria-expanded="false" aria-haspopup="true" aria-label="展开「${item.label}」子菜单">
              <svg class="ico nav__caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </button>
          </span>
          <div class="nav__drop">
${kids}
          </div>
        </li>`;
  }).join('\n');

  return `  <header class="site-header">
    <div class="container container--wide site-header__inner">
      <a class="brand" href="index.html" aria-label="荒启科幻 首页">
        <img class="brand__img brand__img--night" src="assets/logo/logo-full-white.png" alt="荒启科幻 HUANGQI SCI-FI" width="880" height="334">
        <img class="brand__img brand__img--day" loading="lazy" src="assets/logo/logo-full-dark.png" alt="荒启科幻 HUANGQI SCI-FI" width="880" height="334">
      </a>

      <nav class="nav" aria-label="主导航">
        <ul class="nav" style="display:contents">
${navItems}
        </ul>
      </nav>

      <div class="site-header__actions">
        <button class="theme-toggle" type="button" data-theme-toggle aria-label="切换到白天模式" aria-pressed="false">
          <svg class="ico ico-20 theme-toggle__ico theme-toggle__ico--to-day" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.2 5.2l1.7 1.7M17.1 17.1l1.7 1.7M18.8 5.2l-1.7 1.7M6.9 17.1l-1.7 1.7"/></svg>
          <svg class="ico ico-20 theme-toggle__ico theme-toggle__ico--to-night" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 14.4A8.5 8.5 0 0 1 9.6 3.4a8.5 8.5 0 1 0 11 11Z"/></svg>
        </button>
        ${/* 图书馆页不出现「下载客户端」这种需要邀请码的动作：
             这一页讲的是一套免登录就能读的拆解，头部再放一个要凭证的按钮，
             等于自己把「不用账号」这句话拆掉。换成直通馆内的入口。 */ ''}
        ${/* 银河图书馆是常驻板块，右上角给一个固定入口 —— 不随页面内容变化，
             让「图书馆是个独立站点」这件事在任何页面都可见。 */ ''}
        ${scene === 'library'
          ? `<a class="btn btn--primary btn--sm" href="${LIBRARY_URL}" target="_blank" rel="noopener">${icon('book', 'ico-18')}进入图书馆</a>`
          : `<a class="btn btn--outline btn--sm btn--read" href="${LIBRARY_URL}" target="_blank" rel="noopener">${icon('book', 'ico-18')}银河图书馆</a>
        <a class="btn btn--outline btn--sm btn--download" href="${APP_DOWNLOAD.href}" download>${icon('cpu', 'ico-18')}${APP_DOWNLOAD.label}</a>
        ${/* 页头常驻的「获取邀请码」。
             以前整站唯一的转化动作只在首页首屏和每页页底各出现一次：从 about /
             community / tools 进来的人，读完第一屏找不到任何下一步，要滑到 97%
             才遇到第一个按钮。而窄屏恰好只保留一枚 btn--primary —— 这一枚现在
             是「获取邀请码」，不是「下载客户端」：安装包本来就能直接下载（注册
             才要邀请码），把它做成实心主按钮却一点就只剩弹窗，是承诺与结果不一致。 */ ''}
        <a class="btn btn--primary btn--sm" href="contact.html#invite">${icon('shieldCheck', 'ico-18')}获取邀请码</a>`}
        <button class="nav-toggle" type="button" aria-label="打开导航菜单" aria-controls="mobile-drawer">
          <span class="nav-toggle__bars" aria-hidden="true"><span></span><span></span><span></span></span>
        </button>
      </div>
    </div>
  </header>`;
}

function buildDrawer(current) {
  const groups = [
    {
      label: '页面',
      items: [
        { href: 'index.html', label: '首页', key: 'home' },
        { href: 'about.html', label: '关于我们', key: 'about' },
        { href: 'contact.html', label: '联系我们', key: 'contact' }
      ]
    },
    {
      label: '三大板块',
      items: NAV.find((n) => n.key === 'biz').drop.map((d) => ({
        href: d.href, label: d.label
      }))
    },
    {
      label: '开始使用',
      items: [
        { href: 'contact.html#start', label: '客户端下载', key: '' },
        { href: LIBRARY_URL, label: '进入银河图书馆', key: '', ext: true }
      ]
    }
  ];

  const body = groups.map((g) => `      <div class="drawer__group">
        <p class="drawer__label">${g.label}</p>
        <ul class="mt-3">
${g.items.map((it) => `          <li><a class="drawer__link" href="${it.href}"${it.ext ? ' target="_blank" rel="noopener"' : ''}>${it.label}${icon('chevronRight', 'ico-18')}</a></li>`).join('\n')}
        </ul>
      </div>`).join('\n');

  return `  <div class="drawer" id="mobile-drawer">
    <div class="drawer__panel">
      <button class="drawer__close" type="button" aria-label="关闭导航菜单">
        ${icon('close', 'ico-20')}
      </button>
${body}
      <div class="drawer__cta">
        <a class="btn btn--primary btn--block" href="contact.html">${icon('send', 'ico-18')}加入社群</a>
      </div>
    </div>
  </div>`;
}

function buildFooter() {
  return `  <footer class="site-footer">
    <div class="container container--full">
      <div class="site-footer__main">
        <div class="site-footer__brand">
          <img class="brand__img--night" src="assets/logo/logo-full-white.png" alt="荒启科幻" width="880" height="334">
          <img class="brand__img--day" loading="lazy" src="assets/logo/logo-full-dark.png" alt="荒启科幻" width="880" height="334">
          <p class="site-footer__tagline">从荒芜中开启新世界。连接科幻创作者与读者，用技术把重复劳动交给系统，用关键词索引解构科幻世界。</p>
          <p class="footer-brand-line">HUANGQI SCI-FI</p>
        </div>

        <div>
          <h2 class="site-footer__col-title">三大板块</h2>
          <ul class="site-footer__links">
${FOOTER_BIZ.map((l) => `            <li><a href="${l.href}">${l.label}</a></li>`).join('\n')}
          </ul>
        </div>

        <div>
          <h2 class="site-footer__col-title">快速链接</h2>
          <ul class="site-footer__links">
${FOOTER_LINKS.map((l) => `            <li><a href="${l.href}"${l.ext ? ' target="_blank" rel="noopener"' : ''}>${l.label}</a></li>`).join('\n')}
          </ul>
        </div>

        <div>
          <h2 class="site-footer__col-title">联系我们</h2>
          <ul class="site-footer__links">
            <li><a href="contact.html#invite">加入社群（QQ 群 280660235）</a></li>
            <li><a href="contact.html">联系方式与入群须知</a></li>
            <li><a href="mailto:huangqi.sifi@gmail.com">huangqi.sifi@gmail.com</a></li>
            <li><a href="mailto:founpghkf@gmail.com">founpghkf@gmail.com</a></li>
          </ul>
        </div>
      </div>

      <div class="site-footer__bottom">
        <p>© <span data-year>2026</span> 成都荒启文化传播有限公司 · 荒启科幻。<a class="site-footer__icp" href="https://beian.miit.gov.cn/" rel="noopener noreferrer" target="_blank">蜀ICP备2025169184号-1</a> · <a class="site-footer__icp" href="https://beian.miit.gov.cn/" rel="noopener noreferrer" target="_blank">蜀ICP备2025169184号-2A</a></p>
        <p class="site-footer__compliance">本站由成都荒启文化传播有限公司（统一社会信用代码 91510100MAEY15D64X）主办。「荒启科幻练笔」为科幻爱好者自主运营的纯公益社群，公司仅提供平台工具，不参与其日常运营，不获取经营收益。</p>
        <div class="site-footer__bottom-links">
          <a href="about.html">品牌规范</a>
          <a href="contact.html#privacy">隐私说明</a>
          <a href="#top">返回顶部</a>
        </div>
      </div>
    </div>
  </footer>`;
}

/* v2.1「星图」背景构成：极简几何线条 + 色块构成的星图。
   科学探索的意象来自真实星图的语法：星座连线、坐标十字、刻度弧、
   轨道椭圆、 sector 高亮色块。全部为矢量 SVG / CSS，任意分辨率锐利。 */

/* 全幅星座图（v2.2）：东亚夏季真实星空的极简几何再现。
   面南而观：左东右西。银河自东北（左上）经天津四、天鹰座流向南部人马座与天蝎座；
   夏季大三角（织女星 / 牛郎星 / 天津四）居中偏上，北斗在西北角（右上），
   仙后座 W 在东北角（左上），天蝎座（心宿二，红色超巨星）与人马座茶壶贴南天。
   星点为几何圆，连线为发丝线 —— 是星图，也是美术；v2.3 起不显示星座名字，
   让画面回归纯几何观测语言。 */
const CONSTELLATION = `<svg class="bg-constellation" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <!-- 银河带：边界虚线 + 半透明带 + 带内碎星 -->
      <g fill="currentColor" opacity="0.055">
        <path d="M470 -40 C 620 120 740 260 790 400 C 840 540 850 720 830 940 L 660 940 C 700 740 690 600 620 470 C 550 340 460 160 400 -40 Z"/>
      </g>
      <g fill="none" stroke="currentColor" stroke-width="1" opacity="0.16" stroke-dasharray="2 10">
        <path d="M470 -40 C 620 120 740 260 790 400 C 840 540 850 720 830 940"/>
        <path d="M400 -40 C 460 160 550 340 620 470 C 690 600 700 740 660 940"/>
      </g>
      <g fill="currentColor" opacity="0.5">
        <circle cx="560" cy="60" r="1.1"/><circle cx="640" cy="150" r="1.3"/><circle cx="700" cy="240" r="1.1"/>
        <circle cx="740" cy="330" r="1.4"/><circle cx="790" cy="430" r="1.2"/><circle cx="810" cy="520" r="1.5"/>
        <circle cx="800" cy="610" r="1.2"/><circle cx="780" cy="700" r="1.4"/><circle cx="760" cy="790" r="1.2"/>
        <circle cx="700" cy="850" r="1.3"/><circle cx="850" cy="120" r="1"/><circle cx="760" cy="90" r="1"/>
      </g>
      <!-- 远景散星（无连线） -->
      <g fill="#EDF2F8">
        <circle cx="60" cy="420" r="1.3" opacity="0.4"/><circle cx="180" cy="640" r="1" opacity="0.3"/>
        <circle cx="460" cy="380" r="1.2" opacity="0.35"/><circle cx="1010" cy="180" r="1.2" opacity="0.4"/>
        <circle cx="1130" cy="330" r="1" opacity="0.3"/><circle cx="1310" cy="430" r="1.3" opacity="0.4"/>
        <circle cx="1430" cy="620" r="1" opacity="0.3"/><circle cx="1530" cy="240" r="1.2" opacity="0.35"/>
        <circle cx="980" cy="740" r="1" opacity="0.3"/><circle cx="240" cy="300" r="1" opacity="0.3"/>
      </g>
      <!-- 北斗七星（西北天，右上）：天枢→天璇→天玑→天权→玉衡→开阳→摇光 -->
      <g stroke="currentColor" stroke-width="1" opacity="0.4" fill="none">
        <polyline points="1240,140 1300,120 1350,135 1390,110 1440,120 1490,95 1545,105"/>
      </g>
      <g fill="#EDF2F8" opacity="0.75">
        <circle cx="1240" cy="140" r="2.2"/><circle cx="1300" cy="120" r="2.2"/><circle cx="1350" cy="135" r="2"/>
        <circle cx="1390" cy="110" r="2"/><circle cx="1440" cy="120" r="2.2"/><circle cx="1490" cy="95" r="2.2"/>
        <circle cx="1545" cy="105" r="2.2"/>
      </g>
      <!-- 仙后座 W（东北天，左上） -->
      <g stroke="currentColor" stroke-width="1" opacity="0.35" fill="none">
        <polyline points="140,130 200,105 265,135 330,100 395,125"/>
      </g>
      <g fill="#EDF2F8" opacity="0.65">
        <circle cx="140" cy="130" r="1.8"/><circle cx="200" cy="105" r="1.8"/><circle cx="265" cy="135" r="1.8"/>
        <circle cx="330" cy="100" r="1.8"/><circle cx="395" cy="125" r="1.8"/>
      </g>
      <!-- 天琴座：织女星 + 平行四边形 -->
      <g stroke="currentColor" stroke-width="1" opacity="0.42" fill="none">
        <polyline points="648,215 682,232 668,276 634,258 648,215"/>
      </g>
      <circle cx="620" cy="170" r="3.4" fill="#EDF2F8" opacity="0.95"/>
      <circle cx="620" cy="170" r="9" fill="none" stroke="currentColor" stroke-width="1" opacity="0.6"/>
      <g fill="#EDF2F8" opacity="0.7">
        <circle cx="648" cy="215" r="1.8"/><circle cx="682" cy="232" r="1.6"/><circle cx="668" cy="276" r="1.6"/><circle cx="634" cy="258" r="1.6"/>
      </g>
      <!-- 天鹅座（北十字）：天津四 → 天津一 → 辇道增七，两翼 -->
      <g stroke="currentColor" stroke-width="1" opacity="0.42" fill="none">
        <polyline points="880,120 852,212 822,322"/>
        <polyline points="772,190 852,212 932,252"/>
      </g>
      <circle cx="880" cy="120" r="3.2" fill="#EDF2F8" opacity="0.95"/>
      <circle cx="880" cy="120" r="9" fill="none" stroke="currentColor" stroke-width="1" opacity="0.6"/>
      <g fill="#EDF2F8" opacity="0.75">
        <circle cx="852" cy="212" r="2.4"/><circle cx="822" cy="322" r="2.2"/>
        <circle cx="772" cy="190" r="1.8"/><circle cx="932" cy="252" r="1.8"/>
      </g>
      <!-- 天鹰座：牛郎星 + 扁担双星（河鼓一 / 河鼓三） -->
      <g stroke="currentColor" stroke-width="1" opacity="0.42" fill="none">
        <polyline points="665,445 700,482 736,518"/>
      </g>
      <circle cx="700" cy="482" r="3.2" fill="#EDF2F8" opacity="0.95"/>
      <circle cx="700" cy="482" r="9" fill="none" stroke="currentColor" stroke-width="1" opacity="0.6"/>
      <g fill="#EDF2F8" opacity="0.75">
        <circle cx="665" cy="445" r="2"/><circle cx="736" cy="518" r="2"/>
        <circle cx="780" cy="570" r="1.6"/><circle cx="816" cy="608" r="1.5"/>
      </g>
      <!-- 天蝎座：三星头弧 → 心宿二（红色超巨星） → 尾钩 -->
      <g stroke="currentColor" stroke-width="1" opacity="0.42" fill="none">
        <polyline points="1080,575 1120,560 1160,575"/>
        <polyline points="1120,630 1160,690 1195,735 1178,795 1122,822 1066,806 1042,762"/>
      </g>
      <circle cx="1120" cy="630" r="3" fill="#E88B8B" opacity="0.9"/>
      <circle cx="1120" cy="630" r="9" fill="none" stroke="#E88B8B" stroke-width="1" opacity="0.55"/>
      <g fill="#EDF2F8" opacity="0.75">
        <circle cx="1080" cy="575" r="1.9"/><circle cx="1120" cy="560" r="2"/><circle cx="1160" cy="575" r="1.9"/>
        <circle cx="1160" cy="690" r="1.9"/><circle cx="1195" cy="735" r="1.9"/><circle cx="1178" cy="795" r="1.9"/>
        <circle cx="1122" cy="822" r="1.7"/><circle cx="1066" cy="806" r="1.7"/><circle cx="1042" cy="762" r="1.7"/>
      </g>
      <!-- 人马座茶壶（银河中心方向） -->
      <g stroke="currentColor" stroke-width="1" opacity="0.42" fill="none">
        <polyline points="790,692 850,672 898,702 893,756 834,776 786,746 790,692"/>
        <polyline points="786,746 752,722"/>
        <polyline points="850,672 872,640"/>
      </g>
      <g fill="#EDF2F8" opacity="0.75">
        <circle cx="790" cy="692" r="1.9"/><circle cx="850" cy="672" r="2"/><circle cx="898" cy="702" r="1.9"/>
        <circle cx="893" cy="756" r="1.9"/><circle cx="834" cy="776" r="1.9"/><circle cx="786" cy="746" r="1.9"/>
        <circle cx="752" cy="722" r="1.6"/><circle cx="872" cy="640" r="1.6"/>
      </g>
      <!-- 织女星坐标十字（织女一是夏季夜空最易辨认的恒星之一） -->
      <g stroke="currentColor" stroke-width="1" opacity="0.5">
        <path d="M620 152 V165 M620 175 V188 M602 170 H615 M625 170 H638"/>
      </g>
    </svg>`;

/* 观测坐标图（左上）：一小组被测量的星 —— 扇形高亮色块标出「目标天区」，
   星点连线 + 刻度弧 + 坐标十字，是星图而非装饰方块。 */
const SHARD_A = `<svg class="bg-shard bg-shard--a" viewBox="0 0 320 320" aria-hidden="true">
      <path d="M60 40 L200 120 L110 230 Z" fill="currentColor" opacity="0.07"/>
      <g fill="none" stroke="currentColor" stroke-width="1.1" opacity="0.5">
        <polyline points="40,240 110,150 190,190 250,90"/>
        <path d="M210 60 A 80 80 0 0 1 268 140" stroke-dasharray="1 6"/>
      </g>
      <g fill="currentColor">
        <circle cx="40" cy="240" r="2.6"/><circle cx="110" cy="150" r="3"/>
        <circle cx="190" cy="190" r="3.4"/><circle cx="250" cy="90" r="2.6"/>
      </g>
      <circle cx="190" cy="190" r="8" fill="none" stroke="currentColor" stroke-width="1" opacity="0.65"/>
      <g stroke="currentColor" stroke-width="1" opacity="0.55">
        <path d="M250 78 V88 M250 92 V102 M240 90 H250 M254 90 H264"/>
      </g>
      <rect x="30" y="264" width="20" height="20" fill="currentColor" opacity="0.8"/>
    </svg>`;

/* 轨道图（右中）：中心恒星 + 十字准线、两层轨道（实线圆 + 虚线圆）、
   倾斜轨道椭圆与在轨星点、扇形高亮色块标出一段轨域。 */
const SHARD_B = `<svg class="bg-shard bg-shard--b" viewBox="0 0 320 320" aria-hidden="true">
      <path d="M160 160 L268 122 A 118 118 0 0 1 262 202 Z" fill="currentColor" opacity="0.09"/>
      <g fill="none" stroke="currentColor" stroke-width="1.1" opacity="0.5">
        <circle cx="160" cy="160" r="110"/>
        <circle cx="160" cy="160" r="72" stroke-dasharray="2 8" opacity="0.6"/>
        <ellipse cx="160" cy="160" rx="132" ry="44" transform="rotate(-18 160 160)" opacity="0.8"/>
      </g>
      <g stroke="currentColor" stroke-width="1" opacity="0.5">
        <path d="M160 132 V156 M160 164 V188 M132 160 H156 M164 160 H188"/>
      </g>
      <circle cx="160" cy="160" r="4" fill="currentColor" opacity="0.9"/>
      <circle cx="52" cy="196" r="5" fill="currentColor"/>
      <circle cx="41" cy="188" r="2" fill="currentColor" opacity="0.7"/>
      <g fill="none" stroke="currentColor" stroke-width="1" opacity="0.35">
        <path d="M52 196 L160 160"/>
      </g>
    </svg>`;

/* 白天模式背景（v2.3.7「宇宙视角 · 巨物太阳 · 光照系统」）：视角身处宇宙之中 ——
   锐利斜切色块构成深空平面（边界全为直线，无圆弧、无云朵）；
   太阳为 r=520 平涂圆盘（圆心 1400,540，v2.3.16 放大 1.3 倍），
   左侧与上方 limb 完整落在画幅内、右下两向出画 —— 直径 1040 超过画幅高
   900，以「一张屏幕装不下」的比例制造巨物压迫感；完整可见的 limb 弧
   仍让用户一眼认出这是太阳。三层平涂同心「光照阶梯带」+ 八道放射光线
   + 三级日冕环，把「这一片天光来自太阳」讲清楚；
   黯淡月牙的亮面朝向太阳（与光源方向一致）。颜色全部走 CSS 令牌。 */
const DAYSKY = `<svg class="bg-daysky" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <defs>
        <mask id="daysky-moon-mask">
          <rect x="100" y="700" width="150" height="130" fill="#fff"/>
          <circle cx="145" cy="767" r="30" fill="#000"/>
        </mask>
      </defs>
      <!-- 锐利斜切色块：深空平面，全部直线边界 -->
      <polygon points="0,900 0,560 640,900" fill="var(--c-data, #4A5FA8)" opacity="0.10"/>
      <polygon points="1600,900 1600,470 960,900" fill="var(--c-data, #4A5FA8)" opacity="0.08"/>
      <polygon points="1600,0 1600,280 1270,0" fill="#FFFFFF" opacity="0.55"/>
      <polygon points="0,0 320,0 0,200" fill="#FFFFFF" opacity="0.40"/>
      <line x1="0" y1="742" x2="1600" y2="548" stroke="var(--c-data, #4A5FA8)" stroke-width="1" opacity="0.14"/>
      <!-- 光照阶梯带（v2.3.7）：以太阳为圆心的三层平涂同心圆，
           亮度逐层衰减 —— 用「色阶」而非渐变表达「这一片天光来自太阳」 -->
      <g fill="var(--sun, #F5C445)">
        <circle cx="1400" cy="540" r="1000" opacity="0.025"/>
        <circle cx="1400" cy="540" r="860" opacity="0.045"/>
        <circle cx="1400" cy="540" r="700" opacity="0.07"/>
      </g>
      <!-- 放射光线：自日面外侧射出的直线光束（几何、锐利，非卡通短刺） -->
      <g class="daysky__rays" stroke="var(--sun-line, #D9A32B)" stroke-width="1" opacity="0.14">
        <path d="M1682.5 1029.3 L1900 1406 M1400 1105 L1400 1540 M910.7 822.5 L534 1040
                 M835 540 L400 540 M910.7 257.5 L534 40 M1117.5 50.7 L900 -326
                 M1400 -25 L1400 -460 M1682.5 50.7 L1900 -326"/>
      </g>
      <!-- 巨物太阳：r=520、圆心 (1400,540) —— 左侧与上方 limb 完整落在
           画幅内、右下两向出画。直径 1040 已超过画幅高 900，比 v2.3.7 的
           r=400 再放大 1.3 倍：太阳从此不是「画面里的一个圆」，
           而是「画面装不下的一个天体」 -->
      <g class="daysky__rings">
        <circle cx="1400" cy="540" r="575" fill="none" stroke="var(--sun-line, #D9A32B)" stroke-width="1" opacity="0.12"/>
        <circle cx="1400" cy="540" r="554" fill="none" stroke="var(--sun-line, #D9A32B)" stroke-width="1" opacity="0.20"/>
        <circle cx="1400" cy="540" r="536" fill="none" stroke="var(--sun-line, #D9A32B)" stroke-width="1.2" opacity="0.34"/>
      </g>
      <g class="daysky__sun">
        <!-- 平涂内盘（v2.3.16）：五层同心、半径逐级收 74、透明度逐级 +0.05。
             日面跨度 1040，只用一层均匀填色时读作「一片浅黄雾」而不是球体；
             五级台阶叠出「中心亮、边缘暗」的亮度梯度，全站禁渐变，
             这是唯一能表达「发光球体」的语言 —— 与「光照阶梯带」同一套做法。 -->
        <circle cx="1400" cy="540" r="520" fill="var(--sun, #F5C445)" opacity="0.26"/>
        <circle cx="1400" cy="540" r="446" fill="var(--sun, #F5C445)" opacity="0.05"/>
        <circle cx="1400" cy="540" r="372" fill="var(--sun, #F5C445)" opacity="0.05"/>
        <circle cx="1400" cy="540" r="298" fill="var(--sun, #F5C445)" opacity="0.05"/>
        <circle cx="1400" cy="540" r="224" fill="var(--sun, #F5C445)" opacity="0.05"/>
        <!-- 赤道与子午两条对角基准线（几何线稿语言） -->
        <path d="M800 -60 L2000 1140 M2000 -60 L800 1140" stroke="var(--sun-line, #D9A32B)" stroke-width="1" fill="none" opacity="0.22"/>
        <!-- 日面边缘：加粗一档并提亮 —— 直径超过画幅后，这条弧是唯一
             能让人读出「这是个球、不是色块」的证据，必须给足 -->
        <circle cx="1400" cy="540" r="520" fill="none" stroke="var(--sun-line, #D9A32B)" stroke-width="3" opacity="0.72"/>
        <circle cx="1400" cy="540" r="492" fill="none" stroke="var(--sun-line, #D9A32B)" stroke-width="1.2" opacity="0.34"/>
      </g>
      <!-- 黯淡的月亮：画面左下，亮面朝向太阳（光源方向一致），
           mask 硬裁出锐利明暗界线 -->
      <g class="daysky__moon" opacity="0.5">
        <circle cx="170" cy="760" r="30" fill="#DDE7EF" mask="url(#daysky-moon-mask)"/>
        <circle cx="170" cy="760" r="30" fill="none" stroke="var(--c-data, #4A5FA8)" stroke-width="1" opacity="0.35"/>
      </g>
      <!-- 几点黯淡星光：低存在感圆点与十字亮星（极慢漂移） -->
      <g class="daysky__stars" fill="var(--c-data, #4A5FA8)">
        <circle cx="520" cy="212" r="2" opacity="0.35"/>
        <circle cx="760" cy="332" r="1.5" opacity="0.28"/>
        <circle cx="984" cy="140" r="2" opacity="0.30"/>
        <circle cx="700" cy="560" r="1.8" opacity="0.25"/>
        <circle cx="1084" cy="692" r="2.2" opacity="0.28"/>
        <circle cx="222" cy="322" r="1.6" opacity="0.30"/>
        <circle cx="1448" cy="424" r="1.8" opacity="0.30"/>
        <path d="M880 458 V482 M868 470 H892" stroke="var(--c-data, #4A5FA8)" stroke-width="1" fill="none" opacity="0.30"/>
        <path d="M1494 640 V660 M1484 650 H1504" stroke="var(--c-data, #4A5FA8)" stroke-width="1" fill="none" opacity="0.26"/>
      </g>
    </svg>`;

/* 基础脚本：main.js 必须排在页面级脚本之前，
   因为它负责定义 window.HQ（$ / $$ / rafThrottle），
   而 works.js 在文件顶部即读取 window.HQ。 */
/* v2.0 起移除 space.js（星球 Canvas 引擎整站下线，背景改为纯矢量几何层） */
const SCRIPTS_HEAD = `  <script src="js/main.js"></script>
`;

/* ---------------------------------------------------------------- 组装 */
/* 页面主体中可用 {{ico:图标名:尺寸类}} 占位符引用图标，避免重复书写 SVG。
   尺寸类允许同时给出多个类名（以空格分隔），例如 {{ico:arrowRight:ico-18 flow-arrow}} */
function expandIcons(html) {
  return html.replace(/\{\{ico:([a-zA-Z]+)(?::([^}]+))?\}\}/g, (m, name, cls) => {
    const clean = (cls || '').trim();
    return icon(name, clean || 'ico-20');
  });
}

/* 构建后自检：任何未被展开的占位符都必须暴露出来，避免静默输出到页面 */
function assertNoPlaceholder(html, file) {
  const left = html.match(/\{\{[^}]*\}\}/g);
  if (left) throw new Error(`${file} 存在未展开的占位符: ${left.slice(0, 5).join(' , ')}`);
  return html;
}

/* 页面主体可用 {{LIBRARY_URL}} 引用银河图书馆站地址（含 /library 基础路径） */
function expandLibraryUrl(html) {
  return html.replace(/\{\{LIBRARY_URL\}\}/g, LIBRARY_URL);
}

/* 页面主体可用 {{APP_DL_BUTTONS:按钮类名}} 生成三平台客户端下载按钮组 */
function expandAppButtons(html) {
  return html.replace(/\{\{APP_DL_BUTTONS:([^}]+)\}\}/g, (m, cls) => appDownloadButtons(cls.trim()));
}

function page({ title, desc, scene, file, current, scripts = '' }) {
  const bodyPath = path.join(SRC_DIR, file);
  if (!fs.existsSync(bodyPath)) throw new Error('缺少页面主体: ' + file);
  const body = expandAppButtons(expandLibraryUrl(expandIcons(fs.readFileSync(bodyPath, 'utf8').trimEnd())));

  const extraScripts = scripts
    ? scripts.split('\n').map((s) => '  <script src="' + s.trim() + '"></script>').join('\n') + '\n'
    : '';

  return assertNoPlaceholder(`<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${title}</title>
<meta name="description" content="${desc}">
<meta name="theme-color" content="#0B0E14">
<meta property="og:title" content="${title}">
<meta property="og:description" content="${desc}">
<meta property="og:type" content="website">
<link rel="icon" href="assets/logo/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/logo/favicon-32.png">
<link rel="apple-touch-icon" href="assets/logo/apple-touch-icon.png">
<link rel="preload" href="assets/fonts/noto-sans-sc-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/noto-sans-sc-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/inter-latin-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="css/style.css">
<script>/* 首帧前应用主题，避免闪色；图书馆链接带上当前主题，馆里首帧就同色 */(function(){try{document.documentElement.setAttribute('data-theme',localStorage.getItem('hq-theme')||'night')}catch(e){}})();
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a[href*="/library"]');if(!a)return;try{var t=localStorage.getItem('hq-theme');if(t==='day'||t==='night'){var u=new URL(a.href,location.href);u.searchParams.set('theme',t);a.href=u.toString()}}catch(err){}},true);</script>
</head>
<body id="top" data-scene="${scene}">
<a class="skip-link" href="#main">跳到主要内容</a>

<div class="scroll-progress" aria-hidden="true"></div>

<div class="bg-overlay" aria-hidden="true">
  <div class="bg-grid"></div>
  <div class="bg-stars"></div>
  ${CONSTELLATION}
  ${SHARD_A}
  ${SHARD_B}
  ${DAYSKY}
  <div class="bg-vignette"></div>
</div>

${buildHeader(current, scene)}

${body}

${buildFooter()}

${buildDrawer(current)}

${/* 银河图书馆整站免登录可读，这一页不放任何邀请码入口 ——
     弹窗是给「注册 / 在线阅读」用的，而这一页只讲拆解怎么读、怎么查，
     混进一个邀请码按钮只会让人以为馆内内容要凭证。 */ ''}
${scene === 'library' ? '' : buildInviteModal()}

<button class="to-top" type="button" aria-label="回到顶部">
  <svg class="ico ico-20" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
</button>

${SCRIPTS_HEAD}${extraScripts}</body>
</html>
`, file);
}

/* ---------------------------------------------------------------- 页面表 */
const PAGES = [
  {
    file: 'index.html',
    current: 'home',
    scene: 'home',
    title: '荒启科幻 · 数字内容与平台开发｜成都荒启文化传播有限公司官方网站',
    desc: '成都荒启文化传播有限公司官方网站。公司以数字内容制作、软件开发与技术服务为业务方向，开发并维护 APP「荒启科幻」与在线索引平台「银河图书馆」，并为纯公益社群「荒启科幻练笔」提供平台工具支持。'
  },
  {
    file: 'about.html',
    current: 'about',
    scene: 'about',
    title: '关于我们｜荒启科幻 · 成都荒启文化传播有限公司',
    desc: '成都荒启文化传播有限公司简介：公司定位、经营范围、主体信息与发展脉络，以及公司品牌「荒启科幻」、平台工具、银河图书馆与纯公益社群「荒启科幻练笔」的关系说明。'
  },
  {
    file: 'community.html',
    current: 'biz',
    scene: 'community',
    title: '科幻社群｜荒启科幻',
    desc: '「荒启科幻练笔」是由科幻爱好者自主运营的纯公益写作社群，每年九期，参加全程免费。投稿与评稿通过公司开发的 APP「荒启科幻」完成，算法分配评稿任务，每篇作品至少十位读者评稿。'
  },
  {
    file: 'tools.html',
    current: 'biz',
    scene: 'tools',
    title: '数字化产品开发｜荒启科幻',
    desc: '成都荒启文化传播有限公司的软件开发与技术服务：社区与内容系统、教育考试系统、AI 应用与定制开发四条业务线，一套代码覆盖 Web、H5、安卓、iOS 与小程序，支持托管、私有化部署与源码交付。'
  },
  {
    file: 'library.html',
    current: 'biz',
    scene: 'library',
    title: '银河图书馆｜荒启科幻',
    desc: '银河图书馆：成都荒启文化传播有限公司开发维护的免费在线科幻索引平台，每篇入馆作品做成可检索的科幻词库与两页拆解 —— 读者一页看核心设定与魅力，作者一页看剧情节点与修改方向。全部免登录可读，无需注册。'
  },
  {
    file: 'contact.html',
    current: 'contact',
    scene: 'contact',
    title: '联系我们｜荒启科幻 · 成都荒启文化传播有限公司',
    desc: '成都荒启文化传播有限公司的联系渠道：公司邮箱 huangqi.sifi@gmail.com、欧洲事务联系 founpghkf@gmail.com、微信公众号「荒启科幻」，以及公益社群「荒启科幻练笔」的加入方式。'
  }
];

/* ---------------------------------------------------------------- 执行 */
let count = 0;
PAGES.forEach((p) => {
  const html = page(p);
  fs.writeFileSync(path.join(ROOT, p.file), html, 'utf8');
  const lines = html.split('\n').length;
  console.log(`  ${p.file.padEnd(18)} ${lines} 行  ${(Buffer.byteLength(html, 'utf8') / 1024).toFixed(1)} KB`);
  count++;
});
console.log(`已生成 ${count} 个页面`);
console.log(`图书馆入口：${LIBRARY_URL}${process.env.LIBRARY_URL ? '（来自 LIBRARY_URL）' : '（默认值，生产请设 LIBRARY_URL=/library）'}`);
