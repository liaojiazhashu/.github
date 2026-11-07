/*
 * v2.3.6 一次性迁移：把内联 SVG 插图中残留的 v1.x 旧品牌色（星云紫 / 薄荷青 /
 * 深空蓝系硬编码色）批量替换为 v2.0 设计令牌，使示意图在昼夜两模式都成立。
 *
 * 规则（按上下文区分 fill / stroke）：
 *   fill="<深蓝系>"   → fill="var(--surface-3)"   （节点底板，随主题翻浅）
 *   stroke="<深空蓝系>" → stroke="var(--line-2)"   （描边，随主题翻浅）
 *   星云紫   #6C63FF → var(--accent-1)   薄荷青 #00D4AA → var(--accent-2)
 *   浅紫     #B9B5FF → var(--accent-1-hi)
 *   告警红   #FF8080 → var(--accent-warn)
 *   浅字     #F0F3FB → var(--text-1)  #A8B0BD → var(--text-3)  #7A83A8 → var(--text-4)
 *
 * 用法：node tools/migrate-old-svg-palette.cjs（幂等，可重复执行）
 */
const fs = require('fs');
const path = require('path');

const PAGES = ['about.html', 'library.html', 'community.html', 'tools.html'];
const DIR = path.join(__dirname, '..', '.build', 'pages');

const FILL_MAP = {
  '#1F2A4A': 'var(--surface-3)',
  '#18213E': 'var(--surface-3)',
  '#2A3559': 'var(--surface-3)',
  '#2A3470': 'var(--surface-3)',
  '#26315A': 'var(--surface-3)',
  '#232E55': 'var(--surface-2)',
  '#161E44': 'var(--surface-2)',
  '#161E3E': 'var(--surface-2)',
  '#0F1631': 'var(--surface-2)',
  '#0B1026': 'var(--surface-1)',
};

const STROKE_MAP = {
  '#33406E': 'var(--line-2)',
  '#565E82': 'var(--line-2)',
  '#46539A': 'var(--line-2)',
};

const BOTH_MAP = {
  '#6C63FF': 'var(--accent-1)',
  '#00D4AA': 'var(--accent-2)',
  '#B9B5FF': 'var(--accent-1-hi)',
  '#FF8080': 'var(--accent-warn)',
  '#F0F3FB': 'var(--text-1)',
  '#A8B0BD': 'var(--text-3)',
  '#7A83A8': 'var(--text-4)',
};

let total = 0;
for (const file of PAGES) {
  const p = path.join(DIR, file);
  let src = fs.readFileSync(p, 'utf8');
  let n = 0;

  for (const [hex, token] of Object.entries(FILL_MAP)) {
    src = src.replace(new RegExp(`fill="${hex}"`, 'g'), () => (n++, `fill="${token}"`));
  }
  for (const [hex, token] of Object.entries(STROKE_MAP)) {
    src = src.replace(new RegExp(`stroke="${hex}"`, 'g'), () => (n++, `stroke="${token}"`));
  }
  for (const [hex, token] of Object.entries(BOTH_MAP)) {
    src = src.replace(new RegExp(`(fill|stroke)="${hex}"`, 'g'), (_m, a) => (n++, `${a}="${token}"`));
  }

  /* 旧色 rgba 形式：紫 / 青的半透明填充与描边 */
  src = src.replace(/rgba\(108,\s*99,\s*255,\s*([0-9.]+)\)/g, (_m, a) => (n++, `rgba(79, 193, 224, ${a})`));
  src = src.replace(/rgba\(0,\s*212,\s*170,\s*([0-9.]+)\)/g, (_m, a) => (n++, `rgba(139, 158, 216, ${a})`));

  /* 渐变 stop-color 与图例色点（style="background:#..."） */
  for (const [hex, token] of Object.entries(BOTH_MAP)) {
    src = src.replace(new RegExp(`stop-color="${hex}"`, 'g'), () => (n++, `stop-color="${token}"`));
    src = src.replace(new RegExp(`background:${hex}`, 'g'), () => (n++, `background:${token}`));
  }
  src = src.replace(/stop-color="#33406E"/g, () => (n++, 'stop-color="var(--line-2)"'));
  src = src.replace(/background:#33406E/g, () => (n++, 'background:var(--line-2)'));
  src = src.replace(/stop-color="#1F2A4A"/g, () => (n++, 'stop-color="var(--surface-3)"'));
  src = src.replace(/background:#1F2A4A/g, () => (n++, 'background:var(--surface-3)'));

  if (n) fs.writeFileSync(p, src);
  console.log(`${file}: 替换 ${n} 处`);
  total += n;
}
console.log(`共替换 ${total} 处旧色`);
