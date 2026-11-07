/*
 * v2.3.8 代码优化：删除 CSS 源文件中确认未被 HTML / JS 引用的死规则
 * （表单组件随 contact 页「去表单化」下线、引擎层 .bg-canvas 已退役等）
 *
 * 结构化解析 { } 块：从分组选择器中摘除目标类，摘空的分组整体删除；
 * @media / @supports / @layer 递归处理，声明体原样保留。幂等，可重复执行。
 *
 * 用法：node tools/prune-dead-css.cjs
 */
const fs = require('fs');
const path = require('path');

const DIR = path.join(__dirname, '..', '.build', 'css-parts');

/* 经交叉核对（HTML + JS 均无引用）的死类 */
const DEAD = [
  'bg-canvas',
  'card--flat', 'card--solid', 'card--ghost', 'card--pad-lg',
  'logo-box--deep', 'page-hero__inner', 'brand__mark',
  'quote', 'quote__src', 'figure-inline', 'ring-rhythm', 'page-btn',
  'title-underline', 'hover-lift', 'loading-dots',
  'badge--accent', 'badge__dot', 'eyebrow--plain', 'metric__ico',
  'text-accent', 'text-warn', 'display-latin', 'lead', 'balance', 'h-display',
  'flow-sm', 'flow-lg', 'ico-28', 'ico-32',
  'section--plain', 'section-head--center', 'grid--auto-sm',
  'split--reverse', 'row--tight', 'data-table--compact',
  /* 已去表单化：表单组件样式整体下线 */
  'radio-card', 'radio-card__dot', 'radio-card__title', 'radio-card__desc',
  'field-row', 'field__label', 'field__hint', 'field__error',
  'opt__text', 'is-invalid', 'req',
  /* v2.3.19 内容去重后新增的死类（由 tools/audit-css.cjs 交叉核对） */
  /* 首页删掉「生态闭环」与「核心价值」两节 */
  'loop', 'loop__desc', 'loop__idx', 'loop__node', 'loop__title',
  /* 首页删掉与首屏 hero__meta 重复的数据条 */
  'stat', 'stat-bar', 'stat__label', 'stat__note', 'stat__value',
  /* 社群页删掉与条形图重复的九宫格 */
  'rhythm', 'rhythm__idx', 'rhythm__item', 'rhythm__item--now', 'rhythm__label',
  'section--tight', 'notice--warn',
  /* v2.3.17 探测器只有一个喷口，喷口分组的样式残留 */
  'hero-ship__jet-side',
  /* 作品库改版为知识图谱后遗留的筛选 / 工具栏 / 分页 / 空状态 */
  'works-toolbar', 'works-toolbar__meta', 'works-toolbar__spacer',
  'filter-panel', 'filter-grid', 'filter-grid--wide', 'filter-group__title',
  'sort-group', 'sort-btn', 'active-filters', 'active-filters__clear', 'search-field',
  'empty-state', 'empty-state__action', 'empty-state__desc', 'empty-state__title',
  'pagination', 'badge',
  /* 去表单化后剩余的裸控件类（HTML 侧已无任何引用） */
  'field', 'input', 'select', 'textarea', 'opt', 'opt__box',
  /* v2.3.19 第二轮：工具页删掉「技术优势」整节（与部署方案 / 能力矩阵逐条重复） */
  'feature', 'feature__title', 'feature__desc',
  /* 其余由 audit-css 复核确认无引用 */
  'btn--link', 'flow', 'ico-40', 'text-grad',
  'work-card__author', 'work-card__foot',
  /* v2.3.23 图书馆按「四件产出」重写，删掉「三种交付物」整节后 .entry-card 只剩
     tools 页的 __foot 还在用（它的样式与 card 一致），主体规则随之作废 */
  'entry-card', 'entry-card__title', 'entry-card__desc', 'entry-card__list',
];

const isDead = (sel) => DEAD.some((d) => new RegExp(`\\.${d}(?![a-zA-Z0-9_-])`).test(sel));

function matchBrace(s, open) {
  let d = 0;
  for (let i = open; i < s.length; i++) {
    if (s[i] === '{') d++;
    else if (s[i] === '}') { d--; if (d === 0) return i; }
  }
  return s.length - 1;
}

function prune(css, stats) {
  let out = '';
  let prelude = '';
  let i = 0;
  while (i < css.length) {
    const ch = css[i];
    if (ch === '{') {
      const end = matchBrace(css, i);
      const inner = css.slice(i + 1, end);
      const p = prelude.trim();
      /* 前置注释会让 ^@media 匹配失败，于是整个 @media 块被当成普通规则原样放过 ——
         v2.3.19 修：判断前先剥掉注释（注释本身仍随 prelude 原样保留） */
      const pClean = p.replace(/\/\*[\s\S]*?\*\//g, '').trim();
      if (/^@(media|supports|layer)/.test(pClean)) {
        out += prelude + '{' + prune(inner, stats) + '}';
      } else {
        const indent = (prelude.match(/\n([ \t]*)$/) || [, ''])[1];
        const sels = pClean.split(',').map((s) => s.trim()).filter(Boolean);
        const kept = sels.filter((s) => !isDead(s));
        stats.removed += sels.length - kept.length;
        if (kept.length) out += (out === '' ? '' : '\n') + indent + kept.join(', ') + ' {' + inner + '}';
      }
      i = end + 1;
      prelude = '';
      continue;
    }
    prelude += ch;
    i++;
  }
  return out + prelude;
}

let total = 0;
for (const file of fs.readdirSync(DIR).filter((f) => f.endsWith('.css')).sort()) {
  const p = path.join(DIR, file);
  const src = fs.readFileSync(p, 'utf8');
  const stats = { removed: 0 };
  const out = prune(src, stats).replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n');
  if (stats.removed) fs.writeFileSync(p, out);
  console.log(`${file}: 移除 ${stats.removed} 个选择器`);
  total += stats.removed;
}
console.log(`共移除 ${total} 个选择器`);
