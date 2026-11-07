/* ==========================================================================
   荒启科幻 · 太空场景引擎 (Space Scene Engine)
   --------------------------------------------------------------------------
   自研 Canvas 2D 程序化渲染：星云 / 三层视差星野 / 星球（地表 + 明暗 + 大气辉光）
   / 行星环 / 卫星 / 流星 / 星尘。零第三方依赖，零网络请求。

   调用方式：<body data-scene="home">  引擎自动初始化
   公开接口：window.HQSpace.init(sceneName)  /  window.HQSpace.destroy()
   ========================================================================== */
(function (global) {
  'use strict';

  var REDUCED = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var NEBULA_GAIN = 0.27;             // 星云整体强度收敛系数（网页背景以不干扰阅读为前提）

  /* ------------------------------------------------------------------ 调色板
     bands / storm 用于行星环与大气；terrain 用于程序化地表。
     terrain 各层按「高度由低到高」排列，着色时在这些颜色之间插值，
     因此档与档之间不会出现环形硬边。
     配色的一组硬规则：
       ① 「海」与「陆」的色相必须拉开（v1.6 起不再要求海是冷色、陆是暖色 ——
          那正是「每颗星球都像地球」的根源），只要求两族颜色在暗面上也能区分；
       ② shore（岸线）刻意提亮，用一圈浅色把大陆轮廓勾出来；
       ③ low 与 shelf 的明度不能接近 —— 那会让整颗星球糊成一片。
     配色的观感规则（v1.6 起）：
       ④ 整站从「蔚蓝海洋 + 翠绿大陆」的地球范式切换为五套异星地貌：
          violet  紫晶星 —— 靛紫「甲烷海」+ 品红「晶体壤」，青色荧光极冠；
          sulfur  硫磺星 —— 酸性青绿海 + 硫磺金大陆，荧光紫生物光点；
          ember   熔焰星 —— 熔岩海（暗红→炽橙→亮金）+ 玄武岩紫灰陆地；
          orchid  兰酒星 —— 玫红酒色海 + 薄荷绿大陆，荧光青生物光点；
          frost   霜晶星 —— 冰下暗海 + 青白霜原，明暗关系与地球相反。
       ⑤ 「城市灯光」语义改为「生物荧光」：light 各档不再是暖白，而是
          青紫绿等异色光点；「云」也不再是纯白，各档带本星球的环境色；
       ⑥ 暗面不压到纯黑，保留冷色反光 —— 星球是背景元素，全黑只会让页面压抑。 */
  var PALETTES = {
    violet: {
      base: '#6C4ED8',
      bands: ['#4A38A8', '#6C4ED8', '#9C86F0', '#4A38A8', '#7E64E4', '#33267C', '#C8B8FF'],
      storm: '#D8CCFF',
      atmo: '186,150,255',
      ring: [[196, 172, 255], [96, 74, 190], [236, 226, 255], [140, 116, 220]],
      terrain: {
        deep: [40, 36, 128], sea: [72, 70, 190], shelf: [126, 136, 230], shore: [216, 238, 255],
        low: [172, 76, 158], mid: [208, 120, 192], high: [238, 190, 228],
        ice: [176, 250, 238], cloud: [236, 222, 252], light: [128, 255, 206]
      }
    },
    sulfur: {
      base: '#2FA98C',
      bands: ['#1E7E6C', '#2FA98C', '#5ED0AC', '#1E7E6C', '#3CB894', '#14584E', '#A8F0D8'],
      storm: '#C2F4E2',
      atmo: '96,232,196',
      ring: [[150, 240, 214], [26, 116, 104], [228, 250, 242], [76, 176, 156]],
      terrain: {
        deep: [12, 92, 86], sea: [26, 142, 126], shelf: [92, 204, 176], shore: [226, 255, 244],
        low: [210, 166, 52], mid: [236, 198, 88], high: [248, 228, 150],
        ice: [242, 252, 250], cloud: [250, 238, 212], light: [214, 152, 255]
      }
    },
    ember: {
      base: '#E06A34',
      bands: ['#B84A24', '#E06A34', '#F49A62', '#B84A24', '#EA8248', '#8C3218', '#FFC89A'],
      storm: '#FFD8B4',
      atmo: '255,172,110',
      ring: [[255, 190, 140], [168, 70, 40], [255, 230, 206], [214, 124, 80]],
      terrain: {
        deep: [88, 18, 14], sea: [186, 54, 20], shelf: [244, 124, 38], shore: [255, 218, 120],
        low: [104, 88, 110], mid: [138, 120, 142], high: [180, 164, 182],
        ice: [238, 234, 240], cloud: [228, 206, 196], light: [255, 238, 168]
      }
    },
    orchid: {
      base: '#C2508E',
      bands: ['#963868', '#C2508E', '#E88EB8', '#963868', '#D470A2', '#6E244E', '#FFC4DC'],
      storm: '#FFD4E6',
      atmo: '255,150,200',
      ring: [[255, 190, 216], [150, 52, 102], [255, 228, 240], [212, 110, 160]],
      terrain: {
        deep: [84, 18, 74], sea: [148, 36, 108], shelf: [212, 92, 158], shore: [255, 226, 240],
        low: [62, 172, 140], mid: [104, 208, 166], high: [188, 240, 208],
        ice: [242, 250, 255], cloud: [255, 234, 244], light: [140, 255, 232]
      }
    },
    frost: {
      base: '#7CB8D8',
      bands: ['#5488AC', '#7CB8D8', '#ACDCEE', '#5488AC', '#8EC4E0', '#3A668A', '#D2F0FF'],
      storm: '#E4F6FF',
      atmo: '160,220,255',
      ring: [[196, 230, 250], [84, 132, 170], [236, 248, 255], [136, 176, 210]],
      terrain: {
        deep: [20, 56, 88], sea: [40, 94, 138], shelf: [98, 158, 198], shore: [232, 250, 255],
        low: [166, 204, 220], mid: [204, 230, 240], high: [238, 248, 252],
        ice: [154, 250, 232], cloud: [246, 252, 255], light: [126, 240, 255]
      }
    }
  };

  /* -------------------------------------------------------------- 场景定义表
     x / y  : 星球圆心在视口中的相对位置（0-1）
     size   : 直径相对于视口高度的倍数
     flat   : 行星环的扁平率（值越大越接近正视，环越接近圆形）
     light  : 光源方向（相对半径的偏移）
     nebula : 星云色斑。`a` 是强度权重，经 `a / 0.20`（上限 1.85）换算成实际
              增益。v1.4 把各场景统一上调，其中原本最暗的 works / community
              上调幅度最大 —— 它们此前整幅 p50 亮度只有 15 / 25，比参考图的
              页面底（`#13192F`，L≈25）还暗，与「明亮舒适」的目标相悖。
     ---------------------------------------------------------------------- */
  var SCENES = {
    home: {
      seed: 20261015, stars: 1.0, dust: true, meteors: true,
      nebula: [
        { x: 0.16, y: 0.20, r: 1.10, color: '#3FA8E8', a: 0.26 },
        { x: 0.74, y: 0.10, r: 0.80, color: '#2FC4A8', a: 0.18 },
        { x: 0.46, y: 0.92, r: 1.25, color: '#4A78D8', a: 0.22 },
        { x: 0.92, y: 0.66, r: 0.70, color: '#E8B268', a: 0.14 }
      ],
      planets: [{
        x: 0.755, y: 0.655, size: 1.05, palette: 'violet',
        spin: 0.0055, axis: -0.15, light: [-0.44, -0.46], glow: 0.66,
        ring: { flat: 0.19, tilt: -0.20, inner: 1.30, outer: 2.20, speed: 0.02 },
        moons: [{ orbit: 2.60, r: 0.030, speed: 0.055, angle: 1.1, flat: 0.34, color: '#C2C7EE' }]
      }]
    },
    about: {
      seed: 77120, stars: 0.9, dust: true, meteors: true,
      nebula: [
        { x: 0.22, y: 0.70, r: 1.05, color: '#3E9EE0', a: 0.30 },
        { x: 0.82, y: 0.26, r: 0.95, color: '#2FC4A8', a: 0.26 }
      ],
      planets: [
        {
          x: 0.20, y: 0.74, size: 0.86, palette: 'sulfur',
          spin: 0.0042, axis: 0.12, light: [-0.42, -0.50], glow: 0.50,
          ring: { flat: 0.24, tilt: 0.16, inner: 1.44, outer: 1.86, speed: -0.015 },
          moons: [{ orbit: 2.30, r: 0.026, speed: 0.07, angle: 2.4, flat: 0.40, color: '#CFD4F5' }]
        },
        {
          x: 0.38, y: 0.88, size: 0.30, palette: 'ember',
          spin: 0.0030, axis: 0.20, light: [-0.40, -0.48], glow: 0.34, moons: []
        }
      ]
    },
    community: {
      seed: 30551, stars: 0.9, dust: true, meteors: true,
      nebula: [
        { x: 0.86, y: 0.24, r: 1.15, color: '#E8A45E', a: 0.34 },
        { x: 0.24, y: 0.80, r: 1.20, color: '#4A78D8', a: 0.32 }
      ],
      planets: [{
        x: 0.83, y: 0.28, size: 0.64, palette: 'orchid',
        spin: 0.0062, axis: -0.22, light: [-0.46, -0.44], glow: 0.52,
        ring: { flat: 0.30, tilt: -0.30, inner: 1.52, outer: 2.05, speed: 0.024 },
        moons: [{ orbit: 2.45, r: 0.032, speed: 0.062, angle: 0.4, flat: 0.36, color: '#EBD3F2' }]
      }]
    },
    tools: {
      seed: 41200, stars: 0.85, dust: false, meteors: false,
      nebula: [
        { x: 0.80, y: 0.80, r: 1.10, color: '#22A8B8', a: 0.32 },
        { x: 0.30, y: 0.16, r: 0.90, color: '#3E9EE0', a: 0.30 }
      ],
      planets: [{
        x: 0.86, y: 0.80, size: 0.80, palette: 'ember',
        spin: 0.0048, axis: -0.08, light: [-0.40, -0.46], glow: 0.48,
        ring: { flat: 0.13, tilt: -0.10, inner: 1.26, outer: 2.45, speed: 0.03 },
        moons: []
      }]
    },
    analysis: {
      seed: 90077, stars: 1.0, dust: true, meteors: false,
      nebula: [
        { x: 0.20, y: 0.48, r: 1.10, color: '#4A9EE8', a: 0.30 },
        { x: 0.60, y: 0.16, r: 0.85, color: '#E8B268', a: 0.22 }
      ],
      planets: [{
        x: 0.18, y: 0.56, size: 0.72, palette: 'sulfur',
        spin: 0.0040, axis: 0.06, light: [-0.44, -0.44], glow: 0.50,
        ring: { flat: 0.62, tilt: 0.24, inner: 1.40, outer: 2.20, speed: 0.018 },
        moons: [
          { orbit: 2.55, r: 0.026, speed: 0.060, angle: 0.9, flat: 0.48, color: '#C8CFF5' },
          { orbit: 3.05, r: 0.019, speed: 0.040, angle: 4.0, flat: 0.48, color: '#A9B2DE' }
        ]
      }]
    },
    works: {
      seed: 61003, stars: 0.75, dust: false, meteors: false,
      nebula: [
        { x: 0.88, y: 0.10, r: 1.15, color: '#3E86C8', a: 0.38 },
        { x: 0.10, y: 0.86, r: 1.20, color: '#2F8FB8', a: 0.34 }
      ],
      planets: [{
        x: 0.95, y: 0.07, size: 0.46, palette: 'frost',
        spin: 0.0032, axis: -0.18, light: [-0.42, -0.48], glow: 0.34, moons: []
      }]
    },
    contact: {
      seed: 52880, stars: 0.95, dust: true, meteors: true,
      nebula: [
        { x: 0.50, y: 0.90, r: 1.25, color: '#3E9EE0', a: 0.34 },
        { x: 0.16, y: 0.14, r: 0.95, color: '#E8A45E', a: 0.26 }
      ],
      planets: [{
        x: 0.50, y: 0.94, size: 0.56, palette: 'violet',
        spin: 0.0038, axis: -0.10, light: [-0.44, -0.50], glow: 0.40,
        ring: { flat: 0.20, tilt: 0.10, inner: 1.45, outer: 1.80, speed: 0.014 },
        moons: []
      }]
    }
  };

  /* ------------------------------------------------------------------ 工具函数 */
  function mulberry32(a) {
    return function () {
      a |= 0; a = a + 0x6D2B79F5 | 0;
      var t = Math.imul(a ^ a >>> 15, 1 | a);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }
  function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function smoothstep(e0, e1, x) { var t = clamp((x - e0) / (e1 - e0), 0, 1); return t * t * (3 - 2 * t); }
  function makeCanvas(w, h) {
    var c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(w));
    c.height = Math.max(1, Math.round(h));
    return c;
  }

  /* ------------------------------------------------------------- 程序化噪声
     值噪声 + fBm。为什么用 3D：行星表面最终要贴到球面上，若按「经度 / 纬度」
     直接采样 2D 噪声，两极会因为经线汇聚而被拉伸成扇形条带。改为先把经纬度
     转成球面单位向量再采样 3D 噪声，纹理在整个球面上才各向均匀。

     性能取舍：这里用置换表（PERM）查值，而不是每次调用都做整数哈希。单次
     noise3 从「8 次哈希 × 3 次乘法」降到「14 次数组查表」，在几十万像素的
     纹理生成里大约是 3 倍的差距，而视觉结果没有明显区别。
     代价是全局只有一张置换表，所以「不同的随机场」改由坐标偏移表达：
     调用方把 seed 换算成偏移量（seedOffset）加到采样坐标上，
     效果上等价于换了另一张噪声场，却不需要重建置换表。                */
  var PERM = (function () {
    var p = new Uint8Array(512);
    var v = new Uint8Array(256);
    var rnd = mulberry32(0x5BF03635);
    var i, j, t;
    for (i = 0; i < 256; i++) v[i] = i;
    for (i = 255; i > 0; i--) {
      j = Math.floor(rnd() * (i + 1));
      t = v[i]; v[i] = v[j]; v[j] = t;
    }
    for (i = 0; i < 512; i++) p[i] = v[i & 255];
    return p;
  })();

  /* seed -> 三维坐标偏移。复用同一个数组，避免在像素循环里分配对象。 */
  var _SO = [0, 0, 0];
  function seedOffset(seed) {
    var s = (seed % 100003) * 0.01731;
    _SO[0] = s; _SO[1] = s * 1.7321; _SO[2] = s * 2.4142;
    return _SO;
  }

  /* 梯度方向的点积。取 12 个方向（改进版 Perlin 的标准写法），
     用位运算代替 switch，省掉百万次调用里的分支预测失败。 */
  function grad3(h, x, y, z) {
    h &= 15;
    var u = h < 8 ? x : y;
    var v = h < 4 ? y : (h === 12 || h === 14 ? x : z);
    return ((h & 1) === 0 ? u : -u) + ((h & 2) === 0 ? v : -v);
  }

  /* 3D 梯度噪声（Perlin）。
     ── 为什么从值噪声换成梯度噪声：值噪声在每个格点处就是「一颗圆丘」，
        球面与圆丘相交得到的等值线是圆弧。单位球上频率 f 的噪声场大约只有
        4πf² 个格点参与，f=1.5 时不过三十来个 —— 于是整颗球面就是三十几块
        圆斑，斑与斑的边界正好是一串同心圆弧，远看就是「一圈圈弧形色带」。
        这正是「图案过于抽象」的根源，加阶数也救不了：最低那一阶的圆弧始终
        占最大权重，会被原样留在结果里。
        梯度噪声在每个格点处取零值、由梯度决定走势，天然没有「圆斑 + 圆弧」
        结构，同样的频率下直接给出不规则的大陆轮廓。
     ── 输出归一到 0..1：12 个梯度方向下噪声的理论幅值约 ±0.87，
        乘 0.5 加 0.5 后落在 [0.06, 0.94]，fBm 叠加后仍留有余量。 */
  function noise3(x, y, z) {
    var xi = Math.floor(x), yi = Math.floor(y), zi = Math.floor(z);
    var xf = x - xi, yf = y - yi, zf = z - zi;
    /* 五次平滑：一阶与二阶导数在格点处连续，因此不会留下方格状硬边 */
    var u = xf * xf * xf * (xf * (xf * 6 - 15) + 10);
    var v = yf * yf * yf * (yf * (yf * 6 - 15) + 10);
    var w = zf * zf * zf * (zf * (zf * 6 - 15) + 10);
    /* 索引范围：X/Y/Z 取低 8 位；PERM 长度为 512，第一级索引最大
       PERM[X]+Y = 255+255 = 510，第二级 PERM[A]+Z 同样最大 510，
       因此 PERM[AA+1] 最坏到 511，全部落在表内。
       ！这里绝不能再对中间结果做 & 255：那会把 256..510 折回 0..254，
       使不同的格点共用同一个随机值，噪声随之出现肉眼可见的环状伪影。 */
    var X = xi & 255, Y = yi & 255, Z = zi & 255;
    var A = PERM[X] + Y, B = PERM[X + 1] + Y;
    var AA = PERM[A] + Z, AB = PERM[A + 1] + Z;
    var BA = PERM[B] + Z, BB = PERM[B + 1] + Z;
    var xf1 = xf - 1, yf1 = yf - 1, zf1 = zf - 1;
    var g000 = grad3(PERM[AA], xf, yf, zf);
    var g100 = grad3(PERM[BA], xf1, yf, zf);
    var g010 = grad3(PERM[AB], xf, yf1, zf);
    var g110 = grad3(PERM[BB], xf1, yf1, zf);
    var g001 = grad3(PERM[AA + 1], xf, yf, zf1);
    var g101 = grad3(PERM[BA + 1], xf1, yf, zf1);
    var g011 = grad3(PERM[AB + 1], xf, yf1, zf1);
    var g111 = grad3(PERM[BB + 1], xf1, yf1, zf1);
    var x00 = g000 + (g100 - g000) * u, x10 = g010 + (g110 - g010) * u;
    var x01 = g001 + (g101 - g001) * u, x11 = g011 + (g111 - g011) * u;
    var y0 = x00 + (x10 - x00) * v, y1 = x01 + (x11 - x01) * v;
    return (y0 + (y1 - y0) * w) * 0.5 + 0.5;
  }

  function fbm3(x, y, z, oct, lac, gain) {
    lac = lac || 2.03; gain = gain || 0.5;
    var amp = 1, freq = 1, sum = 0, norm = 0;
    for (var i = 0; i < oct; i++) {
      sum += noise3(x * freq + i * 19.3, y * freq + i * 27.7, z * freq + i * 33.1) * amp;
      norm += amp; amp *= gain; freq *= lac;
    }
    return sum / norm;
  }

  /* 山脊噪声（ridged）。把每一阶噪声先折成 1-|2n-1| 再平方，等值线由圆滑的
     丘状变成带尖脊的折线，叠加后就是连绵的山脉走向。
     为什么普通 fBm 做不出山脉：它的每一阶都是平滑的凸包，多加阶数只会让
     凸包变小变多，结果是一地「小圆丘」，无论怎么调都读不出山脊。 */
  function ridgedFbm3(x, y, z, oct, lac, gain) {
    lac = lac || 2.03; gain = gain || 0.5;
    var amp = 1, freq = 1, sum = 0, norm = 0, n;
    for (var i = 0; i < oct; i++) {
      n = noise3(x * freq + i * 19.3, y * freq + i * 27.7, z * freq + i * 33.1);
      n = 1 - (n * 2 - 1 < 0 ? 1 - n * 2 : n * 2 - 1);
      sum += n * n * amp;
      norm += amp; amp *= gain; freq *= lac;
    }
    return sum / norm;
  }

  /* --------------------------------------------------------------- 星云生成器
     不用单枚大半径渐变（低透明度下会产生肉眼可见的色带断层），
     改为由大量小团簇叠加构成，既有结构感，也天然没有量化色阶。      */
  function hexToHueShift(hex, deg) {
    var v = hex.replace('#', '');
    if (v.length === 3) v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2];
    var n = parseInt(v, 16);
    var r = (n >> 16 & 255) / 255, g = (n >> 8 & 255) / 255, b = (n & 255) / 255;
    var mx = Math.max(r, g, b), mn = Math.min(r, g, b);
    var l = (mx + mn) / 2, h = 0, s = 0;
    if (mx !== mn) {
      var d = mx - mn;
      s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
      if (mx === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6;
      else if (mx === g) h = ((b - r) / d + 2) / 6;
      else h = ((r - g) / d + 4) / 6;
    }
    h = (h + deg / 360) % 1; if (h < 0) h += 1;
    function hue2rgb(p, q, t) {
      if (t < 0) t += 1; if (t > 1) t -= 1;
      if (t < 1 / 6) return p + (q - p) * 6 * t;
      if (t < 1 / 2) return q;
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
      return p;
    }
    var rr, gg, bb;
    if (s === 0) { rr = gg = bb = l; }
    else {
      var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
      var p = 2 * l - q;
      rr = hue2rgb(p, q, h + 1 / 3); gg = hue2rgb(p, q, h); bb = hue2rgb(p, q, h - 1 / 3);
    }
    return [Math.round(rr * 255), Math.round(gg * 255), Math.round(bb * 255)];
  }

  /* 降采样往返模糊：工具函数，保留供后续调优阶段使用 */
  function softBlur(cv, passes) {
    var cur = cv;
    for (var i = 0; i < passes; i++) {
      var hw = Math.max(2, Math.round(cur.width / 2));
      var hh = Math.max(2, Math.round(cur.height / 2));
      var small = makeCanvas(hw, hh);
      var sg = small.getContext('2d');
      sg.imageSmoothingEnabled = true;
      if ('imageSmoothingQuality' in sg) sg.imageSmoothingQuality = 'high';
      sg.clearRect(0, 0, hw, hh);
      sg.drawImage(cur, 0, 0, hw, hh);

      var back = makeCanvas(cur.width, cur.height);
      var bg = back.getContext('2d');
      bg.imageSmoothingEnabled = true;
      if ('imageSmoothingQuality' in bg) bg.imageSmoothingQuality = 'high';
      bg.clearRect(0, 0, cur.width, cur.height);
      bg.drawImage(small, 0, 0, cur.width, cur.height);
      cur = back;
    }
    return cur;
  }

  /* 星云尺寸档缓存：同上一次尺寸接近时直接复用，避免窗口拖拽时反复重算 */
  var NEB_CACHE = { key: '', cv: null };

  /* 星云：逐像素域扭曲（domain warping）
     ── 上一版用「同心环累积」的几何画法，虽然绕开了 8bit alpha 的精度问题，
        但形状本质上是规整的椭圆光斑，缺少星云该有的丝状纤维与暗尘埃带，
        在大屏上会被读成「一团渐变」而不是星云。
     ── 改为逐像素计算：星云按约 1/12 面积预渲染，单像素成本完全可接受，
        换来的是可精确控制的密度场。
     ── 密度用两级域扭曲生成：先取两层低频噪声得到偏移向量，再用它去扭曲主
        噪声。这是产生丝状 / 絮状结构最直接的方法；直接采样 fBm 只会得到
        均匀斑块。
     ── 输出为预乘结果：alpha 固定 255，把最终亮度直接写进 RGB。绘制端用的是
        'lighter' 叠加，黑色等于不叠加，因此不存在边缘硬边，也就不再需要
        靠多个半透明层去累积亮度。
     ── **输出后必须再过一遍模糊**（阶段 2）。这是 v1.4 补上的一步，也是
        「背景脏兮兮」的根治手段：噪声密度场无论怎么调频率与阶数，本质都是
        「一堆斑块」，而贴图只有 1/3 视口分辨率，放大回去时斑块边缘依旧是
        硬边，远看就是一片斑驳的污渍。把阶数降下来只能让斑块变大，不能让
        边缘变软 —— 唯一的办法是把高频结构真的抹掉。
     ── 与行星贴图一样做成按时间预算分片的任务：它的成本随视口面积线性增长，
        在 2560×1440 上实测同步跑要 700ms 以上，放在启动路径上就是一次明显
        的白屏。分片粒度取「行」，阶段 0 逐 blob 累积密度场，阶段 1 输出像素，
        阶段 2 横竖两趟一维模糊。 */
  function NebulaJob(cfg, w, h, scale, sceneName, onDone) {
    var nw = Math.max(8, Math.round((w + 96) * scale));
    var nh = Math.max(8, Math.round((h + 96) * scale));
    this.nw = nw; this.nh = nh;
    this.key = sceneName + '|' + Math.round(nw / 24) + 'x' + Math.round(nh / 24);
    this.blobs = cfg.nebula || [];
    this.seed = cfg.seed || 1;
    this.onDone = onDone;
    /* 噪声采样步长。用「整幅宽度约 24 个噪声格」来表达，而不是写死一个
       像素步长 —— 否则换个视口尺寸，星云的纹理尺度就会跟着变。 */
    this.FU = 24 / nw;
    this.acc = new Float32Array(nw * nh * 3);
    this.phase = 0;          // 0 累积密度 / 1 输出像素
    this.bi = 0;             // 当前 blob 下标
    this.b = -1;             // 已经展开常量的 blob 下标（-1 表示尚未展开）
    this.y = 0;
    this.cv = null;
  }

  /* 进入一个 blob 时把它的常量算好。这些量在行循环内是常量，
     放在这里可以让内层循环少掉十几次数组访问与三角函数。 */
  NebulaJob.prototype.beginBlob = function () {
    var b = this.bi, nb = this.blobs[b];
    var nw = this.nw, nh = this.nh;
    var seed = this.seed + 991 + b * 733;
    var cx = nb.x * nw, cy = nb.y * nh;
    var R = Math.max(12, nb.r * Math.max(nw, nh) * 0.74);
    var so = seedOffset(seed);

    this.b = b;
    this.cx = cx; this.cy = cy; this.R = R; this.invR = 1 / R;
    /* 色斑强度 → 实际增益。
       v1.4 把除数从 0.26 收到 0.20、上限从 1.30 放到 1.85 —— 相当于把每个
       场景的星云整体抬升约三成。原因见 16.1：实拍采样发现页面背景的有效亮度
       仍与参考图持平，「提亮」被暗角遮罩吃掉了；与其继续减遮罩（会伤层次），
       不如把光源本身调亮。 */
    this.gain = clamp(nb.a / 0.20, 0.40, 1.85);
    this.cCool = hexToHueShift(nb.color, -22);        // 外缘薄雾：偏冷
    this.cMid = hexToHueShift(nb.color, 2);           // 主体
    this.cWarm = hexToHueShift(nb.color, 26);         // 内核：偏亮
    this.nox = so[0]; this.noy = so[1]; this.noz = so[2];
    this.x0 = Math.max(0, Math.floor(cx - R * 1.30));
    this.x1 = Math.min(nw, Math.ceil(cx + R * 1.30));
    this.y0 = Math.max(0, Math.floor(cy - R * 1.30));
    this.y1 = Math.min(nh, Math.ceil(cy + R * 1.30));
    this.y = this.y0;
  };

  NebulaJob.prototype.row = function (j) {
    var nw = this.nw, FU = this.FU;
    var acc = this.acc;
    var invR = this.invR, cx = this.cx, cy = this.cy;
    var nox = this.nox, noy = this.noy, noz = this.noz;
    var b = this.b, gain = this.gain;
    var cCool = this.cCool, cMid = this.cMid, cWarm = this.cWarm;
    var ny = (j - cy) * invR;
    var vy = j * FU;

    for (var i = this.x0; i < this.x1; i++) {
      var nx = (i - cx) * invR;
      var rr = Math.sqrt(nx * nx + ny * ny * 1.14);
      if (rr > 1.46) continue;
      var vx = i * FU;

      /* 第一级扭曲：用一层低频噪声算出偏移向量。
         参数在 v1.4 连收三档 —— 幅度 1.9 → 1.15 → 0.85 → 0.70，频率
         0.52 → 0.42 → 0.38，阶数 3 → 2。幅度大、频率高时会把密度场揉成
         一团细密的丝状结构，放大到全屏就是一片斑驳的「污渍感」；阶数多时
         扭曲本身又会在细尺度上制造褶皱。星云需要的是一团柔和的辉光，
         不是一朵可辨的云，所以这里只保留「大尺度的舒展」。 */
      var q1 = fbm3(vx * 0.38 + nox, vy * 0.38 + noy, 4.7 + b * 2.3 + noz, 2, 2.07, 0.50);
      var q2 = fbm3(vx * 0.38 + 17.3 + nox, vy * 0.38 + 9.1 + noy, 11.7 + b * 3.1 + noz, 2, 2.07, 0.50);
      var wx = vx + (q1 - 0.5) * 0.70;
      var wy = vy + (q2 - 0.5) * 0.70;

      /* 第二级：在扭曲后的坐标上取主密度场。
         频率一路下调（1.05 → 0.78 → 0.62 → 0.30）、阶数降到 2、增益压到
         0.42，让能量集中在最大的那一档结构上 —— 频率 0.30 对应贴图上约
         130px 一个周期，放大到 1440 视口就是 400px 量级的云团；
         剩下的细节交给阶段 2 的模糊统一抹平，结果是「大团的雾」而不是
         「密集的斑」。 */
      var d = fbm3(wx * 0.30 + nox, wy * 0.30 + noy, 23.9 + b * 7.7 + noz, 2, 2.05, 0.42);

      /* 径向衰减：中心实、边缘薄，避免出现规整的圆形边界。
         指数从平方放宽到 1.55 次方 —— 平方衰减会把中心压出一个硬核，
         边缘一圈几乎垂直地掉到零，那圈陡沿正是「斑」的轮廓来源。 */
      var fall = 1 - rr / 1.46;
      if (fall <= 0) continue;
      fall = Math.pow(fall, 1.55) * (0.72 + 0.28 * fall);

      /* 密度映射：v1.4 起的第三个版本。
         第一版是「阈值截断 + 线性拉伸」——阈值以下直接归零，于是整片星云被
         切成一个个互不相连的亮斑，斑与斑之间的暗缝正是「脏」的观感来源；
         第二版只把阈值往下挪了挪，缝还在。
         现在改成全程平滑、并抬高下限：亮度在 0.10–1.0 之间起伏，不再有
         归零的暗缝，但下限刻意压得很低 —— 抬得太高会把整片星云填成一坨
         平光的色块，反而失去层次、显得糊。配合径向衰减与阶段 2 的模糊，
         出来的是一层有呼吸感的雾。 */
      var t = (d - 0.32) / 0.64;
      t = t < 0 ? 0 : (t > 1 ? 1 : t);
      var dens = (0.12 + 0.88 * t * t * (3 - 2 * t)) * fall * gain;
      if (dens <= 0.0035) continue;

      /* 颜色分层：低密度偏冷、高密度偏亮，模拟内部被星光照亮的效果。
         系数从 2.4 收到 1.5 —— 密度下限抬高之后，原系数会让几乎所有像素
         都顶到最亮的那一档，分层反而消失、整片星云糊成一块死板的紫。 */
      var ct = clamp(dens * 1.5, 0, 1);
      var t2 = ct * ct * 0.6;
      var o = (j * nw + i) * 3;
      acc[o] += (cCool[0] + (cMid[0] - cCool[0]) * ct + (cWarm[0] - cMid[0]) * t2) * dens;
      acc[o + 1] += (cCool[1] + (cMid[1] - cCool[1]) * ct + (cWarm[1] - cMid[1]) * t2) * dens;
      acc[o + 2] += (cCool[2] + (cMid[2] - cCool[2]) * ct + (cWarm[2] - cMid[2]) * t2) * dens;
    }
  };

  NebulaJob.prototype.beginOutput = function () {
    var nw = this.nw, nh = this.nh;
    this.cv = makeCanvas(nw, nh);
    this.g = this.cv.getContext('2d');
    this.img = this.g.createImageData(nw, nh);
    this.data = this.img.data;
    this.dj = mulberry32(this.seed + 4242);
    this.phase = 1;
    this.y = 0;
  };

  NebulaJob.prototype.outRow = function (j) {
    var nw = this.nw, acc = this.acc, data = this.data, dj = this.dj;
    var base = j * nw;
    for (var i = 0; i < nw; i++) {
      var k = (base + i) * 3;
      var r = acc[k], gg = acc[k + 1], bb = acc[k + 2];
      var p = (base + i) * 4;
      /* 深色区域的每通道取值常常只有个位数，8bit 量化会出现肉眼可见的色带，
         加 ±0.7 的随机抖动可以把断层打散成噪点。全黑处保持纯黑，避免
         整片背景出现浮动噪点。 */
      if (r + gg + bb < 1.6) { data[p + 3] = 255; continue; }
      /* 抖动幅度必须小于一个像素的量化步长，否则放大到全屏时会变成
         一格一格的方块，而不是细腻的颗粒。 */
      var dth = (dj() - 0.5) * 0.9;
      data[p] = clamp(Math.round((r + dth) * NEBULA_GAIN * 3.2), 0, 255);
      data[p + 1] = clamp(Math.round((gg + dth) * NEBULA_GAIN * 3.2), 0, 255);
      data[p + 2] = clamp(Math.round((bb + dth) * NEBULA_GAIN * 3.2), 0, 255);
      data[p + 3] = 255;
    }
  };

  /* ------------------------------------------------ 阶段 2：一维滑动窗口模糊
     用两趟一维滑动窗口（横 + 竖）近似高斯。每像素 O(1)、与半径无关，代价
     远小于一次真正的卷积；半径 7 时对 950×550 的贴图实测约 20ms，仍被切进
     8ms 的分片预算里，不会挤进任何一帧。

     采样越界一律取边缘像素（clamp），而不是补零 —— 补零会让画布四边出现
     一圈明显的暗边，星云是铺满视口的，那圈暗边会直接暴露成一条黑框。 */
  var NEB_BLUR_R = 13;

  NebulaJob.prototype.beginBlur = function () {
    var nw = this.nw, nh = this.nh;
    this.g.putImageData(this.img, 0, 0);
    this.bSrc = this.data;                                  // 横趟的输入
    /* 中间缓冲用 Float32 而不是 Uint8：两趟之间若再做一次 0-255 量化，
       暗部的舍入误差会被第二趟原样带出来，在深色背景上形成细网状的色带。 */
    this.bTmp = new Float32Array(this.data.length);         // 横趟的输出 / 竖趟的输入
    this.acc = null;                                        // 密度场已经用不到了
    this.phase = 2;
    this.bPass = 0;
    this.y = 0;
  };

  /* 横趟：从 bSrc 读、写进 bTmp。 */
  NebulaJob.prototype.blurRowX = function (j) {
    var nw = this.nw, R = NEB_BLUR_R;
    var s = this.bSrc, t = this.bTmp;
    var base = j * nw * 4;
    var inv = 1 / (2 * R + 1);
    var r = 0, g = 0, b = 0, k, i, o;
    for (k = -R; k <= R; k++) {
      i = k < 0 ? 0 : (k >= nw ? nw - 1 : k);
      o = base + i * 4;
      r += s[o]; g += s[o + 1]; b += s[o + 2];
    }
    for (i = 0; i < nw; i++) {
      o = base + i * 4;
      t[o] = r * inv; t[o + 1] = g * inv; t[o + 2] = b * inv; t[o + 3] = 255;
      var io = i - R; io = io < 0 ? 0 : io;
      var ii = i + R + 1; ii = ii >= nw ? nw - 1 : ii;
      var oo = base + io * 4, oi = base + ii * 4;
      r += s[oi] - s[oo];
      g += s[oi + 1] - s[oo + 1];
      b += s[oi + 2] - s[oo + 2];
    }
  };

  /* 竖趟：从 bTmp 读、写回 bSrc（即 ImageData 本体），省掉一次数组分配。
     这一趟的滑动窗口在「行」方向，而分片又是按行推进的 —— 行主序下没法像
     横趟那样在列内滑动。解决办法是把窗口和存在一个持久化的列累加数组里：
     处理第 j 行时它正好是 [j-R, j+R] 的列和，写完再滑动一行。这样每行仍是
     O(宽度)，整个竖趟与前一趟同价。 */
  NebulaJob.prototype.beginBlurY = function () {
    var nw = this.nw, nh = this.nh, R = NEB_BLUR_R;
    var s = this.bTmp;
    var sum = new Float32Array(nw * 3);
    for (var k = -R; k <= R; k++) {
      var r = k < 0 ? 0 : (k >= nh ? nh - 1 : k);
      var o = r * nw * 4;
      for (var i = 0; i < nw; i++) {
        var p = i * 3, q = o + i * 4;
        sum[p] += s[q]; sum[p + 1] += s[q + 1]; sum[p + 2] += s[q + 2];
      }
    }
    this.colSum = sum;
    this.bPass = 1;
    this.y = 0;
  };

  NebulaJob.prototype.blurRowY = function (j) {
    var nw = this.nw, nh = this.nh, R = NEB_BLUR_R;
    var s = this.bTmp, t = this.bSrc, sum = this.colSum;
    var stride = nw * 4;
    var base = j * stride;
    var inv = 1 / (2 * R + 1);
    var i, p, o, q;
    for (i = 0; i < nw; i++) {
      p = i * 3; o = base + i * 4;
      t[o] = sum[p] * inv; t[o + 1] = sum[p + 1] * inv; t[o + 2] = sum[p + 2] * inv;
      t[o + 3] = 255;
    }
    /* 滑到下一行的窗口：加 clamp(j+R+1)、减 clamp(j-R)。
       两端都用 clamp，滑动恒等式 Σ_{k=-R..R} s[clamp(j+k)] 才成立；
       若改成「越界补零」会在画布上下各留一圈暗边。 */
    var ri = j + R + 1; ri = ri >= nh ? nh - 1 : ri;
    var ro = j - R; ro = ro < 0 ? 0 : ro;
    var oi = ri * stride, oo = ro * stride;
    for (i = 0; i < nw; i++) {
      p = i * 3; q = i * 4;
      sum[p] += s[oi + q] - s[oo + q];
      sum[p + 1] += s[oi + q + 1] - s[oo + q + 1];
      sum[p + 2] += s[oi + q + 2] - s[oo + q + 2];
    }
  };

  NebulaJob.prototype.step = function () {
    var t0 = nowMs();

    while (this.phase === 0) {
      if (this.bi >= this.blobs.length) { this.beginOutput(); break; }
      if (this.b !== this.bi) this.beginBlob();
      while (this.y < this.y1) {
        this.row(this.y++);
        if (nowMs() - t0 >= TEX_CHUNK_MS) return false;
      }
      this.bi++;
      if (nowMs() - t0 >= TEX_CHUNK_MS) return false;
    }

    while (this.phase === 1 && this.y < this.nh) {
      this.outRow(this.y++);
      if (nowMs() - t0 >= TEX_CHUNK_MS) return false;
    }
    if (this.phase === 1) this.beginBlur();

    if (this.phase === 2 && this.bPass === 0) {
      while (this.y < this.nh) {
        this.blurRowX(this.y++);
        if (nowMs() - t0 >= TEX_CHUNK_MS) return false;
      }
      this.beginBlurY();
    }
    if (this.phase === 2) {
      while (this.y < this.nh) {
        this.blurRowY(this.y++);
        if (nowMs() - t0 >= TEX_CHUNK_MS) return false;
      }
    }

    this.g.putImageData(this.img, 0, 0);
    this.bSrc = null; this.bTmp = null; this.colSum = null;
    this.img = null; this.data = null; this.acc = null;
    this.maps = this.cv;
    return true;
  };

  /* ------------------------------------------- 程序化行星表面（等距圆柱投影）
     一次遍历产出三张图：
       1) surface —— 深海 / 浅海 / 大陆架 / 海岸 / 低地 / 高地 / 雪线 / 极冠
       2) clouds  —— 独立云层。绘制时用与地表不同的角速度自转，产生相对运动
       3) lights  —— 夜面城市灯光
     纹理宽高比固定 2:1（经度 360° × 纬度 180°），这是球面采样所需的标准比例；
     若用 1:1，两极会被纵向拉伸成条状。
     上一版只有「纬向条带 + 拉长风暴斑」，虽是程序化生成，但没有任何可辨识的
     地貌，在实机上会被读成一团抽象斜纹 —— 这正是本次重做的直接原因。

     分辨率选择的依据：球面采样是把纹理的「半个周长」铺到球的直径上，
     也就是屏幕上的球半径约等于纹理的半宽。纹理半宽若小于球半径，纹理的
     单个像素就会被放大到数个屏幕像素，双线性插值的结果是一颗颗方块。
     v1.5 起按屏幕自适应：1536 宽（半宽 768）覆盖绝大多数桌面场景，
     ≥1600px 宽的屏幕用 2048（半宽 1024，dpr 2 下依旧不会出现方块），
     小屏退回 1024 —— 小屏的球半径小，更高分辨率只会浪费空闲时间。
     Node 离线渲染没有 screen 对象，落在默认档 1536。                   */
  var TEX_W = 1536;
  if (global.screen && global.screen.width) {
    if (global.screen.width < 760) TEX_W = 1024;
    else if (global.screen.width >= 1600) TEX_W = 2048;
  }
  var TEX_H = TEX_W >> 1;

  /* 复用的三元数组：像素循环里若每次都 new 一个数组，几十万次分配会把
     GC 压力直接反映成掉帧。这里始终写同一个数组，调用方用完即取。       */
  var _PT = [0, 0, 0];
  function ptSet(r, g, b) { _PT[0] = r; _PT[1] = g; _PT[2] = b; return _PT; }
  function ptMix(a, b, t) {
    _PT[0] = a[0] + (b[0] - a[0]) * t;
    _PT[1] = a[1] + (b[1] - a[1]) * t;
    _PT[2] = a[2] + (b[2] - a[2]) * t;
    return _PT;
  }
  function clampS(v) { return v < 0 ? 0 : (v > 1 ? 1 : v); }

  /* 高度场按百分位找海平面，而不是写死一个阈值。
     为什么必须这么做：fBm 的取值分布取决于阶数与增益，只要动一下这两者，
     同样的阈值就会对应完全不同的海平面 —— 阈值稍低就整颗星球淹掉，稍高就
     只剩几粒岛。按百分位取，海陆比就变成一个可读、可控的常数。 */
  var SEA_QUANTILE = 0.655;           // 海洋占比 65.5%
  var HI_QUANTILE = 0.9988;           // 最高峰所在的百分位
  var LO_QUANTILE = 0.0016;           // 最深海沟所在的百分位

  /* ================================================== 行星贴图的异步构建
     ── 为什么必须异步：一张 1024×512 的贴图要跑约一千万次噪声采样，同步跑完
        实测接近 1.5 秒（两个星球的场景就是 3 秒）。把它放在启动路径上，用户
        看到的第一屏会是一张完全没有背景、并且期间无法交互的静态页面。
     ── 做法：把生成过程切成「按时间预算分片」的任务，每片最多占用主线程
        8ms，剩下的交给 requestIdleCallback（不支持时退化为 setTimeout）。
        页面照常渲染与响应，贴图就绪后星球淡入。
     ── 分片挂在「行」这一级而不是像素级：一次 step 只处理若干整行，任何时刻
        中断都不会留下半行数据，恢复时也不需要保存循环内的中间变量。
     ── 之所以需要两遍（高度场 → 着色），是因为着色要用到高度场的百分位统计，
        必须等整个高度场算完才能开始。                                    */
  var TEX_CHUNK_MS = 8;

  /* 文档可滚动总量 = 文档总高 - 视口高（下限 1，避免除零）。
     星球的「滚动离场」位移系数要用它来折算：无论页面长短，
     滚动到底时星球都恰好完全移出视口。 */
  function docScrollMax() {
    var de = global.document && global.document.documentElement;
    var vh = global.innerHeight || (de && de.clientHeight) || 800;
    var dh = (de && de.scrollHeight) || vh;
    return Math.max(1, dh - vh);
  }

  var nowMs = (global.performance && global.performance.now)
    ? function () { return global.performance.now(); }
    : function () { return Date.now(); };

  var TEX_QUEUE = [];
  var TEX_QUEUED = {};                // key -> true，避免同一套贴图排队两次
  var texPumping = false;
  var TEX_STEPS = 0;                  // 诊断计数：完成的分片数（经 HQSpace._stats 暴露）

  /* 贴图就绪后需要重绘一次的登记处。
     prefers-reduced-motion 下没有动画循环，renderStatic 只跑一次；若那一帧
     贴图还没生成完，星球就永远停在一块纯色圆盘上。这里让引擎把「重绘静态帧」
     登记进来，任务完成时统一触发。
     约定：钩子返回 true 表示「还要继续等下一批贴图」，返回 false 表示
     已处理完毕、可以注销 —— 否则钩子会随重建次数无限堆积。 */
  var TEX_READY_HOOKS = [];

  function notifyTexReady() {
    for (var i = TEX_READY_HOOKS.length - 1; i >= 0; i--) {
      if (!TEX_READY_HOOKS[i]()) TEX_READY_HOOKS.splice(i, 1);
    }
  }

  /* 空闲调度（v1.6 重做）：贴图队列改由 rAF 驱动。
     ── 旧实现用 requestIdleCallback（timeout 120ms）。实测部分环境
        （headless 截图、被节流的后台标签）里 rIC 每次要等满 120–170ms、
        setTimeout(0) 也会被钳到 ~50ms 才执行，而一个 300+ 分片的贴图任务
        逐片调度就要 40 秒以上 —— 星球会长时间停在纯色占位球上。
     ── rAF 在这些环境里仍然稳定跑满 60fps，因此调度器换成 rAF（不支持时
        退化 setTimeout），并在一个调度窗口内连续推进多片：每片内部仍有
        8ms 的分片预算，窗口总预算 10ms，正常台式机上每帧只花一半时间
        在贴图上，不会把帧率压下去，贴图却能在几秒内完成。 */
  var TEX_WINDOW_MS = 10;

  function pumpTexQueue() {
    if (texPumping || !TEX_QUEUE.length) return;
    texPumping = true;
    var drive = global.requestAnimationFrame
      ? function (fn) { global.requestAnimationFrame(fn); }
      : function (fn) { global.setTimeout(fn, 0); };
    drive(function () {
      texPumping = false;
      var job = TEX_QUEUE[0];
      if (!job) return;
      var t0 = nowMs();
      while (TEX_QUEUE[0] === job) {
        if (job.step()) {
          TEX_QUEUE.shift();
          if (job.key) delete TEX_QUEUED[job.key];
          if (job.onDone) job.onDone(job.maps);
          notifyTexReady();
          break;
        }
        TEX_STEPS++;
        if (nowMs() - t0 >= TEX_WINDOW_MS) break;
      }
      pumpTexQueue();
    });
  }

  function enqueueTexJob(job) {
    TEX_QUEUE.push(job);
    pumpTexQueue();
  }

  function PlanetTexJob(key, pal, seed, onDone) {
    var W = TEX_W, H = TEX_H, N = W * H;
    var so = seedOffset(seed);
    this.key = key;
    this.pal = pal; this.t = pal.terrain;
    this.onDone = onDone;
    this.W = W; this.H = H; this.N = N;
    this.nox = so[0]; this.noy = so[1]; this.noz = so[2];
    this.hMap = new Float32Array(N);
    this.cMap = new Float32Array(N);
    this.iceMap = new Float32Array(N);
    this.phase = 0;                   // 0 高度场 / 1 着色
    this.y = 0;
    this.maps = null;
  }

  /* ---- 阶段 0：高度场 / 云密度 / 极冠阈值（一次一行）
     所有噪声都在「单位球面」上采样，两极因此不会出现经线汇聚的扇形拉伸。 */
  PlanetTexJob.prototype.row1 = function (y) {
    var W = this.W, H = this.H;
    var hMap = this.hMap, cMap = this.cMap, iceMap = this.iceMap;
    var nox = this.nox, noy = this.noy, noz = this.noz;
    var lat = (0.5 - (y + 0.5) / H) * Math.PI;        // +π/2 .. -π/2
    var sl = Math.sin(lat), cl = Math.cos(lat);
    var absLat = lat < 0 ? -lat : lat;
    var idx = y * W;
    /* 纬向权重只跟纬度有关，提到行循环外算一次即可 */
    var bandK = 0.74 + 0.26 * (0.5 + 0.5 * Math.cos(lat * 3.6));

    for (var x = 0; x < W; x++, idx++) {
      var lon = ((x + 0.5) / W) * Math.PI * 2;
      var px = cl * Math.cos(lon), py = sl, pz = cl * Math.sin(lon);

      /* 域扭曲（domain warping）：先取两层低频噪声组成偏移向量，加到球面
         坐标上，最后重新归一化回单位球面。
         ── 为什么需要它：值噪声下每个格点就是一颗「圆丘」，球面与圆丘相交
            得到的等值线是圆弧。这里已经换成梯度噪声（本身没有圆弧结构），
            扭曲的作用变成把大陆「揉」出不规则的走向与岛链，纯粹是造型需要。
         ── 为什么必须重新归一化：偏移量到了 0.3 量级，采样点就会跑出球壳、
            落进噪声体的内部；那里相邻格点之间的整体差异被压低，结果是一片
            涂抹感很强的色块，比不做扭曲更糟。归一化把偏移约束成「沿球面
            流动」，等值线才会被读成大陆、岛链与海岸。 */
      var w1 = noise3(px * 1.10 + 3.7 + nox, py * 1.10 + 9.1 + noy, pz * 1.10 + 1.3 + noz);
      var w2 = noise3(px * 1.10 + 37.1 + nox, py * 1.10 + 21.7 + noy, pz * 1.10 + 61.9 + noz);
      var qx = px + (w1 - 0.5) * 0.30;
      var qy = py + (w2 - 0.5) * 0.30;
      var qz = pz + (w1 - 0.5) * 0.30;
      var ql = 1 / Math.sqrt(qx * qx + qy * qy + qz * qz);
      qx *= ql; qy *= ql; qz *= ql;

      /* 大陆：基频 1.50 对应球面上约三十个特征区，即「十来块大陆 + 十来片
         大洋」的量级。v1.5 起用 7 阶，最高频推到 1.50×2⁶ ≈ 96，在 1536 宽
         的纹理上最细特征约 16 个像素 —— 配合更高的贴图分辨率，海岸线的
         锯齿、半岛与岛链比 1024 时代细密得多，屏幕上读作真实海岸而不是色斑。 */
      var cont = fbm3(qx * 1.50 + 11.3 + nox, qy * 1.50 + 5.1 + noy, qz * 1.50 + 7.7 + noz, 7, 2.02, 0.52);

      /* 山脉：山脊噪声只在大陆内部生效（ct 即「大陆度」）。
         限定在陆地内部有两个好处 —— 海面不会冒出无意义的凸起；大陆中央会
         自然形成一条脊线，远看就是山脉走向，而不是一地碎丘。
         v1.5 起用 6 阶，山脊的次级皱褶在高分辨率下可以辨识。 */
      var ct = clampS((cont - 0.53) * 3.8);
      var mount = ridgedFbm3(qx * 3.20 + 41.7 + nox, qy * 3.20 + 23.9 + noy, qz * 3.20 + 3.3 + noz, 6, 2.05, 0.52);
      hMap[idx] = cont + (mount - 0.36) * 0.30 * ct * ct;

      /* 云：复用扭曲后的坐标，于是「山脉上空云多、海面云少」这种地形与云的
         相关性会自然出现，同时省掉三次噪声采样。 */
      var c1 = fbm3(qx * 2.60 + 61.5 + nox, qy * 2.60 + 17.2 + noy, qz * 2.60 + 29.8 + noz, 5, 2.05, 0.52);
      var cdd = c1 * bandK;
      /* 阈值取 0.465，略低于 cdd 的中位数（实测约 0.50）—— 覆盖率约四成。
         这个数字不能凭感觉写：fBm 的取值高度集中在均值附近，阈值只要偏高一
         点，覆盖率就会从五成掉到不足一成（整颗星球没有云）；偏低一点，又会
         迅速逼近九成（糊成白球）。调参时用 HQ_DEBUG 打印的 cMap 分位数来定，
         不要靠看渲染结果反推。gamma 1.30 让薄云更透、浓云更实。 */
      var cth = (cdd - 0.465) / 0.270;
      cMap[idx] = cth <= 0 ? 0 : Math.pow(cth > 1 ? 1 : cth, 1.30);

      /* 极冠：阈值随大陆噪声起伏，冰缘因此是不规则的齿状而非一条纬线。 */
      iceMap[idx] = (absLat - (1.31 + (cont - 0.5) * 1.10)) / 0.17;
    }
  };

  /* 高度场算完后：求百分位得到海平面与高程极值，并开辟着色用的三张画布。
     Float32Array 的 sort 默认按数值升序，52 万个元素约 60ms —— 这里是整个
     构建过程中唯一一处无法再分片的地方，但它在可接受范围内。 */
  PlanetTexJob.prototype.beginPass2 = function () {
    var W = this.W, H = this.H, N = this.N;
    var sorted = new Float32Array(this.hMap);
    sorted.sort();
    var lo = sorted[(N * LO_QUANTILE) | 0];
    var SEA = sorted[(N * SEA_QUANTILE) | 0];
    var hi = sorted[Math.min(N - 1, (N * HI_QUANTILE) | 0)];
    if (hi - SEA < 1e-4) hi = SEA + 1e-4;
    if (SEA - lo < 1e-4) lo = SEA - 1e-4;

    this.lo = lo; this.SEA = SEA; this.hi = hi;
    this.invLand = 1 / (hi - SEA);
    this.invSea = 1 / (SEA - lo);

    this.surfCv = makeCanvas(W, H);
    this.cloudCv = makeCanvas(W, H);
    this.lightCv = makeCanvas(W, H);
    var sg = this.surfCv.getContext('2d');
    var cg = this.cloudCv.getContext('2d');
    var lg = this.lightCv.getContext('2d');
    this.sImg = sg.createImageData(W, H); this.sd = this.sImg.data;
    this.cImg = cg.createImageData(W, H); this.cd = this.cImg.data;
    this.lImg = lg.createImageData(W, H); this.ld = this.lImg.data;
  };

  /* ---- 阶段 1：着色（一次一行） ---- */
  PlanetTexJob.prototype.row2 = function (y) {
    var W = this.W, H = this.H;
    var t = this.t;
    var hMap = this.hMap, cMap = this.cMap, iceMap = this.iceMap;
    var nox = this.nox, noy = this.noy, noz = this.noz;
    var SEA = this.SEA, invLand = this.invLand, invSea = this.invSea;
    var sd = this.sd, cd = this.cd, ld = this.ld;
    var lat = (0.5 - (y + 0.5) / H) * Math.PI;
    var sl = Math.sin(lat), cl = Math.cos(lat);
    var idx = y * W;

    for (var x = 0; x < W; x++, idx++) {
      var p4 = idx * 4;
      var ice = clampS(iceMap[idx]);
      var hh = hMap[idx];
      var col;
      var e = 0;

      var lon = ((x + 0.5) / W) * Math.PI * 2;
      var px = cl * Math.cos(lon), py = sl, pz = cl * Math.sin(lon);

      /* 按高度逐档取色，档与档之间线性插值，因此不会出现环形硬边。
         高度先按百分位归一化成 d / e（0-1）：d 是「离海岸多深」，e 是
         「离海岸多高」。阈值因此与噪声的绝对幅值解耦，换一套噪声参数也
         不用重调。 */
      if (hh < SEA) {
        var d = clampS((SEA - hh) * invSea);
        /* 海岸浅色带刻意收窄到 0.045：它是用来勾勒大陆轮廓的，画宽了
           整片浅海都会变成亮色，大陆形状反而糊掉。 */
        if (d < 0.045) col = ptMix(t.shore, t.shelf, d / 0.045);
        else if (d < 0.22) col = ptMix(t.shelf, t.sea, (d - 0.045) / 0.175);
        else col = ptMix(t.sea, t.deep, clampS((d - 0.22) / 0.78));
        /* 洋流明暗起伏：避免大片纯色带来的死板感。
           幅度收在 0.86–1.14 —— 原来 0.74–1.26 的摆幅会把海面打成一片
           深浅不匀的斑块，远看正是「脏」的来源。起伏仍在，但不抢海岸线。 */
        var cur = fbm3(px * 2.6 + 88.1 + nox, py * 2.6 + 12.7 + noy, pz * 2.6 + 44.3 + noz, 3, 2.08, 0.5);
        var kk = 0.86 + 0.28 * cur;
        col = ptSet(col[0] * kk, col[1] * kk, col[2] * kk);
      } else {
        e = clampS((hh - SEA) * invLand);
        if (e < 0.025) col = ptMix(t.shore, t.low, e / 0.025);
        else if (e < 0.26) col = ptMix(t.low, t.mid, (e - 0.025) / 0.235);
        else if (e < 0.66) col = ptMix(t.mid, t.high, (e - 0.26) / 0.40);
        else col = ptMix(t.high, t.ice, clampS((e - 0.80) / 0.20));
        /* 陆地按高度提亮，得到可读的起伏感。系数上限 1.08 —— 再往上，
           高地上的亮色会顶到纯白，整片大陆糊成一块，反而看不出地形。 */
        var lit = 0.92 + 0.16 * e;
        col = ptSet(col[0] * lit, col[1] * lit, col[2] * lit);
      }

      if (ice > 0) col = ptMix(col, t.ice, ice);

      sd[p4] = col[0] < 0 ? 0 : (col[0] > 255 ? 255 : col[0] | 0);
      sd[p4 + 1] = col[1] < 0 ? 0 : (col[1] > 255 ? 255 : col[1] | 0);
      sd[p4 + 2] = col[2] < 0 ? 0 : (col[2] > 255 ? 255 : col[2] | 0);
      sd[p4 + 3] = 255;

      /* 云层：颜色取自调色板，alpha 即密度 */
      cd[p4] = t.cloud[0]; cd[p4 + 1] = t.cloud[1]; cd[p4 + 2] = t.cloud[2];
      cd[p4 + 3] = (clampS(cMap[idx]) * 238) | 0;

      /* 城市灯光：只在陆地、避开冰盖，且人口噪声偏高处才亮起 */
      var lv = 0;
      if (e > 0.012 && ice < 0.40) {
        var pop = fbm3(px * 9.5 + 21.7 + nox, py * 9.5 + 63.1 + noy, pz * 9.5 + 5.9 + noz, 3, 2.10, 0.56);
        var pk = (pop - 0.540) / 0.130;
        if (pk > 0) {
          /* 高频场负责把连片灯光打散成一座座城市群。频率 34（v1.5 起，
             随贴图分辨率提高同步上调）在 1536 宽的纹理上约 9 个像素一格，
             缩到球面刚好是「一簇簇亮点」而不是一片雾。 */
          var fine = fbm3(px * 34.0 + 7.1 + nox, py * 34.0 + 29.3 + noy, pz * 34.0 + 51.7 + noz, 2, 2.20, 0.5);
          lv = clampS(pk) * clampS((fine - 0.40) / 0.26) * clampS(e * 5.5) * (1 - ice / 0.40);
        }
      }
      ld[p4] = t.light[0]; ld[p4 + 1] = t.light[1]; ld[p4 + 2] = t.light[2];
      ld[p4 + 3] = (lv * 255) | 0;
    }
  };

  /* 一次分片：在时间预算内尽量多推进几行，返回是否已经全部完成。 */
  PlanetTexJob.prototype.step = function () {
    var t0 = nowMs();
    var H = this.H;

    if (this.phase === 0) {
      while (this.y < H) {
        this.row1(this.y++);
        if (nowMs() - t0 >= TEX_CHUNK_MS) return false;
      }
      this.beginPass2();
      this.phase = 1;
      this.y = 0;
    }

    while (this.y < H) {
      this.row2(this.y++);
      if (nowMs() - t0 >= TEX_CHUNK_MS) return false;
    }

    this.finish();
    return true;
  };

  PlanetTexJob.prototype.finish = function () {
    var W = this.W, H = this.H, N = this.N;
    this.surfCv.getContext('2d').putImageData(this.sImg, 0, 0);
    this.cloudCv.getContext('2d').putImageData(this.cImg, 0, 0);
    this.lightCv.getContext('2d').putImageData(this.lImg, 0, 0);
    this.sImg = this.cImg = this.lImg = null;
    this.sd = this.cd = this.ld = null;

    var maps = { surface: this.surfCv, clouds: this.cloudCv, lights: this.lightCv };

    /* 调试出口：把高度场本身、以及海陆二值图导出来。
       贴图出问题时，先分清是「高度场本来就没有结构」还是「着色把它抹平了」，
       这两者要调的参数完全不同。仅在 HQ_DEBUG 下生成，正常运行零开销。 */
    if (global.HQ_DEBUG) {
      var hMap = this.hMap, cMap = this.cMap;
      var lo = this.lo, hi = this.hi, SEA = this.SEA;
      var dCv = makeCanvas(W, H), dg = dCv.getContext('2d');
      var dImg = dg.createImageData(W, H), dd = dImg.data;
      var gCv = makeCanvas(W, H), gg2 = gCv.getContext('2d');
      var gImg = gg2.createImageData(W, H), gd = gImg.data;
      var i;
      for (i = 0; i < N; i++) {
        var q4 = i * 4;
        var vv = clampS((hMap[i] - lo) / (hi - lo));
        var g8 = (vv * 255) | 0;
        dd[q4] = g8; dd[q4 + 1] = g8; dd[q4 + 2] = g8; dd[q4 + 3] = 255;
        var land = hMap[i] > SEA ? 236 : 24;
        gd[q4] = land; gd[q4 + 1] = land; gd[q4 + 2] = land; gd[q4 + 3] = 255;
      }
      dg.putImageData(dImg, 0, 0);
      gg2.putImageData(gImg, 0, 0);
      maps.height = dCv;
      maps.land = gCv;

      /* 统计量：云层阈值这类参数直接取决于噪声幅值，靠肉眼看渲染结果反推
         非常低效。把分布的均值和分位数打出来，阈值就能一次调准。 */
      if (global.console && global.console.log) {
        var st = new Float32Array(cMap); st.sort();
        var hst = new Float32Array(hMap); hst.sort();
        var mean = 0;
        for (i = 0; i < N; i++) mean += cMap[i];
        global.console.log('    [stats] cloud mean=' + (mean / N).toFixed(4)
          + ' p50=' + st[(N * 0.5) | 0].toFixed(3)
          + ' p75=' + st[(N * 0.75) | 0].toFixed(3)
          + ' p90=' + st[(N * 0.90) | 0].toFixed(3)
          + ' p97=' + st[(N * 0.97) | 0].toFixed(3)
          + ' p99.5=' + st[(N * 0.995) | 0].toFixed(3)
          + ' | height p1=' + hst[(N * 0.01) | 0].toFixed(3)
          + ' p50=' + hst[(N * 0.5) | 0].toFixed(3)
          + ' p99=' + hst[(N * 0.99) | 0].toFixed(3)
          + ' | sea=' + SEA.toFixed(4) + ' hi=' + hi.toFixed(4) + ' lo=' + lo.toFixed(4));
      }
    }

    this.maps = maps;
    this.hMap = this.cMap = this.iceMap = null;
  };

  /* --------------------------------------------------------- 球面明暗遮罩（静态） */
  function makeShadeMask(size, light, pal) {
    var c = makeCanvas(size, size);
    var g = c.getContext('2d');
    var R = size / 2;
    var lx = R + light[0] * R, ly = R + light[1] * R;
    var grad = g.createRadialGradient(lx, ly, R * 0.04, R, R, R * 1.58);
    /* 暗面要够暗才有体积感，但不能压到纯黑。
       原先把边缘一路压到 rgba(4,6,14)，星球在深色页面里就成了一块「黑斑」，
       整页观感随之沉下去。现在把中后段整体抬高约一档：明暗关系仍清晰，
       暗面却保留得住一层可辨识的冷色反光，球体因此「透」一些。 */
    grad.addColorStop(0.00, 'rgba(255,255,255,1)');
    grad.addColorStop(0.20, 'rgba(236,242,255,1)');
    grad.addColorStop(0.38, 'rgba(158,172,216,1)');
    grad.addColorStop(0.55, 'rgba(84,98,152,1)');
    grad.addColorStop(0.72, 'rgba(40,50,90,1)');
    grad.addColorStop(0.88, 'rgba(24,32,60,1)');
    grad.addColorStop(1.00, 'rgba(18,24,48,1)');
    g.fillStyle = grad;
    g.fillRect(0, 0, size, size);
    return c;
  }

  /* ------------------------------------------------------- 星球渲染（离屏合成） */
  var TEX_CACHE = {};                 // 纹理按「调色板 + 种子」缓存，重排时零开销
  var RING_RES = 1024;                // 行星环预渲染固定分辨率（环本身柔和，放大无损观感）

  /* 极光色。与所在星球的主色相拉开，才能在异色星球上看出「这是极光」
     而不是一片同色雾。v1.6 起键名跟随新调色板。 */
  var AURORA = {
    violet: '132,255,204', sulfur: '216,150,255', ember: '120,255,236',
    orchid: '150,255,212', frost: '255,150,220'
  };

  /* 球面滑动采样：把等距屏幕列映射回经度，得到真实的边缘压缩。
     抽成独立函数是因为地表、城市灯光、云层三张贴图都要走同一套映射，
     只是相位不同 —— 复制三遍会让这段最容易出错的边界处理逻辑难以维护。 */
  function blitSphere(tg, tex, texW, texH, rs, ps, N, phase) {
    for (var i = 0; i < N; i++) {
      var x0 = -rs + 2 * rs * i / N;
      var x1 = -rs + 2 * rs * (i + 1) / N;
      var l0 = Math.asin(clamp(x0 / rs, -1, 1));
      var l1 = Math.asin(clamp(x1 / rs, -1, 1));
      var sw = (l1 - l0) / (Math.PI * 2) * texW;
      if (sw <= 0.02) continue;
      /* 目标矩形取整对齐：相邻列的边界因此完全重合，不会在球面上留下
         一条条竖着的接缝。非整数边界会让 drawImage 对两侧各做一次抗锯齿，
         在高对比的云层上这条缝会非常明显。 */
      var dx = Math.round(x0 + rs);
      var dw = Math.max(1, Math.round(x1 + rs) - dx);
      var sx = ((l0 / (Math.PI * 2)) + 0.25 + phase) * texW;
      var wrapped = sx % texW; if (wrapped < 0) wrapped += texW;
      if (wrapped + sw <= texW) {
        tg.drawImage(tex, wrapped, 0, sw, texH, dx, 0, dw, ps);
      } else {
        /* 跨过纹理接缝：拆成两段绘制，否则会出现一条竖着的错位 */
        var first = texW - wrapped;
        tg.drawImage(tex, wrapped, 0, first, texH, dx, 0, dw * (first / sw), ps);
        tg.drawImage(tex, 0, 0, sw - first, texH, dx + dw * (first / sw), 0, dw * (1 - first / sw), ps);
      }
    }
  }

  /* 极光椭圆（auroral oval）
     ── 为什么不能直接用「中心亮、边缘透明」的径向渐变：那样铺出来是一整片
        均匀色雾 —— 面积大、没有形状，看起来只是球面被染了色，不是极光。
     ── 为什么不能把渐变的圆心放到球外、靠峰值半径去凑一圈弧：那条弧除了贴着
        极点的那一段，还会从球心下方穿过去（同一半径的圆与球面圆相交于两点），
        结果球体中间会横出一条绿带。
     ── 正确做法：先把坐标系压扁成椭圆，再在单位圆里做环带。球面上一条纬度圈
        在正交投影下就是椭圆，极光椭圆因此天然是「扁的、贴着极点的」。
        side = -1 画北极、+1 画南极。                                  */
  function auroraBand(tg, rs, ps, aur, side, alpha) {
    var a = rs * 0.36, b = rs * 0.26;                 // 椭圆半轴
    var cy = rs + side * rs * 0.62;                   // 椭圆心相对球心的偏移
    tg.save();
    tg.translate(rs, cy);
    tg.scale(a, b);
    /* 环带的上升段必须够长（0.35 -> 0.62 才到达峰值）。收得太窄时，
       同一条椭圆会因为明暗过渡过陡而被读成「画上去的一个圈」，
       而不是一团有厚度的极光。 */
    var g = tg.createRadialGradient(0, 0, 0.74, 0, 0, 1.20);
    g.addColorStop(0, 'rgba(' + aur + ',0)');
    g.addColorStop(0.35, 'rgba(' + aur + ',0)');
    g.addColorStop(0.62, 'rgba(' + aur + ',' + alpha.toFixed(3) + ')');
    g.addColorStop(1, 'rgba(' + aur + ',0)');
    tg.fillStyle = g;
    tg.fillRect(-1.4, -1.4, 2.8, 2.8);
    tg.restore();
  }

  function Planet(o) {
    this.cfg = o.cfg;
    this.pal = PALETTES[o.cfg.palette] || PALETTES.azure;
    this.seed = o.seed;
    this.cap = 880;                 // 星球渲染分辨率上限，超大屏靠放大省算力
    this.phase = o.phase || 0;      // 地表自转相位
    /* 云层相位独立推进，且速率与地表不同 —— 这个「差速」是让星球看起来
       是「有大气在流动的行星」而不是「一张贴图在转」的关键。 */
    this.cloudPhase = (o.phase || 0) * 0.41;
    this.aurT = o.seed % 6.28;
    this.tmp = makeCanvas(8, 8);
    this.tg = this.tmp.getContext('2d');
    this.setQuality(o.quality || 0);

    /* 贴图命中缓存就直接用；否则排进异步任务队列，先画一块纯色圆盘占位。
       注意这里不能用「同 key 就复用旧 Planet」的思路：同一场景内 resize 会
       反复 build()，而队列里的任务只应存在一份，去重交给 TEX_QUEUED。
       若本实例创建时任务已在队列里（引擎重建早于贴图完成），旧任务的 onDone
       闭包绑定的是旧实例 —— 本实例必须自己登记一个就绪钩子，否则会永远
       停在纯色占位球上（v1.6 修复的「星球变成另一颗」问题的一部分）。 */
    var key = o.cfg.palette + '_' + o.seed;
    this.key = key;
    var cached = TEX_CACHE[key];
    if (cached) {
      this.applyMaps(cached);
    } else if (!TEX_QUEUED[key]) {
      TEX_QUEUED[key] = true;
      var self = this;
      enqueueTexJob(new PlanetTexJob(key, this.pal, o.seed, function (m) {
        TEX_CACHE[key] = m;
        self.applyMaps(m);
      }));
    } else {
      var selfWait = this;
      TEX_READY_HOOKS.push(function () {
        var m = TEX_CACHE[key];
        if (!m) return true;               // 其他贴图先就绪，继续等本 key
        if (!selfWait.tex) selfWait.applyMaps(m);
        return false;                      // 已接入，注销本钩子
      });
    }

    if (o.cfg.ring) this.buildRing();
    this.refresh(o.geom);
  }

  Planet.prototype.applyMaps = function (maps) {
    this.tex = maps.surface;
    this.cloudTex = maps.clouds;
    this.lightTex = maps.lights;
    this.debugHeight = maps.height;     // 仅在 HQ_DEBUG 下存在
    this.debugLand = maps.land;
    this.texW = TEX_W;
    this.texH = TEX_H;
    this.fade = 0;                      // 淡入进度，由 drawSurface 每帧推进
  };

  /* 性能档位（v1.6 再设计）。核心原则：降档不允许改变画面的「内容」，
     只允许降低它的「精度」—— 旧版降档会关掉云与极光、砍掉一半星星，
     用户滚一次长页回来就会发现「星球变成了另一颗」，这比掉帧更伤观感。
     因此降档只动两处真正便宜且无损观感的开关：
       · 球面映射列数（3×N 次 drawImage，最大成本项）；
       · 画布渲染分辨率（build 内按档位乘 0.85 / 0.75 / 0.6）。
     云、极光、星野、星尘、流星在所有档位保持原样。 */
  Planet.prototype.setQuality = function (d) {
    this.degrade = d;
    this.cols = d >= 3 ? 32 : (d >= 2 ? 40 : (d >= 1 ? 48 : 64));
    this.showClouds = true;
    this.showAurora = true;
  };

  /* 环带：以「上半环 / 下半环」两张预渲染贴图保存，绘制时用 transform 压扁成椭圆 */
  Planet.prototype.buildRing = function () {
    var rg = this.cfg.ring;
    var pal = this.pal.ring;
    var unitR = (RING_RES / 2) / rg.outer;             // 单位半径 -> 贴图半径
    var half = RING_RES / 2;
    var steps = 240;

    this.ringSize = RING_RES;
    this.ringUnitR = unitR;
    this.ringCv = [makeCanvas(RING_RES, RING_RES), makeCanvas(RING_RES, RING_RES)];

    for (var h = 0; h < 2; h++) {
      var g = this.ringCv[h].getContext('2d');
      g.clearRect(0, 0, RING_RES, RING_RES);
      g.save();
      /* h=0 -> 上半环（远侧，位于星球之后）；h=1 -> 下半环（近侧，位于星球之前） */
      g.beginPath();
      if (h === 0) g.rect(0, 0, RING_RES, half);
      else g.rect(0, half, RING_RES, half);
      g.clip();

      for (var i = 0; i < steps; i++) {
        var t = (i + 0.5) / steps;
        var r = lerp(rg.inner, rg.outer, t);
        /* 密度：内外缘渐隐 × 多频叠加形成的天然环缝 */
        var edge = Math.sin(Math.PI * clamp(t, 0, 1));
        var dens = (0.52 + 0.48 * Math.sin(t * 21.7 + 0.6)) * (0.68 + 0.32 * Math.sin(t * 6.3 + 2.1));
        var a = 0.115 * (0.30 + 0.90 * edge) * (0.30 + 0.85 * dens);
        if (a <= 0.002) continue;

        /* 颜色在调色板内平滑插值，避免分带生硬 */
        var pos = t * (pal.length - 1);
        var i0 = Math.floor(pos), i1 = Math.min(pal.length - 1, i0 + 1);
        var k = pos - i0;
        var c0 = pal[i0], c1 = pal[i1];
        var cr = Math.round(lerp(c0[0], c1[0], k));
        var cg = Math.round(lerp(c0[1], c1[1], k));
        var cb = Math.round(lerp(c0[2], c1[2], k));

        g.strokeStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',' + a.toFixed(3) + ')';
        g.lineWidth = (rg.outer - rg.inner) * unitR / steps * 2.6;   // 相邻重叠，杜绝缝隙
        g.beginPath();
        g.arc(half, half, r * unitR, 0, Math.PI * 2);
        g.stroke();
      }
      g.restore();
    }
  };

  Planet.prototype.refresh = function (geom) {
    this.geom = geom;                                     // {cx, cy, R}
    var ps = Math.max(24, Math.round(Math.min(geom.R * 2, this.cap)));
    this.ps = ps;
    this.rs = ps / 2;
    this.shade = makeShadeMask(ps, this.cfg.light, this.pal);

    if (this.tmp.width !== ps || this.tmp.height !== ps) {
      this.tmp = makeCanvas(ps, ps);
      this.tg = this.tmp.getContext('2d');
    }
    if (this.cfg.ring) {
      this.ringDraw = this.cfg.ring.outer * geom.R * 2;   // 环在屏幕上的外径尺寸
    }
  };

  /* 球面合成：地表 → 明暗 → 城市灯光 → 云层 → 高光与大气 → 极光。
     全部在同一条 clip 到球面的离屏画布上完成，外层因此只需要一次 drawImage，
     也才能让行星环的远 / 近半环正确地夹住球体。 */
  Planet.prototype.drawSurface = function () {
    var tg = this.tg, ps = this.ps, rs = this.rs;
    var TW = this.texW, TH = this.texH, N = this.cols;

    tg.setTransform(1, 0, 0, 1, 0, 0);
    tg.clearRect(0, 0, ps, ps);
    tg.save();
    tg.beginPath();
    tg.arc(rs, rs, rs, 0, Math.PI * 2);
    tg.clip();

    /* ---- 0. 贴图尚未就绪：先画一块纯色球 ----
       贴图是分片异步生成的，首屏必然会经历这个状态。比起让星球直接缺席
       （背景会突然空出 `块位置），一块带明暗的纯色球在视觉上是连续的 ——
       贴图就绪后再淡入，用户看到的是「星球逐渐显出地貌」而不是一次跳变。 */
    if (!this.tex) {
      tg.globalCompositeOperation = 'source-over';
      tg.globalAlpha = 1;
      tg.fillStyle = this.pal.base;
      tg.beginPath();
      tg.arc(rs, rs, rs, 0, Math.PI * 2);
      tg.fill();
      tg.globalCompositeOperation = 'multiply';
      tg.drawImage(this.shade, 0, 0);
      tg.globalCompositeOperation = 'source-over';
      tg.restore();
      this.drawAlpha = 1;
      return;
    }
    if (this.fade < 1) this.fade = Math.min(1, this.fade + 0.055);
    this.drawAlpha = this.fade;

    /* ---- 1. 地表 ---- */
    tg.globalCompositeOperation = 'source-over';
    blitSphere(tg, this.tex, TW, TH, rs, ps, N, this.phase);

    /* ---- 2. 明暗（multiply 只作用于球面内，因为已经 clip 过） ---- */
    tg.globalCompositeOperation = 'multiply';
    tg.drawImage(this.shade, 0, 0);

    /* ---- 3. 夜面城市灯光 ----
       直接叠加即可，不需要额外的昼夜遮罩：昼面地表本身很亮，几点灯光看不出来；
       夜面地表已经足够暗，同一张贴图就会自然显出来。 */
    if (this.lightTex) {
      tg.globalCompositeOperation = 'lighter';
      tg.globalAlpha = 0.85;
      blitSphere(tg, this.lightTex, TW, TH, rs, ps, N, this.phase);
      tg.globalAlpha = 1;
    }

    /* ---- 4. 云层：相位与地表不同，形成相对流动 ---- */
    if (this.showClouds) {
      tg.globalCompositeOperation = 'source-over';
      tg.globalAlpha = 0.78;
      blitSphere(tg, this.cloudTex, TW, TH, rs, ps, N, this.cloudPhase);
      tg.globalAlpha = 1;
    }

    /* ---- 5. 受光侧高光 + 大气边缘散射 ---- */
    tg.globalCompositeOperation = 'lighter';
    var lx = rs + this.cfg.light[0] * rs, ly = rs + this.cfg.light[1] * rs;
    var spec = tg.createRadialGradient(lx, ly, 0, lx, ly, rs * 0.95);
    spec.addColorStop(0, 'rgba(255,255,255,0.055)');
    spec.addColorStop(0.45, 'rgba(196,212,255,0.022)');
    spec.addColorStop(1, 'rgba(255,255,255,0)');
    tg.fillStyle = spec;
    tg.fillRect(0, 0, ps, ps);

    /* 大气散射最亮处落在受光侧的球面边缘，即晨昏线靠昼侧的那一段 */
    var lxa = Math.atan2(this.cfg.light[1], this.cfg.light[0]);
    var atmo = this.pal.atmo;
    var g3 = tg.createRadialGradient(rs, rs, rs * 0.84, rs, rs, rs);
    g3.addColorStop(0, 'rgba(' + atmo + ',0)');
    g3.addColorStop(0.72, 'rgba(' + atmo + ',0.06)');
    g3.addColorStop(1, 'rgba(' + atmo + ',0.19)');
    tg.fillStyle = g3;
    tg.beginPath();
    tg.arc(rs, rs, rs, lxa - 1.6, lxa + 1.6);
    tg.arc(rs, rs, rs * 0.84, lxa + 1.6, lxa - 1.6, true);
    tg.fill();

    /* ---- 6. 极光 ----
       极区在正视球面上的投影就是圆的上下边缘，所以这里画的是以该处为圆心、
       在球外的大部分会被 clip 裁掉的环带。颜色刻意与主色相拉开，
       否则在紫调星球上会被读成一片紫雾而不是极光。 */
    if (this.showAurora) {
      var aur = AURORA[this.cfg.palette] || '138,250,214';
      var pulse = 0.55 + 0.45 * Math.sin(this.aurT);
      /* 极光画成「环带」而不是「圆斑」。
         用径向渐变做圆斑时，峰值停在全透明与满色之间的中段，铺出来是一整片
         均匀的色雾 —— 大面积、无形状，看起来像球面被染了色。
         改成把峰值停在球面顶缘所在的那个半径（约 0.76 的归一化位置），
         内外两侧都归零，得到的才是一圈贴在极区边缘的极光椭圆。 */
      auroraBand(tg, rs, ps, aur, -1, 0.30 * pulse);
      auroraBand(tg, rs, ps, aur, 1, 0.21 * pulse);
    }

    tg.globalCompositeOperation = 'source-over';
    tg.restore();
  };

  /* ------------------------------------------------------------------ 主引擎 */
  function Engine(sceneName) {
    this.name = sceneName;
    this.cfg = SCENES[sceneName] || SCENES.home;
    this.canvas = document.createElement('canvas');
    this.canvas.className = 'bg-canvas';
    this.canvas.setAttribute('aria-hidden', 'true');
    document.body.insertBefore(this.canvas, document.body.firstChild);
    this.ctx = this.canvas.getContext('2d');

    this.degrade = 0;          // 性能降档等级 0-3
    this.raf = null;
    this.running = false;
    this.t = 0;
    this.lastTs = 0;
    this.fpsAcc = 0; this.fpsFrames = 0; this.fpsLocked = false;
    this.mx = 0.5; this.my = 0.5;
    this.tmx = 0.5; this.tmy = 0.5;
    this.scroll = 0;
    this.scrollMax = 1;

    this.build();
    this.bind();
  }

  Engine.prototype.rng = function () { return mulberry32(this.cfg.seed); };

  /* -------------------------------------------------------------- 几何与资源 */
  Engine.prototype.build = function () {
    var w = this.w = global.innerWidth;
    var h = this.h = global.innerHeight;
    var mobile = w < 768;
    var dprCap = mobile ? 1.5 : 2;
    /* 降档用渲染分辨率换帧率（内容不变），分辨率系数随档位递减 */
    var dprScale = this.degrade >= 3 ? 0.6 : (this.degrade === 2 ? 0.75 : (this.degrade === 1 ? 0.85 : 1));
    this.dpr = Math.min(global.devicePixelRatio || 1, dprCap) * dprScale;
    this.dprStart = this.dpr;

    this.canvas.width = Math.round(w * this.dpr);
    this.canvas.height = Math.round(h * this.dpr);
    this.canvas.style.width = w + 'px';
    this.canvas.style.height = h + 'px';
    this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    /* 滚动离场的基准量：文档变了（图片加载、字体就绪都会改变文档高度），
       scrollMax 也要跟着重算，否则「到底时星球恰好出画」就不准了。 */
    this.scrollMax = docScrollMax();

    /* 天空底色（v1.5）：星云是「黑底 + 加色色斑」，若直接铺在画布上，
       色斑之间就是一片近黑 —— 这是页面显得暗的直接原因。
       现在每帧先铺一层上亮下暗的天青渐变，星云用 lighter 叠上去，
       全幅任何位置都不会掉到近黑，金色斑叠在亮底上也不再发浑。 */
    var bg = this.ctx.createLinearGradient(0, 0, 0, h);
    bg.addColorStop(0, '#2A4E78');
    bg.addColorStop(0.55, '#1C375A');
    bg.addColorStop(1, '#152B4A');
    this.baseGrad = bg;

    /* 星野。降档不减星星数量 —— 数量一变场景就「换了内容」（v1.6 教训） */
    var seed = this.cfg.seed;
    var count = Math.round((mobile ? 130 : 300) * this.cfg.stars);
    var rnd = mulberry32(seed + 11);
    var layers = [
      { n: Math.round(count * 0.52), r: [0.5, 0.85], a: [0.20, 0.48], sp: 0.010, par: 0.35 },
      { n: Math.round(count * 0.33), r: [0.85, 1.35], a: [0.35, 0.72], sp: 0.020, par: 0.70 },
      { n: Math.round(count * 0.15), r: [1.35, 2.10], a: [0.55, 1.00], sp: 0.032, par: 1.20 }
    ];
    this.stars = [];
    for (var li = 0; li < layers.length; li++) {
      var L = layers[li];
      for (var i = 0; i < L.n; i++) {
        this.stars.push({
          x: rnd() * w, y: rnd() * h,
          r: lerp(L.r[0], L.r[1], rnd()),
          a: lerp(L.a[0], L.a[1], rnd()),
          sp: L.sp * (0.6 + rnd() * 0.8),
          par: L.par,
          ph: rnd() * Math.PI * 2,
          tw: 0.5 + rnd() * 1.6,
          hue: rnd() > 0.86 ? 'warm' : (rnd() > 0.8 ? 'cool' : 'white')
        });
      }
    }

    /* 星点精灵（3 档尺寸 × 3 色调） */
    this.sprites = {};
    var tints = { white: [255, 255, 255], warm: [255, 226, 190], cool: [190, 214, 255] };
    for (var key in tints) {
      var arr = [];
      for (var s = 0; s < 3; s++) {
        var sz = 8 + s * 8;
        var c = makeCanvas(sz, sz);
        var cg = c.getContext('2d');
        var col = tints[key];
        var gr = cg.createRadialGradient(sz / 2, sz / 2, 0, sz / 2, sz / 2, sz / 2);
        gr.addColorStop(0, 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',1)');
        gr.addColorStop(0.28, 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',0.62)');
        gr.addColorStop(0.62, 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',0.14)');
        gr.addColorStop(1, 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',0)');
        cg.fillStyle = gr;
        cg.fillRect(0, 0, sz, sz);
        arr.push(c);
      }
      this.sprites[key] = arr;
    }

    /* 星云（1/4 分辨率预渲染，之后仅做放大合成）
       画布比视口大一圈，配合漂移量保证四周始终被完整覆盖 */
    /* 星云：启动路径上最大的一笔开销（成本随视口面积线性增长，2560×1440 上
       同步跑要 700ms 以上），同样交给分片任务。它是排在队首的第一份任务，
       因此会最先就绪 —— 这一层只是背景薄雾，晚一两帧出现并不影响首屏。 */
    this.nebScale = 0.32;
    this.neb = null;
    this.nebCtx = null;
    this.nebFade = 0;
    var nw0 = Math.max(8, Math.round((w + 96) * this.nebScale));
    var nh0 = Math.max(8, Math.round((h + 96) * this.nebScale));
    var nkey = this.name + '|' + Math.round(nw0 / 24) + 'x' + Math.round(nh0 / 24);
    if (NEB_CACHE.key === nkey && NEB_CACHE.cv) {
      this.neb = NEB_CACHE.cv;
      this.nebCtx = this.neb.getContext('2d');
      this.nebFade = 1;
    } else {
      var selfN = this;
      enqueueTexJob(new NebulaJob(this.cfg, w, h, this.nebScale, this.name, function (cv) {
        NEB_CACHE.key = nkey;
        NEB_CACHE.cv = cv;
        selfN.neb = cv;
        selfN.nebCtx = cv.getContext('2d');
      }));
    }

    /* 星球 */
    var old = this.pList || [];
    this.pList = [];
    for (var pi = 0; pi < this.cfg.planets.length; pi++) {
      var c2 = this.cfg.planets[pi];
      var seed = this.cfg.seed + pi * 977;
      var reuse = old[pi];
      var planObj;
      if (reuse && reuse.seed === seed && reuse.cfg.palette === c2.palette) {
        planObj = reuse;
        planObj.cfg = c2;
        planObj.setQuality(this.degrade);
        planObj.refresh(this.geomOf(c2));
      } else {
        planObj = new Planet({ cfg: c2, seed: seed, geom: this.geomOf(c2), quality: this.degrade });
      }
      this.pList.push(planObj);
    }

    /* 星尘 */
    var drnd = mulberry32(seed + 77);
    this.dustArr = [];
    var dn = (mobile || this.degrade >= 3 || !this.cfg.dust) ? 0 : 46;
    for (var d = 0; d < dn; d++) {
      this.dustArr.push({
        x: drnd() * w, y: drnd() * h,
        r: 0.4 + drnd() * 1.5,
        a: 0.06 + drnd() * 0.16,
        vy: -(0.05 + drnd() * 0.22),
        vx: (drnd() - 0.5) * 0.10,
        ph: drnd() * Math.PI * 2
      });
    }

    this.meteor = null;
    this.meteorCd = 3 + Math.random() * 6;
  };

  /* 全局星球尺度。各场景的 size 是相对视口高度的比例，但真实站点里星球是
     「背景装饰」而不是主体 —— 尺寸过大时它会顶到屏幕边缘、抢走正文的注意力，
     同时纹理被放大得更多，地貌会糊成色块。这里用一个统一系数收一档。 */
  var PLANET_SCALE = 0.76;

  Engine.prototype.geomOf = function (pc) {
    var minSide = Math.min(this.w, this.h);
    var R = (pc.size * this.h * PLANET_SCALE) / 2;
    if (R * 2 > minSide * 1.5) R = minSide * 0.75;
    /* 窄屏再收一档：手机上星球占满整个视口宽，直接垫在标题与正文后面，
       再好的文字对比度也架不住高纹理亮色大陆贴脸（v1.6 实拍踩坑） */
    if (this.w < 768) R *= 0.72;
    return { cx: pc.x * this.w, cy: pc.y * this.h, R: R };
  };

  function hexToRgb(hex) {
    var v = hex.replace('#', '');
    if (v.length === 3) v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2];
    var n = parseInt(v, 16);
    return ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255);
  }

  /* -------------------------------------------------------------- 事件绑定 */
  Engine.prototype.bind = function () {
    var self = this;
    this.onResize = function () {
      clearTimeout(self.rt);
      self.rt = setTimeout(function () { self.build(); }, 180);
    };
    this.onScroll = function () {
      self.tScroll = global.pageYOffset || document.documentElement.scrollTop || 0;
      self.scrollMax = docScrollMax();
    };
    this.onMove = function (e) {
      if (self.w < 1024) return;
      self.tmx = e.clientX / self.w;
      self.tmy = e.clientY / self.h;
    };
    this.onVis = function () {
      if (document.hidden) self.pause(); else self.resume();
    };
    global.addEventListener('resize', this.onResize);
    global.addEventListener('scroll', this.onScroll, { passive: true });
    global.addEventListener('mousemove', this.onMove, { passive: true });
    document.addEventListener('visibilitychange', this.onVis);
    this.tScroll = global.pageYOffset || 0;
  };

  Engine.prototype.unbind = function () {
    global.removeEventListener('resize', this.onResize);
    global.removeEventListener('scroll', this.onScroll);
    global.removeEventListener('mousemove', this.onMove);
    document.removeEventListener('visibilitychange', this.onVis);
  };

  /* ------------------------------------------------------------------ 绘制 */
  Engine.prototype.frame = function (now) {
    var dt = Math.min(50, now - (this.lastTs || now)) / 1000;
    this.lastTs = now;
    this.t += dt;

    /* 鼠标 / 滚动视差缓动 */
    this.mx = lerp(this.mx, this.tmx, 0.045);
    this.my = lerp(this.my, this.tmy, 0.045);
    this.scroll = lerp(this.scroll, this.tScroll || 0, 0.09);
    var parX = (this.mx - 0.5) * 2;
    var parY = (this.my - 0.5) * 2;
    var sc = this.scroll;

    /* 性能监测与自动降档。
       ── 滚动进行中不采样：滚动时每帧都在重绘整幅画布，瞬时帧率本来就低；
          若据此降档，用户每滚一次长页就会被永久换成低配画面 —— 表现就是
          「滚到底再滚回来，星球变成了另一颗」（云与极光消失、贴图变粗）。
          判据用缓动目标与当前值的距离：还在收敛就说明滚动尚未结束。
       ── 降档需要连续两个低帧窗口才生效：单次 2.5s 的偶发卡顿（后台标签
          切回、贴图任务收尾）不该立刻付出画质代价。 */
    if (!this.fpsLocked) {
      var scrollActive = Math.abs((this.tScroll || 0) - this.scroll) > Math.max(2, this.h * 0.004);
      if (scrollActive) {
        this.fpsAcc = 0; this.fpsFrames = 0; this.fpsLow = 0;
      } else {
        this.fpsAcc += dt; this.fpsFrames++;
        if (this.fpsAcc > 2.5) {
          var fps = this.fpsFrames / this.fpsAcc;
          if (fps < 40 && this.degrade < 3) {
            this.fpsLow = (this.fpsLow || 0) + 1;
            if (this.fpsLow >= 2) {
              this.degrade++;
              this.fpsLow = 0;
              this.build();
              if (this.degrade >= 3) this.fpsLocked = true;
            }
          } else {
            if (fps >= 52) this.fpsLocked = true;
            this.fpsLow = 0;
          }
          this.fpsAcc = 0; this.fpsFrames = 0;
        }
      }
    }

    var ctx = this.ctx, w = this.w, h = this.h;
    ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;
    /* 天空底色：星云用 lighter 叠加，需要一个不透明的亮底（见 build） */
    if (this.baseGrad) { ctx.fillStyle = this.baseGrad; ctx.fillRect(0, 0, w, h); }

    /* L1 星云。它是分片异步生成的，未就绪时整层跳过 —— 视差星野与星球
       此时已经在画，背景不会空着。就绪后用 0.7 秒左右的淡入接上，
       避免一层薄雾「啪」地一下铺满。 */
    if (this.neb) {
      if (this.nebFade < 1) this.nebFade = Math.min(1, this.nebFade + dt * 1.4);
      var nebDrift = 12 + Math.sin(this.t * 0.018) * 12;  // 恒为正，避免越界取源产生边缘拉丝
      ctx.globalCompositeOperation = 'lighter';
      ctx.globalAlpha = this.nebFade;
      ctx.drawImage(this.neb, 0, 0, this.neb.width, this.neb.height,
        -16 + nebDrift - parX * 6, -16 - parY * 4 + sc * 0.02, w + 64, h + 64);
    }

    /* L2 三层视差星野 */
    ctx.globalCompositeOperation = 'lighter';
    for (var i = 0; i < this.stars.length; i++) {
      var st = this.stars[i];
      var bx = st.x + this.t * st.sp * 6 - parX * 14 * st.par;
      var by = st.y - parY * 10 * st.par + sc * 0.012 * st.par;
      var x = bx % w; if (x < 0) x += w;
      var y = by % h; if (y < 0) y += h;
      var tw = 0.62 + 0.38 * Math.sin(this.t * st.tw + st.ph);
      var size = st.r * 4.2;
      var idx = st.r > 1.6 ? 2 : (st.r > 1.0 ? 1 : 0);
      ctx.globalAlpha = st.a * tw;
      ctx.drawImage(this.sprites[st.hue][idx], x - size / 2, y - size / 2, size, size);
    }
    ctx.globalAlpha = 1;

    /* L6 流星 */
    if (this.cfg.meteors && this.degrade < 3) this.drawMeteor(dt, parX);

    /* L3-L5 星球 / 环 / 卫星 */
    for (var pi = 0; pi < this.pList.length; pi++) {
      this.drawPlanet(this.pList[pi], dt, parX, parY, sc);
    }

    /* L7 星尘 */
    if (this.dustArr.length) {
      ctx.globalCompositeOperation = 'lighter';
      for (var d = 0; d < this.dustArr.length; d++) {
        var du = this.dustArr[d];
        du.x += du.vx; du.y += du.vy;
        if (du.y < -8) { du.y = h + 8; du.x = Math.random() * w; }
        if (du.x < -8) du.x = w + 8;
        if (du.x > w + 8) du.x = -8;
        ctx.globalAlpha = du.a * (0.55 + 0.45 * Math.sin(this.t * 0.9 + du.ph));
        ctx.fillStyle = '#C7D2FF';
        ctx.fillRect(du.x - parX * 5, du.y - parY * 4 + sc * 0.02, du.r, du.r);
      }
      ctx.globalAlpha = 1;
    }

    ctx.globalCompositeOperation = 'source-over';
    this.raf = global.requestAnimationFrame(this.frame.bind(this));
  };

  Engine.prototype.drawMeteor = function (dt, parX) {
    var ctx = this.ctx;
    if (this.meteor) {
      var m = this.meteor;
      m.life += dt;
      m.x += m.vx * dt; m.y += m.vy * dt;
      var k = m.life / m.dur;
      if (k >= 1) { this.meteor = null; this.meteorCd = 6 + Math.random() * 12; }
      else {
        var fade = Math.sin(Math.PI * clamp(k, 0, 1));
        var len = 150 + 120 * k;
        var tx = m.x - m.vx * 0.06, ty = m.y - m.vy * 0.06;
        var g = ctx.createLinearGradient(m.x, m.y, tx - m.vx * 0.16, ty - m.vy * 0.16);
        g.addColorStop(0, 'rgba(255,255,255,' + (0.85 * fade) + ')');
        g.addColorStop(0.25, 'rgba(180,200,255,' + (0.42 * fade) + ')');
        g.addColorStop(1, 'rgba(120,140,255,0)');
        ctx.globalCompositeOperation = 'lighter';
        ctx.strokeStyle = g;
        ctx.lineWidth = 1.7;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(m.x, m.y);
        ctx.lineTo(m.x - m.vx * (len / 320), m.y - m.vy * (len / 320));
        ctx.stroke();
        ctx.globalAlpha = fade * 0.9;
        ctx.drawImage(this.sprites.white[1], m.x - 7, m.y - 7, 14, 14);
        ctx.globalAlpha = 1;
      }
    } else {
      this.meteorCd -= dt;
      if (this.meteorCd <= 0) {
        var startX = this.w * (0.05 + Math.random() * 0.7);
        var speed = 320 + Math.random() * 300;
        var ang = (Math.PI / 180) * (118 + Math.random() * 24);
        this.meteor = {
          x: startX, y: -60 + Math.random() * this.h * 0.35,
          vx: Math.cos(ang) * speed, vy: Math.abs(Math.sin(ang)) * speed,
          life: 0, dur: 0.9 + Math.random() * 0.6
        };
      }
    }
    ctx.globalCompositeOperation = 'source-over';
  };

  Engine.prototype.drawPlanet = function (pl, dt, parX, parY, sc) {
    var ctx = this.ctx;
    var cfg = pl.cfg;
    var g = pl.geom;
    var R = g.R;
    /* 滚动离场（v1.5）：位移系数按「本页滚动总量」现场折算 ——
       滚动到底（sc = scrollMax）时 cy = g.cy + h + 1.35R，星球连辉光、
       行星环一起恰好完全移出视口下缘。短页面系数大、星球下降快，
       长页面则平缓，但每个页面的底部时刻都精确对齐。
       sc 为 0（首屏 / 静态帧）时不做除法，避免 scrollMax 未就绪时出 NaN。 */
    var exitK = (sc > 0 && this.scrollMax)
      ? (this.h + R * 1.35 - g.cy) / this.scrollMax
      : 0;
    var cx = g.cx + parX * 16 * 1.4;
    var cy = g.cy + parY * 12 * 1.4 + sc * exitK;

    pl.phase += cfg.spin * dt * 60 * 0.016;
    if (pl.phase > 1) pl.phase -= 1;
    /* 云层用略快的角速度推进，与地表形成相对流动 */
    pl.cloudPhase += cfg.spin * 1.38 * dt * 60 * 0.016;
    if (pl.cloudPhase > 1) pl.cloudPhase -= 1;
    pl.aurT += dt * 0.42;

    /* 大气外辉光（先画，位于球体之后）。
       强度刻意压低：辉光过强时，球体边缘会裹上一圈浓重的光晕，
       整颗星球会被读成「玻璃球」而不是天体。 */
    var gl = cfg.glow || 0.5;
    ctx.globalCompositeOperation = 'lighter';
    var glow = ctx.createRadialGradient(cx, cy, R * 0.94, cx, cy, R * (1 + 0.30 * gl + 0.07));
    glow.addColorStop(0, 'rgba(' + pl.pal.atmo + ',' + (0.21 * gl + 0.05) + ')');
    glow.addColorStop(0.42, 'rgba(' + pl.pal.atmo + ',' + (0.08 * gl) + ')');
    glow.addColorStop(1, 'rgba(' + pl.pal.atmo + ',0)');
    ctx.fillStyle = glow;
    ctx.beginPath();
    ctx.arc(cx, cy, R * (1 + 0.30 * gl + 0.09), 0, Math.PI * 2);
    ctx.fill();
    ctx.globalCompositeOperation = 'source-over';

    /* 行星环：远半环 */
    var ringDrawn = false;
    var tilt = 0, flat = 0;
    if (cfg.ring) {
      tilt = cfg.ring.tilt + Math.sin(this.t * 0.05) * 0.012;
      flat = cfg.ring.flat + Math.sin(this.t * 0.03) * 0.008;
      this.drawRingHalf(pl, cx, cy, flat, tilt, true);
      ringDrawn = true;
    }

    /* 球体。drawAlpha 由 drawSurface 设定：贴图未就绪时为 1（纯色占位球），
       就绪后由 0 淡入到 1，避免地貌「啪」地一下出现。 */
    pl.drawSurface();
    ctx.globalAlpha = pl.drawAlpha === undefined ? 1 : pl.drawAlpha;
    ctx.drawImage(pl.tmp, cx - R, cy - R, R * 2, R * 2);
    ctx.globalAlpha = 1;

    /* 受光侧边缘轮辉 */
    ctx.globalCompositeOperation = 'lighter';
    var lxa = Math.atan2(cfg.light[1], cfg.light[0]);
    var rim = ctx.createRadialGradient(cx, cy, R * 0.80, cx, cy, R * 1.02);
    rim.addColorStop(0, 'rgba(' + pl.pal.atmo + ',0)');
    rim.addColorStop(1, 'rgba(' + pl.pal.atmo + ',' + (0.24 * (cfg.glow || 0.5) + 0.05) + ')');
    ctx.fillStyle = rim;
    ctx.beginPath();
    ctx.arc(cx, cy, R * 1.02, lxa - 1.5, lxa + 1.5);
    ctx.arc(cx, cy, R * 0.80, lxa + 1.5, lxa - 1.5, true);
    ctx.fill();
    ctx.globalCompositeOperation = 'source-over';

    /* 行星环：近半环 */
    if (ringDrawn) this.drawRingHalf(pl, cx, cy, flat, tilt, false);

    /* 卫星 */
    if (cfg.moons && this.degrade < 3) {
      for (var i = 0; i < cfg.moons.length; i++) {
        var m = cfg.moons[i];
        pl.angle = pl.angle || 0;
        var a = m.angle + this.t * m.speed * (0.6 + i * 0.2);
        var orbit = m.orbit * R;
        var mx = cx + Math.cos(a) * orbit;
        var my = cy + Math.sin(a) * orbit * m.flat;
        var mr = R * m.r * 1.6;
        ctx.globalCompositeOperation = 'lighter';
        var mg = ctx.createRadialGradient(mx - mr * 0.4, my - mr * 0.4, mr * 0.05, mx, my, mr * 1.9);
        mg.addColorStop(0, 'rgba(255,255,255,0.55)');
        mg.addColorStop(0.5, 'rgba(190,200,240,0.22)');
        mg.addColorStop(1, 'rgba(140,160,220,0)');
        ctx.fillStyle = mg;
        ctx.beginPath(); ctx.arc(mx, my, mr * 1.9, 0, Math.PI * 2); ctx.fill();
        ctx.globalCompositeOperation = 'source-over';
        var sd = ctx.createRadialGradient(mx - mr * 0.45, my - mr * 0.45, mr * 0.06, mx, my, mr);
        sd.addColorStop(0, '#FFFFFF');
        sd.addColorStop(0.45, m.color);
        sd.addColorStop(1, 'rgba(28,32,58,1)');
        ctx.fillStyle = sd;
        ctx.beginPath(); ctx.arc(mx, my, mr, 0, Math.PI * 2); ctx.fill();
      }
    }
  };

  /* 绘制半环：ringCv[0] 远侧（星球之后），ringCv[1] 近侧（星球之前） */
  Engine.prototype.drawRingHalf = function (pl, cx, cy, flat, tilt, far) {
    if (!pl.ringCv) return;
    var ctx = this.ctx;
    var size = pl.ringDraw;
    var shimmer = 0.82 + 0.18 * Math.sin(this.t * 0.32 + (far ? 1.4 : 0));
    ctx.save();
    ctx.globalAlpha = (far ? 0.62 : 1) * shimmer;      // 远半环压暗，强化纵深
    ctx.translate(cx, cy);
    ctx.rotate(tilt);
    ctx.scale(1, flat);
    ctx.drawImage(pl.ringCv[far ? 0 : 1], -size / 2, -size / 2, size, size);
    ctx.restore();
    ctx.globalAlpha = 1;
  };

  /* ------------------------------------------------------------------ 生命周期 */
  Engine.prototype.start = function () {
    if (this.running) return;
    this.running = true;
    /* 贴图就绪后补一次静态帧。prefers-reduced-motion 下没有动画循环，
       首次 renderStatic 时贴图多半还没生成完（分片异步），
       若不补这一帧，星球会永远停在纯色占位球上。 */
    var self0 = this;
    TEX_READY_HOOKS.push(function () {
      if (REDUCED && self0.running) self0.renderStatic();
      return self0.running;               // 实例销毁后自动注销
    });
    if (REDUCED) {
      /* 减少动态效果：只渲染一帧静态画面，保留氛围但无动画 */
      this.renderStatic();
      return;
    }
    this.lastTs = 0;
    this.raf = global.requestAnimationFrame(this.frame.bind(this));
  };

  Engine.prototype.renderStatic = function () {
    /* 静态帧：dt 为 0，星野不闪烁、星球不自转 */
    var savedMeteors = this.cfg.meteors;
    this.cfg.meteors = false;
    var self = this;
    var ctx = this.ctx, w = this.w, h = this.h;
    ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    if (this.baseGrad) { ctx.fillStyle = this.baseGrad; ctx.fillRect(0, 0, w, h); }
    ctx.globalCompositeOperation = 'lighter';
    if (this.neb) ctx.drawImage(this.neb, 0, 0, this.neb.width, this.neb.height, 0, 0, w, h);
    for (var i = 0; i < this.stars.length; i++) {
      var st = this.stars[i];
      var size = st.r * 4.2;
      ctx.globalAlpha = st.a * 0.8;
      ctx.drawImage(this.sprites[st.hue][st.r > 1.6 ? 2 : (st.r > 1.0 ? 1 : 0)], st.x - size / 2, st.y - size / 2, size, size);
    }
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    for (var pi = 0; pi < this.pList.length; pi++) {
      this.pList[pi].fade = 1;          // 静态帧没有动画循环，直接给到终值
      this.drawPlanet(this.pList[pi], 0, 0, 0, 0);
    }
    this.cfg.meteors = savedMeteors;
  };

  Engine.prototype.pause = function () {
    if (this.raf) global.cancelAnimationFrame(this.raf);
    this.raf = null;
    this.paused = true;
  };

  Engine.prototype.resume = function () {
    if (this.paused && this.running && !REDUCED) {
      this.paused = false;
      this.lastTs = 0;
      this.raf = global.requestAnimationFrame(this.frame.bind(this));
    }
  };

  Engine.prototype.destroy = function () {
    this.running = false;
    if (this.raf) global.cancelAnimationFrame(this.raf);
    this.unbind();
    if (this.canvas.parentNode) this.canvas.parentNode.removeChild(this.canvas);
  };

  /* ------------------------------------------------------------------ 启动 */
  var instance = null;
  var HQSpace = {
    init: function (scene) {
      if (instance) instance.destroy();
      var name = scene || document.body.getAttribute('data-scene') || 'home';
      try {
        instance = new Engine(name);
        instance.start();
      } catch (e) {
        /* 任何异常都不影响页面内容展示 */
        if (global.console) console.warn('[HQSpace] 场景初始化失败：', e && e.message);
        instance = null;
      }
      return instance;
    },
    destroy: function () {
      if (instance) { instance.destroy(); instance = null; }
    },
    /* 诊断出口：贴图队列进度（探针 / 调试用，不影响正常路径） */
    _stats: function () {
      return { queue: TEX_QUEUE.length, steps: TEX_STEPS, pumping: texPumping };
    }
  };
  global.HQSpace = HQSpace;

  function boot() {
    if (!document.body) return;
    HQSpace.init();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
