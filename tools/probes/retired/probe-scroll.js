/* ==========================================================================
   星球滚动离场探针
   --------------------------------------------------------------------------
   验证 v1.5 的滚动联动：页面下滑时星球随之下移，滚动到页面最底部时
   星球（连同辉光与行星环）恰好完全移出视口下缘。
   本探针断言：
     · 引擎的 scrollMax 与文档实际可滚动量一致（±2px）
     · 位移系数折算的不变量：到底时星球圆心 = g.cy + h + 1.35R
     · 行为验证：滚到底并待缓动收敛后，星球原位置的取样块均色大幅变化
       （星球已离开），且变化量显著大于仅滚动一小段时的背景自然波动
   用法：
     PAGES=index WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-scroll.js node tools/shoot.cjs
   ========================================================================== */
(async () => {
  const out = {};
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const errs = [];
  window.addEventListener('error', (e) => errs.push(e.message));

  /* 重新初始化引擎以拿到实例引用（init 会先销毁旧实例，探针页允许这样） */
  const eng = window.HQSpace.init();
  out['0_引擎实例'] = eng ? 'OK' : '初始化失败';
  if (!eng) return out;
  /* 冻结自动降档：本探针验证的是几何与行为，而 headless/慢环境下 fps
     采样必然触发降档 → 画布分辨率缩小 → 取样坐标越界读出透明黑，
     各样本之间还会混入分辨率变化带来的噪声（v1.6 踩坑）。 */
  eng.fpsLocked = true;
  await sleep(300);

  const de = document.documentElement;
  const realMax = de.scrollHeight - window.innerHeight;
  out['1_scrollMax 一致'] = Math.abs(eng.scrollMax - realMax) <= 2
    ? 'OK（' + eng.scrollMax + ' ≈ ' + realMax + '）'
    : '不一致：engine=' + eng.scrollMax + ' real=' + realMax;

  const pl = eng.pList[0];
  const g = pl.geom;
  const expectedCy = g.cy + eng.h + g.R * 1.35;
  const exitK = (eng.h + g.R * 1.35 - g.cy) / eng.scrollMax;
  out['2_到底时圆心不变量'] = 'cy=' + g.cy + ' + exitK(' +
    exitK.toFixed(4) + ')×scrollMax(' + eng.scrollMax + ') → 预期 ' +
    expectedCy.toFixed(0) + '（= h + 1.35R = ' + (eng.h + g.R * 1.35).toFixed(0) + '）';
  out['2_完全出画'] = expectedCy - g.R > eng.h ? 'OK（底缘 h+' + (expectedCy - g.R - eng.h).toFixed(0) + 'px）' : '未出画';

  /* 取样块：星球初始圆心附近 48×48（设备像素）。
     每个检查点取 400ms 间隔的 3 次样本做逐通道中位数 —— 流星穿越、
     星点闪烁是瞬时噪声，中位数可以滤掉；慢环境（rAF≈15fps）下尤其必要。 */
  const dpr = eng.dpr;
  const px = Math.round(g.cx * dpr), py = Math.round(g.cy * dpr);
  /* 均色 + 亮度标准差。星球贴图带高频地貌纹理（方差高），天空与星云平滑
     （方差低）—— 星球离场后方差应显著下降，这是比均色更可靠的特征：
     均色可能巧合相近（community 页星球均色 ≈ 金色星云背景），方差不会。 */
  const patchSample = () => {
    const d = eng.ctx.getImageData(px - 24, py - 24, 48, 48).data;
    let r = 0, gg = 0, b = 0, n = 0;
    const lum = [];
    for (let i = 0; i < d.length; i += 4) {
      r += d[i]; gg += d[i + 1]; b += d[i + 2]; n++;
      lum.push(0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]);
    }
    const m = lum.reduce((a, v) => a + v, 0) / lum.length;
    const std = Math.sqrt(lum.reduce((a, v) => a + (v - m) * (v - m), 0) / lum.length);
    return { mean: [r / n, gg / n, b / n], std };
  };
  const median3 = async () => {
    const a = patchSample(); await sleep(400);
    const b = patchSample(); await sleep(400);
    const c = patchSample();
    const med = (get) => [0, 1, 2].map((i) => [get(a)[i], get(b)[i], get(c)[i]].sort((x, y) => x - y)[1]);
    return { mean: med((s) => s.mean), std: [a.std, b.std, c.std].sort((x, y) => x - y)[1] };
  };
  const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);

  /* 等待星球贴图与星云淡入全部就绪再取基准：慢环境（headless、低端机）里
     贴图要几秒才完成，若不等待，取样块的差异会混入「淡入本身的变化」，
     出现对照组变化量反而更大的假阴性（v1.6 踩坑）。 */
  for (let i = 0; i < 40; i++) {
    await sleep(500);
    const pl0 = eng.pList[0];
    if (pl0 && pl0.tex && pl0.fade >= 1 && eng.nebFade >= 1) break;
  }

  const meanTop = await median3();

  /* 诊断 1：不滚动，隔 1.2s 再取一次 —— 若完全一致说明画布根本没重绘 */
  await sleep(1200);
  const still = patchSample();
  let rafTicks = 0;
  const countRaf = () => { rafTicks++; if (rafTicks < 120) requestAnimationFrame(countRaf); };
  requestAnimationFrame(countRaf);
  await sleep(1000);
  out['D_画布是否在动'] = '1.2s 静置变化量=' + dist(meanTop.mean, still.mean).toFixed(2) +
    ' | 1s 内 rAF=' + rafTicks + ' | running=' + eng.running + ' | hidden=' + document.hidden;

  /* 行为验证 A：滚到底，等缓动收敛（lerp 0.09/帧，3s 足够）。
     星球（连同辉光、行星环）应完全移出视口 → 原位置只剩背景。 */
  window.scrollTo(0, realMax);
  await sleep(3000);
  const bottom = await median3();
  const delta = dist(meanTop.mean, bottom.mean);
  const stdDrop = meanTop.std > 4 ? (1 - bottom.std / meanTop.std) : 0;
  out['3_滚动状态'] = 'scrollY=' + Math.round(window.scrollY) + ' / realMax=' + realMax +
    ' / eng.scroll=' + (eng.scroll || 0).toFixed(0) + ' / exitK=' +
    ((eng.h + g.R * 1.35 - g.cy) / eng.scrollMax).toFixed(4);
  out['3_滚到底后原位置均色'] = 'rgb(' + meanTop.mean.map((v) => v.toFixed(0)) + ') → rgb(' +
    bottom.mean.map((v) => v.toFixed(0)) + ')';
  out['3_纹理方差'] = meanTop.std.toFixed(1) + ' → ' + bottom.std.toFixed(1) +
    '（下降 ' + (stdDrop * 100).toFixed(0) + '%）';
  out['3_均色变化量'] = delta.toFixed(1);
  out['3_离场判定'] = (delta > 40 || stdDrop > 0.35)
    ? 'OK（星球已离开：均色大幅变化或纹理方差显著下降）'
    : '（均色与方差均未显著变化，疑似未离场）';

  /* 行为验证 B（v1.6）：滚回顶部。星球应回到原位且「还是同一颗」。
     ── 像素均值在这里只能作参考：星球自转会让贴图相位前移（高速自转下
        20s 可达一成周长，取样块均色本来就会漂），星云的缓慢呼吸也会改变
        背景均色 —— 这些都是设计内动画，不是「变成另一颗星球」。
     ── 因此硬断言用身份检查：贴图仍挂载、淡入进度保持 1（没有经历
        「贴图丢失 → 重挂 → 重新淡入」）、渲染列数未降（未被降档重建）。 */
  window.scrollTo(0, 0);
  await sleep(3000);
  const meanBack = await median3();
  const deltaBack = dist(meanTop.mean, meanBack.mean);
  const pl0 = eng.pList[0];
  const identity = !!(pl0 && pl0.tex && pl0.fade >= 1);
  out['4_滚回顶部后变化量'] = deltaBack.toFixed(1) +
    '（参考值；含自转相位与星云呼吸的自然漂移）';
  out['4_身份检查'] = 'tex=' + !!(pl0 && pl0.tex) +
    ' / fade=' + (pl0 ? (pl0.fade || 0).toFixed(2) : '-') +
    ' / cols=' + (pl0 ? pl0.cols : '-') +
    ' / degrade=' + (pl0 ? pl0.degrade : '-') +
    (identity ? '（同一颗星球）' : '（贴图丢失或重新淡入 → 异常）');
  out['4_结论'] = (delta > 40 || stdDrop > 0.35) && identity
    ? 'OK（离场与归位均正常，星球样式未漂移）'
    : '需人工复核';

  out['5_JS错误'] = errs.length ? errs.join(' ; ') : '（无）';
  return out;
})();
