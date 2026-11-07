#!/usr/bin/env node
/* ==========================================================================
   荒启科幻官网 · 站点自检
   --------------------------------------------------------------------------
   在无浏览器环境下尽可能覆盖可自动验证的质量项：

     [HTML] 标题层级 / 唯一 id / 标签配对 / 按钮类型 / 图片替代文本
     [链接] 本地文件与锚点是否可解析
     [资源] 页面与 CSS 引用的全部本地资源是否存在
     [规范] emoji 禁用 / 外部请求禁用 / lang 与 meta 是否齐全
     [样式] 花括号平衡 / var() 引用的自定义属性是否已定义 / 未使用的令牌
     [脚本] 页面引入的脚本文件是否存在

   用法：node tools/check-site.cjs
   退出码：0 全部通过；1 存在错误
   ========================================================================== */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const errors = [];
const warnings = [];
const stats = {};

/* 外链白名单：只允许业务必需的外部导航跳转。
   · beian.miit.gov.cn —— ICP 备案号查询，法规要求页脚必须可点，
     与 build-pages.cjs 页脚模板里的备案链接对应；
   · 在线阅读站已改为站点内相对路径 /h5/（同域部署），不再需要域名白名单。 */
const EXT_NAV_ALLOW = [
  'https://beian.miit.gov.cn/',
  'https://www.gsxt.gov.cn/' // 国家企业信用信息公示系统：about.html 登记信息核验指引（政府权威源）
];

/* 站内绝对路径（/h5/、/dt/xxx.exe 等）指向部署环境的根目录，
   不在导出目录内 —— 本地无法校验存在性，只做「形如站内绝对路径」的格式校验。 */
const isRootAbsolute = (href) => href.startsWith('/') && !href.startsWith('//');

function fail(file, msg) { errors.push(`  ✗ [${file}] ${msg}`); }
function warn(file, msg) { warnings.push(`  ! [${file}] ${msg}`); }

function read(rel) { return fs.readFileSync(path.join(ROOT, rel), 'utf8'); }
function exists(rel) { return fs.existsSync(path.join(ROOT, rel)); }

/* ---------------------------------------------------------------- 页面清单 */
const htmlFiles = fs
  .readdirSync(ROOT)
  .filter((f) => f.endsWith('.html'))
  .sort();

if (!htmlFiles.length) {
  console.error('未找到任何 HTML 页面');
  process.exit(1);
}

/* 每页的锚点集合，供跨页链接校验 */
const anchors = {};
htmlFiles.forEach((f) => {
  anchors[f] = new Set(
    [...read(f).matchAll(/\sid="([^"]+)"/g)].map((m) => m[1])
  );
});

/* ======================================================== 一、HTML 结构检查 */

/* 需要成对出现的标签 */
const PAIRED = [
  'html', 'head', 'body', 'main', 'header', 'footer', 'nav', 'section',
  'article', 'aside', 'div', 'span', 'p', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
  'a', 'button', 'label', 'form', 'table', 'thead', 'tbody', 'tr', 'td', 'th',
  'caption', 'figure', 'figcaption', 'blockquote', 'strong', 'b', 'em', 'i',
  'small', 'code', 'pre', 'svg', 'g', 'defs', 'select', 'option', 'textarea',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6'
];

const VOID = new Set([
  'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
  'link', 'meta', 'param', 'source', 'track', 'wbr'
]);

/* 标签配对（仅做栈式粗检，不处理 CDATA / 注释内的伪标签） */
function checkTagBalance(file, html) {
  const clean = html
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '');
  const tagRe = /<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|'[^']*'|[^>"'])*?)(\/?)>/g;
  const stack = [];
  let m;
  while ((m = tagRe.exec(clean)) !== null) {
    const closing = m[1] === '/';
    const name = m[2].toLowerCase();
    const selfClose = m[4] === '/';
    if (void_is(name) || selfClose) continue;
    if (VOID.has(name)) continue;
    if (!PAIRED.includes(name)) continue;
    if (closing) {
      if (!stack.length) {
        fail(file, `多余的闭合标签 </${name}>`);
        continue;
      }
      const top = stack.pop();
      if (top.name !== name) {
        fail(file, `标签未正确闭合：<${top.name}> 被 </${name}> 关闭（第 ${lineOf(html, top.index)} 行）`);
        // 尝试恢复：把不匹配的重新压回
        if (top.name !== name) stack.push(top);
      }
    } else {
      stack.push({ name, index: m.index });
    }
  }
  stack.forEach((s) => fail(file, `未闭合的标签 <${s.name}>（第 ${lineOf(html, s.index)} 行）`));
}
function void_is() { return false; }
function lineOf(text, index) { return text.slice(0, index).split('\n').length; }

htmlFiles.forEach((file) => {
  const html = read(file);

  /* --- lang / title / description --- */
  if (!/<html[^>]+lang="zh-CN"/.test(html)) fail(file, '缺少 lang="zh-CN"');
  if (!/<title>[^<]{4,}<\/title>/.test(html)) fail(file, '缺少有效 <title>');
  if (!/<meta\s+name="description"\s+content="[^"]{10,}"/.test(html)) {
    fail(file, '缺少有效的 meta description');
  }
  if (!/<meta\s+name="viewport"[^>]*width=device-width/.test(html)) {
    fail(file, '缺少响应式 viewport');
  }

  /* --- 标题层级 --- */
  const h1s = [...html.matchAll(/<h1[\s>]/g)].length;
  if (h1s !== 1) fail(file, `<h1> 数量为 ${h1s}，应为 1`);

  /* --- 唯一 id --- */
  const ids = [...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
  const dup = ids.filter((v, i) => ids.indexOf(v) !== i);
  if (dup.length) fail(file, `存在重复 id：${[...new Set(dup)].join(', ')}`);

  /* --- 标签配对 --- */
  checkTagBalance(file, html);

  /* --- 按钮必须有 type --- */
  [...html.matchAll(/<button\b((?:"[^"]*"|'[^']*'|[^>])*)>/g)].forEach((m) => {
    if (!/\stype=/.test(m[1])) {
      fail(file, `<button> 缺少 type 属性（第 ${lineOf(html, m.index)} 行）`);
    }
  });

  /* --- 图片必须有 alt 且资源存在 --- */
  [...html.matchAll(/<img\b((?:"[^"]*"|'[^']*'|[^>])*)>/g)].forEach((m) => {
    const attrs = m[1];
    const src = (attrs.match(/\ssrc="([^"]+)"/) || [])[1];
    if (!/\salt=/.test(attrs)) {
      fail(file, `<img> 缺少 alt（src=${src || '?'}）`);
    }
    if (src && !/^(https?:|data:)/.test(src) && !exists(src.replace(/^\//, ''))) {
      fail(file, `<img> 引用的文件不存在：${src}`);
    }
  });

  /* --- 内联 SVG 应标注 aria-hidden 或 role --- */
  const svgOpen = [...html.matchAll(/<svg\b((?:"[^"]*"|'[^']*'|[^>])*)>/g)];
  const svgBad = svgOpen.filter((m) => !/aria-hidden|role=|aria-label/.test(m[1]));
  if (svgBad.length) {
    warn(file, `${svgBad.length} 个内联 <svg> 未标注 aria-hidden / role`);
  }

  /* --- 中文排版：正文中不应出现英文直引号 --- */
  const textOnly = html
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<[^>]*>/g, '\u0001')
    .split('\u0001');
  textOnly.forEach((t) => {
    const re = /[\u4e00-\u9fff]\s?"|"\s?[\u4e00-\u9fff]/g;
    let m;
    while ((m = re.exec(t)) !== null) {
      fail(file, `中文正文中出现英文直引号，应使用「」：…${t.slice(Math.max(0, m.index - 10), m.index + 12).trim()}…`);
    }
  });

  /* --- emoji 禁用 --- */
  const emojiRe = /[\u{1F000}-\u{1FAFF}\u{1F1E6}-\u{1F1FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{FE0F}\u{2B00}-\u{2BFF}\u{1F900}-\u{1F9FF}]/gu;
  const emojiHit = [...new Set((html.match(emojiRe) || []))];
  if (emojiHit.length) fail(file, `出现被禁用的 emoji 字符：${emojiHit.join(' ')}`);

  /* --- 外部资源禁用（v2.3.12 起允许导航外链） ---
     资源加载（src= / <link href=）仍然零外链；
     <a href> 的外部导航跳转（如在线阅读 H5 站）是业务需求，允许，
     但域名必须在 READER_URL_BASE 白名单内。 */
  const ext = [...html.matchAll(/(?:src|href)="(https?:\/\/[^"]+)"/g)].map((m) => m[1]);
  const extNav = [];
  const extRes = ext.filter((url) => {
    const idx = html.indexOf(`"${url}"`);
    const tagStart = html.lastIndexOf('<', idx);
    const tag = html.slice(tagStart, idx);
    if (/<a\b[^>]*$/.test(tag)) { extNav.push(url); return false; }
    return true;
  });
  if (extRes.length) fail(file, `存在外部资源请求：${[...new Set(extRes)].join(', ')}`);
  const badNav = [...new Set(extNav)].filter(
    (u) => !EXT_NAV_ALLOW.some((base) => u.startsWith(base))
  );
  if (badNav.length) fail(file, `存在非白名单外链：${badNav.join(', ')}`);

  /* --- 链接与锚点 --- */
  [...html.matchAll(/href="([^"]*)"/g)].forEach((m) => {
    const href = m[1];
    if (/^(https?:|mailto:|tel:|data:)/.test(href)) return;
    if (href === '') return;
    if (href.startsWith('#')) {
      if (href.length > 1 && !anchors[file].has(href.slice(1))) {
        fail(file, `页内锚点不存在：${href}`);
      }
      return;
    }
    if (isRootAbsolute(href)) return;   /* /h5/、/dt/* —— 部署环境路径，不在本地校验 */
    const [p, hash] = href.split('#');
    if (!p) return;
    if (!exists(p)) { fail(file, `链接目标文件不存在：${href}`); return; }
    if (hash && p.endsWith('.html') && anchors[p] && !anchors[p].has(hash)) {
      fail(file, `跨页锚点不存在：${href}`);
    }
  });

  /* --- 脚本存在性与加载顺序 --- */
  const scriptSrcs = [...html.matchAll(/<script\s+src="([^"]+)"/g)].map((m) => m[1]);
  scriptSrcs.forEach((s) => {
    if (!exists(s)) fail(file, `脚本文件不存在：${s}`);
  });

  /* 脚本加载顺序：main.js 定义共享工具层 window.HQ（$ / $$ / rafThrottle），
     页面级脚本（library.js 等）都在文件顶部取用它，
     因此 main.js 必须排在所有其他脚本之前。
     （v2.3.19：space.js 已归档到 docs/archive/，线上不再有任何前置脚本） */
  const mainAt = scriptSrcs.indexOf('js/main.js');
  const allowedBefore = [];
  if (mainAt === -1) {
    fail(file, '未加载 js/main.js');
  } else {
    scriptSrcs.forEach((s, i) => {
      if (i >= mainAt) return; // 只看 main.js 之前的脚本
      if (allowedBefore.includes(s)) return;
      fail(file, `脚本顺序错误：${s} 排在 js/main.js 之前（main.js 负责定义 window.HQ）`);
    });
  }

  /* --- 样式表存在性 --- */
  [...html.matchAll(/<link[^>]+href="([^"]+\.css)"/g)].forEach((m) => {
    if (!exists(m[1])) fail(file, `样式文件不存在：${m[1]}`);
  });

  stats[file] = {
    lines: html.split('\n').length,
    kb: (Buffer.byteLength(html) / 1024).toFixed(1),
    h2: [...html.matchAll(/<h2[\s>]/g)].length,
    h3: [...html.matchAll(/<h3[\s>]/g)].length,
    svg: svgOpen.length
  };
});

/* ========================================================== 二、CSS 检查 */
if (exists('css/style.css')) {
  const css = read('css/style.css');

  const open = (css.match(/{/g) || []).length;
  const close = (css.match(/}/g) || []).length;
  if (open !== close) fail('css/style.css', `花括号不平衡：{ = ${open}，} = ${close}`);

  /* 自定义属性定义与使用 */
  /* 以下变量由 JavaScript 或元素内联样式在运行时注入，不在样式表中定义：
       --reveal-delay  元素内联 style 提供入场延迟
       --sx / --sy     main.js 的卡片光斑跟随实时写入 */
  const RUNTIME_VARS = new Set(['--reveal-delay', '--sx', '--sy']);

  /* 扫描 var() 之前先剥掉注释：注释里出现的 var(--x) 不是真实引用。
     这不是理论问题 —— 05-components-content.css 开头就用注释记着
     「本套令牌里没有 --line，写成 var(--line) 会静默失效」，不剥注释就当成了真引用。 */
  const cssCode = css.replace(/\/\*[\s\S]*?\*\//g, '');

  const defined = new Set(
    [...cssCode.matchAll(/(--[a-zA-Z0-9-]+)\s*:/g)].map((m) => m[1])
  );
  const used = new Set(
    [...cssCode.matchAll(/var\(\s*(--[a-zA-Z0-9-]+)/g)].map((m) => m[1])
  );
  const undefinedVars = [...used].filter((v) => !defined.has(v) && !RUNTIME_VARS.has(v));
  if (undefinedVars.length) {
    fail('css/style.css', `引用了未定义的自定义属性：${undefinedVars.join(', ')}`);
  }

  /* 运行时变量必须提供回退值，否则声明会整条失效 */
  RUNTIME_VARS.forEach((v) => {
    const re = new RegExp('var\\(\\s*' + v + '\\s*\\)');
    if (re.test(cssCode)) fail('css/style.css', `var(${v}) 缺少回退值`);
  });

  /* 令牌数量统计（未使用项仅作提示，可能是留给后续扩展的） */
  const localTokens = new Set(
    [...css.matchAll(/^\s*(--[a-zA-Z0-9-]+)\s*:/gm)].map((m) => m[1])
  );
  /* 内联 SVG 里的 var() 只写在页面 HTML 里（--sun / --sun-line / --warp-flame …），
     只扫 CSS 会把这类「仅被插图使用」的令牌误判为遗留。把七页 HTML 一并计入
     使用集合（v2.3.16）。 */
  const pagesSource = htmlFiles.map((f) => read(f)).join('\n');
  /* 必须允许 var(--x, 回退值) 的写法：内联 SVG 一律带回退值（首帧令牌可能未就绪），
     用裸的 includes('var(--x)') 会把这些全部判成未使用。 */
  const refIn = (src, v) => new RegExp(`var\\(\\s*${v}\\s*[,)]`).test(src);
  const unused = [...localTokens].filter(
    (v) => !used.has(v) && !refIn(css, v) && !refIn(pagesSource, v)
  );

  /* 外部请求 */
  const cssExt = [...new Set(
    [...css.matchAll(/url\(['"]?(https?:\/\/[^)'"]+)/g)].map((m) => m[1])
  )];
  if (cssExt.length) fail('css/style.css', `存在外部资源请求：${cssExt.join(', ')}`);

  /* 字体文件存在性 */
  [...css.matchAll(/url\(['"]?([^'")]+\.woff2)['"]?\)/g)].forEach((m) => {
    const p = path.join('css', m[1]);
    if (!exists(p)) fail('css/style.css', `字体文件不存在：${m[1]}`);
  });

  stats['css/style.css'] = {
    lines: css.split('\n').length,
    kb: (Buffer.byteLength(css) / 1024).toFixed(1),
    tokens: localTokens.size,
    unusedTokens: unused.length
  };
  /* 已定义但未被引用的令牌。设计系统需要保持刻度完整，
     下列令牌在 01-tokens.css 中已显式标注为「预留待用」，
     因此只作为说明输出；出现列表之外的名称才提示为疑似遗留代码。 */
  const RESERVED = new Set([
    '--c-deepspace', '--c-nebula', '--c-data',
    '--bg-0', '--bg-2', '--bg-3',
    '--surface-1', '--surface-2', '--surface-3',
    '--surface-glass-2', '--line-soft', '--accent-1-dim',
    '--sp-1', '--sp-9', '--sp-10',
    '--sh-1', '--glow-1', '--glow-2'
  ]);
  const reservedHit = unused.filter((v) => RESERVED.has(v));
  const strayTokens = unused.filter((v) => !RESERVED.has(v));
  if (strayTokens.length) {
    warn('css/style.css', `疑似遗留的自定义属性：${strayTokens.join(', ')}`);
  }
  stats['css/style.css'].reserved = reservedHit.length;
} else {
  fail('css/style.css', '缺少唯一样式文件');
}

/* ========================================================== 三、目录检查 */
if (fs.existsSync(path.join(ROOT, 'css'))) {
  const cssFiles = fs.readdirSync(path.join(ROOT, 'css')).filter((f) => f.endsWith('.css'));
  if (cssFiles.length !== 1) {
    fail('css/', `css 目录应只包含一个样式文件，实际有 ${cssFiles.length} 个：${cssFiles.join(', ')}`);
  }
}

/* ============================================================== 输出报告 */
const label = (s, w = 22) => s.padEnd(w);

console.log('\n荒启科幻官网 · 站点自检\n');
console.log('页面概览');
console.log('  ' + label('文件') + label('行数', 8) + label('大小', 10) + label('h2/h3', 10) + 'SVG');
Object.entries(stats).forEach(([k, v]) => {
  if (!k.endsWith('.html')) return;
  console.log(
    '  ' + label(k, 22) + label(String(v.lines), 8) + label(v.kb + ' KB', 10) +
    label(`${v.h2}/${v.h3}`, 10) + v.svg
  );
});
if (stats['css/style.css']) {
  const c = stats['css/style.css'];
  console.log(
    '\n样式\n  ' + label('css/style.css', 22) + label(String(c.lines), 8) +
    label(c.kb + ' KB', 10) + `令牌 ${c.tokens} 个（其中预留 ${c.reserved} 个）`
  );
}

if (warnings.length) {
  console.log('\n提示 (' + warnings.length + ')');
  warnings.forEach((w) => console.log(w));
}

if (errors.length) {
  console.log('\n错误 (' + errors.length + ')');
  errors.forEach((e) => console.log(e));
  console.log('\n自检未通过。\n');
  process.exit(1);
}

console.log('\n自检全部通过。\n');
