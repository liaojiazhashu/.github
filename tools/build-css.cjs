#!/usr/bin/env node
/* ==========================================================================
   样式合并 —— .build/css-parts/*.css  →  css/style.css
   --------------------------------------------------------------------------
   站点只加载一个 style.css（「CSS 全部集中在单一文件」是硬约定），但单一文件
   不好维护，所以源文件按 01–08 分区存放，构建时按文件名顺序拼接。

   用法： node tools/build-css.cjs
   ========================================================================== */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PARTS = path.join(ROOT, '.build', 'css-parts');
const OUT = path.join(ROOT, 'css', 'style.css');

const files = fs.readdirSync(PARTS).filter((f) => f.endsWith('.css')).sort();
if (!files.length) throw new Error('.build/css-parts 下没有找到任何 .css 分区文件');

const chunks = files.map((f) => {
  const body = fs.readFileSync(path.join(PARTS, f), 'utf8');
  return '/* ---- ' + f + ' ' + '-'.repeat(Math.max(2, 66 - f.length)) + ' */\n\n' + body.trimEnd() + '\n';
});

const out = chunks.join('\n');
fs.writeFileSync(OUT, out);

const lines = out.split('\n').length;
console.log('合并 ' + files.length + ' 个分区 → css/style.css');
files.forEach((f) => console.log('  · ' + f));
console.log('  共 ' + lines + ' 行 / ' + (Buffer.byteLength(out) / 1024).toFixed(1) + ' KB');
