/* ==========================================================================
   贴图就绪探针（诊断用）
   验证浏览器（headless）环境下 PlanetTexJob 是否最终完成、耗时多少、
   期间有无未捕获的 JS 错误。
   用法：
     MOTION=1 PAGES=index WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-tex.js node tools/shoot.cjs
   ========================================================================== */
(async () => {
  const out = {};
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const errs = [];
  window.addEventListener('error', (e) => errs.push(e.message));
  window.addEventListener('unhandledrejection', (e) => errs.push('rejection:' + e.reason));

  const eng = window.HQSpace.init();
  out['0_引擎实例'] = eng ? 'OK' : '失败';
  if (!eng) return out;

  const t0 = performance.now();
  /* 环境诊断：rIC / setTimeout(0) / rAF 各自 1.5s 内能跑多少次 */
  let ricN = 0, stN = 0, rafN = 0;
  const loopRic = () => { ricN++; if (performance.now() - t0 < 1500) requestIdleCallback(loopRic, { timeout: 120 }); };
  requestIdleCallback(loopRic, { timeout: 120 });
  const loopSt = () => { stN++; if (performance.now() - t0 < 1500) setTimeout(loopSt, 0); };
  setTimeout(loopSt, 0);
  const loopRaf = () => { rafN++; if (performance.now() - t0 < 1500) requestAnimationFrame(loopRaf); };
  requestAnimationFrame(loopRaf);
  await sleep(1600);
  out['1a_rIC/1.5s'] = ricN;
  out['1b_setTimeout/1.5s'] = stN;
  out['1c_rAF/1.5s'] = rafN;

  const t1 = performance.now();
  let ready = false;
  let s0 = 0;
  for (let i = 0; i < 30; i++) {
    await sleep(500);
    const st = window.HQSpace._stats ? window.HQSpace._stats() : null;
    if (st && i % 4 === 0) out['进度@' + i + 'x0.5s'] = 'steps=' + st.steps + ' queue=' + st.queue;
    if (eng.pList[0] && eng.pList[0].tex) { ready = true; break; }
  }
  out['1_贴图就绪'] = ready ? 'OK（' + ((performance.now() - t0) / 1000).toFixed(1) + 's）' : '30s 内未就绪';
  out['1_队列'] = 'queue=' + (eng.pList[0] ? (eng.pList[0].tex ? '空' : '运行中') : '无星球');
  out['1_淡入进度'] = eng.pList[0] ? (eng.pList[0].fade || 0).toFixed(2) : '-';
  out['2_JS错误'] = errs.length ? errs.join(' ; ') : '（无）';
  return out;
})()
