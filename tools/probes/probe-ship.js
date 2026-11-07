/*
 * 首屏探测器探针（v2.3.17）：原创深空探测船「长针」，验证六件事
 *   1. 六个结构件齐全 —— 探针头（六棱面 + 传感眼）/ 细颈 / 梭形主舱 /
 *      一对后掠稳定翼 / 尾部环形推进器 / 三具喷口
 *   2. 整机俯视变换存在（rotate + 纵向压扁），这是立体感的前提
 *   3. 环形推进器：外缘 / 内壁 / 分段包边 / 挖空带面齐全，环心核心舱在位，
 *      环上八枚节点 + 四根辐条 + 三枚信标绕环心自转（对称圆转动不可见，
 *      必须靠这些不对称物 —— 所以下面特别校验它们的数量与旋转）
 *   4. 尾焰：四层锥体齐全、焰根落在核心舱后缘（x=686）、外层比核心长
 *      （色阶「芯短边长」）、在错相吞吐
 *   5. 传感眼呼吸、流线向尾部流动、尾焰喷洒方向朝 +x
 *   6. prefers-reduced-motion 下全部停用（默认由 shoot.cjs 强制 reduce，
 *      加 MOTION=1 才还原）
 * 另外校验整台（含尾焰）落在首屏可视区内，不会越界压到标题。
 */
(async () => {
  const out = {};
  const q = (s) => document.querySelector(s);
  const qa = (s) => [...document.querySelectorAll(s)];
  const r1 = (n) => Math.round(n * 10) / 10;
  const op = (el) => (el ? +getComputedStyle(el).opacity : null);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  /* scaleX 脉冲唯一的直接证据 */
  const sx = (el) => {
    if (!el) return null;
    const m = new DOMMatrixReadOnly(getComputedStyle(el).transform);
    return Math.round(m.a * 1000) / 1000;
  };

  const ship = q('.hero-ship');
  if (!ship) return { '!': '未找到 .hero-ship' };

  const vb = ship.viewBox.baseVal;
  out['1_viewBox'] = `${vb.width}×${vb.height}`;

  const box = ship.getBoundingClientRect();
  const vw = innerWidth;
  const vh = innerHeight;
  out['2_渲染尺寸(px)'] = `${r1(box.width)}×${r1(box.height)}`;
  /* 人造物尺度：整台（含尾焰）不得超过视口宽的 9% ——
     v2.3.22 起 7vw = 101px @1440，日面直径 1040px，直径比 13:1。
     这条断言防的是「有人为了看得清又把 width 调回去」。 */
  out['2b_占视口宽'] = `${r1((box.width / vw) * 100)}%`;
  out['2c_渺小度达标(≤9%)'] = box.width / vw <= 0.09;
  out['3_落在首屏内'] = box.top >= 0 && box.left >= 0 && box.right <= vw && box.bottom <= vh;

  /* ---- 整机 ---- */
  const body = q('.hero-ship__body');
  out['4_俯视变换'] = body ? body.getAttribute('transform') : '缺失';

  /* ---- 结构件：靠几何特征点数，避免纯靠 class 名自证 ---- */
  const allPath = body ? [...body.querySelectorAll('path')] : [];
  const hasD = (frag) => allPath.some((p) => (p.getAttribute('d') || '').includes(frag));
  out['5_探针头(六棱面+探针)'] = hasD('L96 154 L188 164') && hasD('M40 200 H12');
  out['6_细颈'] = hasD('M188 164 L248 172');
  out['7_梭形主舱'] = hasD('M248 172 C 300 152');
  out['8_稳定翼(上下各一)'] = hasD('L542 112 L448 121') && hasD('L542 288 L448 279');
  out['9_尾管'] = hasD('M600 174 L660 182');

  /* ---- 环形推进器 ---- */
  const ring = body ? [...body.querySelectorAll('g')]
    .find((g) => (g.getAttribute('transform') || '') === 'translate(680 200)') : null;
  out['10_环推外缘+内壁+包边'] = ring ? ring.querySelectorAll('circle[r]').length : 0;
  /* 带面是 evenodd 双环挖空路径 */
  out['11_环带挖空带面'] = !!(ring && [...ring.querySelectorAll('path')]
    .some((p) => p.getAttribute('fill-rule') === 'evenodd'));
  const scan = q('.hero-ship__scan');
  /* 节点 r 在 v2.3.18 由 2.6 补偿为 3.9（描边同比例放大，见 tools/_scale-ship.cjs），
     所以按组取实际值，不写死 —— 否则下次改尺寸又会读成 0。 */
  const nodeR = scan ? (scan.querySelector('g[stroke="none"] circle') || {}).getAttribute?.('r') : null;
  out['12_旋转件:节点/辐条/信标'] = scan
    ? `${scan.querySelectorAll(`circle[r="${nodeR}"]`).length}/${(scan.querySelector('path').getAttribute('d') || '').split('M').length - 1}/${scan.querySelectorAll('.hero-ship__beacon circle').length}`
    : '缺失';
  out['13_环自转动画名'] = scan ? getComputedStyle(scan).animationName : '缺失';
  /* fill-box + center 的轴心 = 旋转组包围盒中心。八枚节点 (r=81) 与三枚信标
     (r=81, 120° 分布) 关于 y 轴严格对称、关于 x 轴差 0.9 单位（信标 r=4.8 比
     节点 r=3.9 大，v2.3.18 补偿后如此），因此轴心与环心的偏差 ≤0.5 单位 ——
     换算到 1440 视口不到 0.1px，不可见。这里直接把偏差算出来，免得看起来像
     "轴心没对准"。 */
  if (scan) {
    const bb = scan.getBBox();
    const dev = Math.hypot(bb.x + bb.width / 2, bb.y + bb.height / 2);
    out['14_环自转轴心偏差(单位)'] =
      `${(Math.round(dev * 100) / 100)} （${getComputedStyle(scan).transformBox} / ${getComputedStyle(scan).transformOrigin}）`;
  } else {
    out['14_环自转轴心偏差(单位)'] = '缺失';
  }
  /* 核心舱：r=20 且 r=13 的同心圈 */
  out['15_环心核心舱'] = !!(ring && [...ring.querySelectorAll('circle')]
    .some((c) => c.getAttribute('r') === '20'));

  /* ---- 尾焰 ---- */
  const jets = {
    halo: q('.hero-ship__jet--halo'),
    outer: q('.hero-ship__jet--outer'),
    mid: q('.hero-ship__jet--mid'),
    core: q('.hero-ship__jet--core')
  };
  const jetList = Object.values(jets);
  out['16_尾焰层数'] = `${jetList.filter(Boolean).length}/4`;
  /* 焰根必须落在核心舱后缘 x=686：偏了会与核心舱脱开一道缝 */
  out['17_焰根x(应为686)'] = jets.outer ? r1(jets.outer.getBBox().x) : '缺失';
  /* 外层比核心长：色阶「芯短边长」—— 反过来会读成两层叶子 */
  const len = (el) => (el ? r1(el.getBBox().width) : 0);
  out['18_层长 halo/outer/mid/core'] =
    `${len(jets.halo)}/${len(jets.outer)}/${len(jets.mid)}/${len(jets.core)}`;
  out['19_尾焰动画名'] = jetList
    .map((e) => (e ? getComputedStyle(e).animationName : '-'))
    .join(' / ');
  const flow = q('.hero-ship__jet-flow');
  out['20_流线动画名'] = flow ? getComputedStyle(flow).animationName : '缺失';
  const eye = q('.hero-ship__eye');
  out['21_传感眼动画名'] = eye ? getComputedStyle(eye).animationName : '缺失';

  /* ---- 动画采样 ---- */
  const samples = [];
  for (let i = 0; i < 4; i++) {
    samples.push({
      core: sx(jets.core),
      mid: sx(jets.mid),
      ring: scan ? getComputedStyle(scan).transform : null,
      dash: flow ? Math.round(parseFloat(getComputedStyle(flow).strokeDashoffset)) : null,
      eye: op(eye)
    });
    await sleep(320);
  }
  const changing = (arr) => Math.max(...arr) - Math.min(...arr) > 0.02;
  const seq = (arr) => arr.map((v) => (typeof v === 'number' ? Math.round(v * 100) / 100 : v)).join(' → ');

  out['22_核心层scaleX'] = changing(samples.map((s) => s.core)) ? seq(samples.map((s) => s.core)) : '无变化';
  out['23_中层scaleX'] = changing(samples.map((s) => s.mid)) ? seq(samples.map((s) => s.mid)) : '无变化';
  out['24_错相(核心≠中层存在帧)'] =
    samples.filter((s) => s.core !== null && s.mid !== null && Math.abs(s.core - s.mid) > 0.01).length + '/4';
  /* 环自转：四帧矩阵应各不相同 */
  const ringSet = new Set(samples.map((s) => s.ring));
  out['25_环在自转(4帧矩阵)'] = `${ringSet.size}/4 个不同`;
  out['26_流线dashoffset在流动'] = changing(samples.map((s) => s.dash))
    ? seq(samples.map((s) => s.dash))
    : '无变化';
  out['27_传感眼在呼吸'] = changing(samples.map((s) => s.eye))
    ? seq(samples.map((s) => s.eye))
    : '无变化';

  /* MOTION=1 时行为上面的"在动"；默认 reduce 时应全部静止 */
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  out['28_当前动效偏好'] = reduced ? 'reduce（应全停）' : 'no-preference（应在动）';
  const moving = changing(samples.map((s) => s.core)) && ringSet.size > 1;
  out['29_与偏好一致'] = reduced
    ? !moving && ringSet.size === 1 && getComputedStyle(ship).animationName === 'none'
    : moving && getComputedStyle(ship).animationName === 'ship-float';

  out['30_线色令牌'] = getComputedStyle(ship).color;
  return out;
})()
