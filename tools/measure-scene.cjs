/* ==========================================================================
   场景亮度测量 —— 渲染结果的可读性体检
   --------------------------------------------------------------------------
   星云是铺在正文底下的，它的亮度直接决定文字对比度。凭肉眼在缩略图上看
   「亮不亮」很容易误判（屏幕伽马 + 缩略图缩放都会骗人），所以这里直接读
   像素统计：取一块不含星球、不含正文的纯星云区域，报出亮度分位数。

   用法： node tools/measure-scene.cjs home
         node tools/measure-scene.cjs home 0.00 0.00 0.42 0.50   # 自定义取样框
   ========================================================================== */
'use strict';
const path = require('path');
const { createCanvas, loadImage } = require('@napi-rs/canvas');

const name = process.argv[2] || 'home';
const R = [0.00, 0.00, 0.42, 0.50].map(Number);
if (process.argv.length >= 7) {
  for (let i = 0; i < 4; i++) R[i] = Number(process.argv[3 + i]);
}

(async () => {
  const img = await loadImage(path.join('.build/scenes', name + '.png'));
  const c = createCanvas(img.width, img.height);
  const x = c.getContext('2d');
  x.drawImage(img, 0, 0);

  const x0 = Math.round(img.width * R[0]), y0 = Math.round(img.height * R[1]);
  const w = Math.max(4, Math.round(img.width * (R[2] - R[0])));
  const h = Math.max(4, Math.round(img.height * (R[3] - R[1])));
  const d = x.getImageData(x0, y0, w, h).data;

  const L = new Float64Array(d.length / 4);
  let n = 0, sr = 0, sg = 0, sb = 0;
  for (let i = 0; i < d.length; i += 4) {
    L[n++] = 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
    sr += d[i]; sg += d[i + 1]; sb += d[i + 2];
  }
  const arr = Array.from(L).sort((a, b) => a - b);
  const q = p => arr[Math.min(arr.length - 1, Math.floor(arr.length * p))];

  /* 正文色取值：--text-1 #F2F5FD 与 --text-2 #B7BFCD。
     WCAG 相对亮度做在线性空间，粗算对比度用 sRGB 相对亮度即可看清趋势。 */
  const lum = (r, g, b) => {
    const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const cr = (fg, bg) => (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05);

  const bgP50 = q(0.5), bgP95 = q(0.95);
  const L1 = lum(242, 245, 253), L2 = lum(183, 191, 205);
  const g1 = v => Math.pow(v / 255 <= 0.03928 ? v / 255 / 12.92 : Math.pow((v / 255 + 0.055) / 1.055, 2.4), 1);
  const relLum = v => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };

  console.log('场景 ' + name + '  @ ' + img.width + 'x' + img.height);
  console.log('  取样框 x[' + R[0] + ',' + R[2] + '] y[' + R[1] + ',' + R[3] + ']  共 ' + n + ' px');
  console.log('  平均色 rgb(' + Math.round(sr / n) + ',' + Math.round(sg / n) + ',' + Math.round(sb / n) + ')');
  console.log('  亮度分位  p05=' + q(0.05).toFixed(1) + '  p25=' + q(0.25).toFixed(1) +
    '  p50=' + q(0.50).toFixed(1) + '  p75=' + q(0.75).toFixed(1) +
    '  p95=' + q(0.95).toFixed(1) + '  max=' + q(0.999).toFixed(1));
  console.log('  对比度（text-1 #F2F5FD / text-2 #B7BFCD 相对 p50 背景）: ' +
    cr(L1, relLum(bgP50)).toFixed(2) + ':1  /  ' + cr(L2, relLum(bgP50)).toFixed(2) + ':1');
  console.log('  对比度（相对 p95 背景）: ' +
    cr(L1, relLum(bgP95)).toFixed(2) + ':1  /  ' + cr(L2, relLum(bgP95)).toFixed(2) + ':1');
})();
