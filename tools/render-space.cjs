/* 在 Node 端运行 js/space.js，输出各场景静态帧，用于视觉验收
   用法: node tools/render-space.cjs [scene|all] [width] [height]
   依赖: @napi-rs/canvas (仅用于开发校验，不进入站点)                        */
const fs = require('fs');
const path = require('path');
const { createCanvas } = require('@napi-rs/canvas');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, '.build', 'scenes');
const SRC = fs.readFileSync(path.join(ROOT, 'docs', 'archive', 'space.js'), 'utf8');

const argScene = process.argv[2] || 'all';
const W = parseInt(process.argv[3] || '1440', 10);
const H = parseInt(process.argv[4] || '810', 10);

function wrapCanvas(c) {
  c.style = {};
  c.className = '';
  c.setAttribute = function () { };
  return c;
}

function render(scene) {
  const rafQueue = [];
  const idleQueue = [];
  let now = 0;

  const bodyEl = {
    _scene: scene,
    firstChild: null,
    getAttribute: function (k) { return k === 'data-scene' ? this._scene : null; },
    insertBefore: function (el) { this.firstChild = el; this._canvas = el; },
    appendChild: function (el) { this.firstChild = el; this._canvas = el; }
  };

  const win = {
    innerWidth: W,
    innerHeight: H,
    devicePixelRatio: 2,
    screen: { width: W },
    pageYOffset: 0,
    matchMedia: () => ({ matches: false }),
    addEventListener: () => { },
    removeEventListener: () => { },
    requestAnimationFrame: (cb) => { rafQueue.push(cb); return rafQueue.length; },
    cancelAnimationFrame: () => { },
    /* 行星贴图是分片异步生成的：requestIdleCallback 只入队，由下面的
       drainIdle 抽干。给出 timeRemaining 是为了兼容按剩余预算分片的实现。 */
    requestIdleCallback: (cb) => { idleQueue.push(cb); return idleQueue.length; },
    setTimeout: (fn) => 0,
    clearTimeout: () => { },
    performance: { now: () => Number(process.hrtime.bigint()) / 1e6 },
    console
  };
  win.window = win;
  /* 打开高度场调试出口：makePlanetMaps 会额外产出灰度高度图与海陆二值图。
     贴图难看时，先分清是高度场没结构、还是着色把它抹平了。 */
  win.HQ_DEBUG = true;

  const doc = {
    readyState: 'complete',
    hidden: false,
    documentElement: { scrollTop: 0 },
    body: bodyEl,
    createElement: (tag) => (tag === 'canvas' ? wrapCanvas(createCanvas(8, 8)) : { style: {} }),
    addEventListener: () => { },
    removeEventListener: () => { }
  };

  /* 计时必须包住 new Function：脚本末尾会自动 boot() 并初始化引擎。 */
  const t0 = process.hrtime.bigint();
  new Function('window', 'document', 'console', SRC)(win, doc, console);
  const bootMs = Number(process.hrtime.bigint() - t0) / 1e6;

  /* 抽干空闲队列：行星贴图按时间预算分片生成，必须全部跑完才出图。
     浏览器里这一步是散在若干帧的空闲时间里做的，这里同步跑完以得到
     一个可比的「总成本」口径。 */
  let guard = 0;
  let texMs = 0;
  while (idleQueue.length && guard++ < 20000) {
    const cbs = idleQueue.splice(0, idleQueue.length);
    const s = process.hrtime.bigint();
    cbs.forEach((cb) => cb({ didTimeout: true, timeRemaining: () => 8 }));
    texMs += Number(process.hrtime.bigint() - s) / 1e6;
  }

  const engine = win.HQSpace.init(scene);
  if (!engine) throw new Error('引擎初始化失败: ' + scene);
  console.log(`    启动同步耗时 ${bootMs.toFixed(0)} ms（首次 renderStatic 口径）`
    + ` | 贴图总成本 ${texMs.toFixed(0)} ms / ${guard} 个分片`);

  /* DEGRADE=n：强制降档。用于逐层排查 —— 降档 2 会关掉云与极光，
     剩下的画面就只剩地表、灯光与光照，便于判断异常来自哪一层。 */
  if (process.env.DEGRADE) {
    engine.degrade = parseInt(process.env.DEGRADE, 10);
    engine.fpsLocked = true;
    engine.build();
  }

  // 推进若干帧，让星球自转 / 环带 / 流星进入自然状态
  const STEP = 1000 / 60;
  const FRAMES = parseInt(process.env.FRAMES || "360", 10);
  for (let i = 0; i < FRAMES; i++) {
    const cbs = rafQueue.splice(0, rafQueue.length);
    if (!cbs.length) break;
    now += STEP;
    cbs.forEach((cb) => cb(now));
  }

  const canvas = engine.canvas;
  const out = path.join(OUT, scene + '.png');

  /* 导出球面合成的中间画布：球面映射一旦出问题，需要能把「合成结果」和
     「最终叠加产物」分开看，否则无法判断异常来自映射还是来自后续的辉光。 */
  if (process.env.DUMP_PLANET && engine.pList[0]) {
    const pl = engine.pList[0];
    fs.writeFileSync(path.join(OUT, `_planet-${scene}.png`), pl.tmp.toBuffer('image/png'));
    console.log(`    planet tmp ${pl.tmp.width}x${pl.tmp.height} cols=${pl.cols}`);
  }

  // 关键：canvas 是透明的，直接看会被预览器合成到白底上，导致亮度/饱和度判断完全失真。
  // 这里按站点真实背景色 #0B1026 预合成，预览所见即浏览器所见。
  const composed = createCanvas(canvas.width, canvas.height);
  const cg = composed.getContext('2d');
  /* 底色取站点的页面背景中位色（--bg 径向渐变的中段停靠点），
     这样离线渲染的亮度分位才可以和实拍页面对得上。 */
  cg.fillStyle = process.env.BG_COLOR || '#1A3152';
  cg.fillRect(0, 0, canvas.width, canvas.height);
  cg.drawImage(canvas, 0, 0);

  fs.writeFileSync(out, composed.toBuffer('image/png'));
  const kb = (fs.statSync(out).size / 1024).toFixed(1);
  console.log(`  ${scene.padEnd(10)} ${canvas.width}x${canvas.height}  ${kb} KB`);

  if (process.env.DUMP_NEB) {
    const d = path.join(OUT, '_nebula-' + scene + '.png');
    fs.writeFileSync(d, engine.neb.getContext('2d').canvas.toBuffer('image/png'));
    const ng = engine.neb.getContext('2d');
    const px = ng.getImageData(0, 0, engine.neb.width, engine.neb.height).data;
    let mx = 0, sum = 0, n = 0;
    for (let i = 3; i < px.length; i += 4) { if (px[i] > mx) mx = px[i]; sum += px[i]; n++; }
    const cov = px.reduce((acc, v, i) => (i % 4 === 3 && v > 8 ? acc + 1 : acc), 0);
    console.log(`    nebula ${engine.neb.width}x${engine.neb.height}  maxAlpha=${(mx / 255).toFixed(3)}  meanAlpha=${(sum / n / 255).toFixed(4)}  coverage=${(cov / n * 100).toFixed(1)}%`);
  }
  if (process.env.DUMP_RING && engine.pList[0] && engine.pList[0].ringCv) {
    engine.pList[0].ringCv.forEach((c, i) => {
      fs.writeFileSync(path.join(OUT, `_ring${i}-${scene}.png`), c.toBuffer('image/png'));
    });
    console.log('    ring dump ok');
  }
  /* 逐层导出行星贴图：球面合成出问题时，必须能单独看清是地表错了、
     还是云层或灯光盖住了地表。 */
  if (process.env.DUMP_MAPS && engine.pList[0]) {
    const pl = engine.pList[0];
    [['surface', pl.tex], ['clouds', pl.cloudTex], ['lights', pl.lightTex], ['shade', pl.shade],
      ['height', pl.debugHeight], ['land', pl.debugLand]]
      .forEach(([n, c]) => {
        if (!c) return;
        fs.writeFileSync(path.join(OUT, `_map-${n}-${scene}.png`), c.toBuffer('image/png'));
        console.log(`    map ${n} ${c.width}x${c.height}`);
      });
  }
}

fs.mkdirSync(OUT, { recursive: true });
const scenes = argScene === 'all'
  ? ['home', 'about', 'community', 'tools', 'analysis', 'works', 'contact']
  : [argScene];
console.log(`渲染 ${scenes.length} 个场景 @ ${W}x${H}`);
scenes.forEach(render);
console.log('输出目录: ' + OUT);
