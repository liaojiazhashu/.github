/* ==========================================================================
   昼夜主题探针（v2.2）
   --------------------------------------------------------------------------
   验证：
     · 页头存在切换按钮，图标随主题互斥显示
     · 点击后 html[data-theme] 在 night ↔ day 间切换
     · 刷新后主题持久化（localStorage）
     · 白天模式下夜间星图层隐藏、白天天空层显示（反向亦然）
   用法：
     PAGES=index WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-theme.js node tools/shoot.cjs
   ========================================================================== */
(async () => {
  const out = {};
  const q = (s) => document.querySelector(s);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const btn = q('[data-theme-toggle]');
  out['1_切换按钮存在'] = !!btn;
  if (!btn) return out;

  const theme = () => document.documentElement.getAttribute('data-theme');
  out['2_初始主题'] = theme();

  const vis = (sel) => {
    const el = q(sel);
    return el ? getComputedStyle(el).display !== 'none' : null;
  };
  const snapshot = () => ({
    theme: theme(),
    stars: vis('.bg-stars'),
    daysky: vis('.bg-daysky'),
    nightLogo: vis('.brand__img--night'),
    dayLogo: vis('.brand__img--day')
  });

  out['3_初始层状态'] = JSON.stringify(snapshot());

  btn.click();
  await sleep(250);
  out['4_点击后'] = JSON.stringify(snapshot());

  btn.click();
  await sleep(250);
  out['5_再点后'] = JSON.stringify(snapshot());

  /* 切到白天后刷新，验证持久化 */
  if (theme() !== 'day') { btn.click(); await sleep(150); }
  location.reload();
  return out;
})()
