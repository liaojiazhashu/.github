/* 首屏探测器描边补偿（幂等，按倍率一次性执行）
 *
 * 起因：`.hero-ship` 的显示宽度每砍一次，SVG 里的线宽就跟着被缩小同样的倍数。
 * 只缩尺寸不补线宽，船不是变小，而是变糊 —— 主轮廓跌破 1px 之后读不出结构。
 * 所以每次改 CSS 宽度，都要按同一倍率反向补偿 stroke-width 与「标识件」半径：
 *
 *   v2.3.18  26vw → 14vw   ×0.539  补偿 ×1.5   （主轮廓 3.1 → 4.65）
 *   v2.3.22  14vw →  7vw   ×0.5    补偿 ×2     （主轮廓 4.65 → 9.3）
 *
 * 两次补偿后渲染线宽都落在 1.0px 左右：船变小了，探针头 / 主舱横肋 /
 * 环形推进器三段仍然读得出来，只是退到了更远处。
 *
 * 用法：node tools/scale-ship-strokes.cjs [倍率] [标记]
 *   例：node tools/scale-ship-strokes.cjs 2 v2322
 * 只改属性数值，不动任何坐标与 transform。svg 上带 data-scale 标记，
 * 同一标记重复执行会直接跳过（不支持无标记累乘）。
 */
const fs = require('fs');
const path = require('path');

const K = parseFloat(process.argv[2] || '1.5');
const MARK = process.argv[3] || 'v2318';
if (!(K > 0)) throw new Error('倍率必须是正数');

/* 这几个半径是「几何」而不是「标识件」，任何倍率下都不能动：
   核心舱 r=20/13、环形推进器外缘 90 / 包边 81 / 内壁 72。
   其余（探针尖 2.7、节点 3.9、舱口 4.5、信标 4.8、传感眼 8.4/15）是点状标识件，
   必须与线宽同倍率放大，否则缩小后只剩半个像素的点。 */
const GEOM_R = new Set(['13', '20', '72', '81', '90']);

const FILE = path.join(__dirname, '..', '.build', 'pages', 'index.html');
let src = fs.readFileSync(FILE, 'utf8');

const start = src.indexOf('<svg class="hero-ship"');
if (start < 0) throw new Error('未找到 .hero-ship');
const endTag = '</svg>';
const end = src.indexOf(endTag, start) + endTag.length;
if (end < endTag.length) throw new Error('未找到 </svg>');

let block = src.slice(start, end);
if (block.includes(`data-scale="${MARK}"`)) {
  console.log(`已经是 ${MARK}，跳过`);
  process.exit(0);
}

const log = [];
const num = (v) => Math.round(v * K * 100) / 100;

block = block.replace(/stroke-width="([\d.]+)"/g, (m, v) => {
  const n = num(parseFloat(v));
  log.push(`stroke-width ${v} → ${n}`);
  return `stroke-width="${n}"`;
});

block = block.replace(/\br="([\d.]+)"/g, (m, v) => {
  if (GEOM_R.has(v)) return m;
  const n = num(parseFloat(v));
  log.push(`r ${v} → ${n}`);
  return `r="${n}"`;
});

/* 虚线的节奏跟着线宽走，否则缩小后点阵密度会翻倍 */
block = block.replace(/stroke-dasharray="([\d.\s]+)"/g, (m, v) => {
  const parts = v.trim().split(/\s+/).map((x) => num(parseFloat(x)));
  log.push(`dasharray ${v.trim()} → ${parts.join(' ')}`);
  return `stroke-dasharray="${parts.join(' ')}"`;
});

/* 标记：替换而不是追加，避免 svg 上挂着两个 data-scale */
block = block.replace(/ data-scale="[^"]*"/, '');
block = block.replace('<svg class="hero-ship"', `<svg class="hero-ship" data-scale="${MARK}"`);

src = src.slice(0, start) + block + src.slice(end);
fs.writeFileSync(FILE, src);
console.log(log.join('\n'));
console.log(`\n共 ${log.length} 处，倍率 ${K}，标记 ${MARK}`);
