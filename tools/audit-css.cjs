/*
 * CSS 引用审计（只读，不改文件）
 * 把 css-parts 里每条规则的选择器拆成原子类名，与「源码中真实出现过的 class」对表：
 *   来源 = .build/pages/*.html + tools/build-pages.cjs（外壳/顶栏/页脚/抽屉/弹窗）+ js/*.js（动态类）
 * 输出：完全没被引用的类、以及出现了但只在 CSS 里出现的类。
 * 用法：node tools/audit-css.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const CSS_DIR = path.join(ROOT, '.build', 'css-parts');
const PAGE_DIR = path.join(ROOT, '.build', 'pages');

/* ---------- 1. 收集源码里的 class ---------- */
const sources = [];
for (const f of fs.readdirSync(PAGE_DIR)) {
  if (f.endsWith('.html')) sources.push(fs.readFileSync(path.join(PAGE_DIR, f), 'utf8'));
}
sources.push(fs.readFileSync(path.join(ROOT, 'tools', 'build-pages.cjs'), 'utf8'));
for (const f of fs.readdirSync(path.join(ROOT, 'js'))) {
  if (f.endsWith('.js')) sources.push(fs.readFileSync(path.join(ROOT, 'js', f), 'utf8'));
}
const srcText = sources.join('\n');

/* class="a b c" 与 JS 里的 classList.add('x') / querySelector('.x') 都算引用 */
const used = new Set();
for (const m of srcText.matchAll(/class="([^"]*)"/g)) {
  m[1].split(/\s+/).forEach((c) => c && used.add(c));
}
for (const m of srcText.matchAll(/classList\.(?:add|remove|toggle|contains)\(\s*'([^']+)'/g)) {
  m[1].split(/\s+/).forEach((c) => c && used.add(c));
}
for (const m of srcText.matchAll(/querySelector(?:All)?\(\s*'\.([a-zA-Z0-9_-]+)'/g)) {
  used.add(m[1]);
}
/* 构建期的占位符会把类名写在参数里：{{ico:arrowRight:ico-18 flow-arrow}}、
   {{APP_DL_BUTTONS:btn--primary btn--sm}} —— 这些类同样算引用，否则误报 */
for (const m of srcText.matchAll(/\{\{ico:[a-zA-Z]+:([^}]+)\}\}/g)) {
  m[1].split(/\s+/).forEach((c) => c && used.add(c));
}
for (const m of srcText.matchAll(/\{\{APP_DL_BUTTONS:([^}]+)\}\}/g)) {
  m[1].split(/\s+/).forEach((c) => c && used.add(c));
}
/* 模板字符串里拼出来的类（cls / extraClass 之类）无法静态判定，白名单兜底 */
const WHITELIST = new Set([
  'is-open', 'is-active', 'is-current', 'is-stuck', 'is-visible', 'is-in', 'is-hidden',
  'is-dark', 'is-light', 'is-loading', 'is-error', 'is-off', 'is-reveal', 'no-scroll'
]);

/* ---------- 2. 解析 CSS 选择器里的类 ---------- */
const cssFiles = fs.readdirSync(CSS_DIR).filter((f) => f.endsWith('.css')).sort();
const report = [];
for (const f of cssFiles) {
  const text = fs.readFileSync(path.join(CSS_DIR, f), 'utf8');
  /* 去掉注释与 @规则名，只留选择器文本（粗略但够用） */
  const clean = text.replace(/\/\*[\s\S]*?\*\//g, '');
  for (const m of clean.matchAll(/(^|\})([^{}@]+)\{/g)) {
    const sel = m[2].trim();
    if (!sel || sel.startsWith('@') || sel.startsWith('from') || sel.startsWith('to') ||
        /^\d/.test(sel)) continue;
    const classes = [...sel.matchAll(/\.([a-zA-Z0-9_-]+)/g)].map((x) => x[1]);
    if (!classes.length) continue;
    const missing = classes.filter((c) => !used.has(c) && !WHITELIST.has(c));
    if (missing.length) report.push({ file: f, sel: sel.replace(/\s+/g, ' ').slice(0, 110), missing });
  }
}

/* ---------- 3. 输出 ---------- */
const byClass = new Map();
for (const r of report) for (const c of r.missing) {
  if (!byClass.has(c)) byClass.set(c, new Set());
  byClass.get(c).add(r.file);
}
if (!report.length) {
  console.log('没有发现未引用的类选择器。');
} else {
  console.log(`发现 ${report.length} 条规则含未引用类，涉及 ${byClass.size} 个类：\n`);
  for (const [c, files] of [...byClass].sort()) console.log(`  .${c}  ← ${[...files].join(', ')}`);
  console.log('');
  console.log('明细（选择器 → 缺失类）：');
  for (const r of report) console.log(`  [${r.file}] ${r.sel}\n      → ${r.missing.join(', ')}`);
}
