# 荒启科幻 · 企业官网

以科幻为核心驱动力的文化科技公司官网。纯静态实现，零依赖、零 CDN、零构建工具，
可直接部署到任意静态托管环境。

---

## 一、快速预览

```bash
# 任选一种方式启动本地静态服务器
python -m http.server 8080
# 或
npx serve .
```

然后访问 `http://localhost:8080/`。

> 直接双击 `index.html` 也能打开，但部分浏览器会因 `file://` 协议限制字体加载，
> 建议使用本地服务器预览。

---

## 二、目录结构

```
huangqi-scifi/
├── index.html              首页
├── about.html              关于荒启（品牌全景）
├── community.html          板块一 · 科幻社群
├── tools.html              板块二 · 数字化产品开发（社区 / 教育 / AI / 定制）
├── library.html            板块三 · 银河图书馆（四件产出：关键词索引 / 剧情分析 / 建议 / 修改方向 + 馆藏）
├── contact.html            联系与加入（QQ 群直接入群，无表单）
│
├── css/
│   └── style.css           唯一样式文件（约 3000 行，由 .build/css-parts 合并，勿手改）
│
├── js/
│   ├── main.js             站点通用交互（导航 / 滚动入场 / 数字滚动 / 折叠 / 标签页 / 邀请码弹窗）
│   ├── works-data.js       作品结构化数据（银河图书馆「馆藏」数据源）
│   └── library.js          银河图书馆卡片渲染
│
├── assets/
│   ├── fonts/              自托管字体（woff2，已子集化）
│   ├── app/                客户端占位安装包（exe / apk / ipa，正式包替换同名文件）
│   └── qq/                 QQ 群二维码
│   └── logo/               Logo 全套与 favicon
│
├── docs/
│   └── 荒启科幻-官网规划文档.md
│
└── tools/                  构建、验证与处理脚本（不参与线上运行）
    ├── build-css.cjs       由 .build/css-parts 合并生成 css/style.css
    ├── build-pages.cjs     由页面主体批量生成完整 HTML 外壳
    ├── check-site.cjs      站点自检（结构 / 无障碍 / 资源 / CSS 变量 / 脚本顺序）
    ├── check-fonts.py      字形覆盖检查：读 woff2 的 cmap，与站点全部文本逐字比对
    ├── shoot.cjs           无头浏览器截图与页面探针（依赖系统安装的 Edge）
    ├── measure-scene.cjs   渲染结果的亮度分位与正文对比度测量
    ├── prepare_logo.py     Logo 去底透明化与 favicon 生成
    ├── subset-fonts.py     字体子集化
    ├── scale-ship-strokes.cjs       首屏飞船缩尺后的描边反向补偿（幂等）
    ├── migrate-old-svg-palette.cjs  内联 SVG 旧色批量令牌化（幂等）
    ├── audit-css.cjs       反向审计：只读，列出未被任何页面 / 脚本引用的类
    ├── prune-dead-css.cjs  按 DEAD 白名单删除死 CSS 规则（幂等）
    ├── render-space.cjs    Node 端渲染特效引擎用于视觉验收
    └── probes/             页面探针脚本（功能验证用）
```

`.build/` 为构建中间目录，包含页面主体（`.build/pages/*.html`）、
**CSS 分区源（`.build/css-parts/*.css`，样式唯一可改的地方）** 与字体原始文件，
**不参与线上部署**。

---

## 三、设计约定（硬性要求）

| 项 | 约定 |
| --- | --- |
| 页面数量 | 多 HTML 页面，内容详尽 |
| 样式组织 | **全部 CSS 集中在 `css/style.css` 单一文件** |
| 图标 | **全站禁止 emoji**，所有图标为自绘内联 SVG |
| 字体 | 使用专业字体库（Noto Sans SC / Inter / Orbitron / JetBrains Mono）并**本地化自托管**，无任何 CDN 引用 |
| 响应式 | 全分辨率兼容，320px ~ 4K 共 10 档断点 |
| 依赖 | 零第三方运行时依赖，零构建工具 |

## 四、品牌视觉规范（v2.0「GEO 冷峻几何」）

```
墨黑    #0B0E14   品牌基色（页面唯一底色，纯色）
冰青    #4FC1E0   主强调色（按钮 / 当前项 / 图标 / 几何锚点）
雾蓝    #8B9ED8   次强调色（次级标记 / 统计单位 / 第二几何构成）
发丝线  #242E3E   第一视觉语言（边框 / 分区线 / 网格）
```

v2.0 起整站美术方向为「GEO 冷峻几何」：**几何线条 + 色块 + 留白**，全站去渐变化。

- 页面底为纯色 `#0B0E14`，不存在任何背景渐变或位图
- 文字层级：主标题 `#EDF2F8` / 正文 `#C3CDDA` / 辅助 `#9AA6B8` / 弱化 `#6E7A8E`
- 内容层：卡片 / 面板 / 表格 / CTA 全部为平面墨面色块（`--surface-*`）+ 1px 发丝边框，
  hover 用边框提亮与角标记号表达，不再使用玻璃拟态
- 背景层（见下节）全部为 CSS / 内联 SVG 矢量，320px ~ 4K 任意分辨率边缘锐利
- 字体栈：`Inter → Noto Sans SC`（正文）、`Orbitron`（数字与英文展示）、`JetBrains Mono`（代码与编号）

**颜色只允许来自令牌（v2.3.23）**。任何「洗色 / 反色 / 幽灵元素」都必须走令牌，
因为同一个 `rgba(255,255,255,.06)` 在夜间是提亮、在白天等于不存在：

| 令牌 | 用途 | 夜间 | 白天 |
| --- | --- | --- | --- |
| `--wash-1` / `--wash-2` | 悬停洗色、chip 底、按钮浅底 | `#fff` 3% / 6% | `#16232F` 4% / 7% |
| `--track` | 条形进度轨道、底槽 | `#fff` 7% | `#16232F` 10% |
| `--on-accent` | 压在冰青实色上的字 | 墨黑 | 纯白 |
| `--ghost-1` / `--ghost-2` | 卡片幽灵序号（默认 / hover） | 冰青 16% / 28% | 冰青 22% / 34% |

> 幽灵序号用的冰青 alpha 要跟随 `--accent-1` 一起改：白天主色压深到 `#0A6580` 后，
> 仍写旧的 `rgba(23,137,173,…)` 会偏亮偏绿，和全站强调色对不上。

禁止再写 `rgba(255,255,255,…)`、`rgba(79,193,224,…)` 这类硬编码 alpha；
`#fff` 压在强调色上必须换成 `--on-accent`，否则白天模式会掉到 2.1:1。


---

## 五、背景几何层

v2.0 起 **星球 Canvas 引擎整站下线**（`js/space.js` 保留在仓库中但不再被任何页面加载）。
历次迭代中程序化星球在低端设备上反复出现降档换样式、贴图时序竞态等问题，
且程序化贴图本质是位图，放大后细节发糊，无法满足 4K 锐利度要求。

v2.1 将背景改写为**星图样式**——用真实星图的语法（星点、星座连线、坐标十字、
刻度弧、轨道椭圆、天区/轨域高亮色块）表达「科学探索 · 幻想」的气质，全部矢量：

- **双层网格** `.bg-grid`：84px 细网格 + 336px 主网格（坐标纸），左上最清晰、向右下淡出
- **星点层** `.bg-stars`：16 枚 1–2px CSS 星点（白 / 雾蓝交替），恒定背景星野
- **全幅星座图** `.bg-constellation`（v2.2 重绘，v2.3 起去星名）：**东亚夏季真实星空**
  的极简几何再现 —— 夏季大三角（织女星 / 牛郎星 / 天津四）、天鹅座北十字、
  天鹰扁担双星、天蝎座（心宿二红色超巨星）、人马座茶壶、北斗七星、仙后座 W，
  半透明银河带自东北流向南部天区；只有星点与连线，不显示星座名字
- **观测坐标图**（左上 SVG）：扇形天区高亮 + 四星连线 + 刻度弧 + 坐标十字
- **轨道图**（右中 SVG）：中心恒星 + 十字准线、双轨道圆、倾斜轨道椭圆、
  在轨星点 + 卫星、扇形轨域高亮
- **上下压暗** `.bg-vignette`：仅顶部 / 底部的轻线性渐变，无径向暗角
- **背景全程可见（v2.2）**：tinted 区块与页脚改为半透明令牌（`--tinted-bg` /
  `--footer-bg`），任何滚动位置都能透过内容层看到部分星图

首屏右侧为**原创深空探测船「长针」**（v2.3.22，`.hero-ship`，≥1024px；窄屏隐藏）：
纯几何线稿的探测器构型，六个结构件沿一条中轴排开 —— **六棱面探针头**
（前端细针 + 三层同心传感眼 + 横肋与 X 分面）、**细颈**、**梭形主舱**
（中脊亮线 + 七道横肋）、一对**后掠稳定翼**（翼面平涂 + 两道肋 + 翼梁）、
**尾管**接环心的**核心舱**、尾部**环形推进器**（外缘 / 内壁 / 分段包边 /
evenodd 挖空带面四层叠压，环上八枚舱节点 + X 形四辐条 + 三枚信标绕环心自转）。
**尾焰从环心穿出**，向后拉出四层色阶锥体 —— 这是整台造型的记忆点。
立体感不靠渐变（全站禁渐变），靠三条：① 整机 `rotate(-9°)` + 纵向压扁 0.84
形成俯视透视；② 平涂色阶分光面 —— 每段壳体＝「顶面 `currentColor` 0.07 平涂
+ 下缘三层同位暗边（31.8/17.7/7.8 单位）」，读作上亮下暗的柱体；③ 环带填一层极淡带面，
压扁后自带「环有宽度」的证据。
动画：整机 18s 横纵漂移（5/4px）、环形推进器 22s 自转、尾焰四层错相 `scaleX` 吞吐
（2.4/1.7/1.15/0.78s），三条流线 `stroke-dashoffset` 向尾部流动、舰首传感眼
2.6s 呼吸、环上三枚信标 3.2s 闪烁；`prefers-reduced-motion` 下全部停用。
垂直 33%，尺寸 `clamp(85px, 7vw, 150px)` —— 1440 视口下整台 101×35px，
只占视口宽 7%；船身本体约 80px，与日面屏幕直径 1040px 是 **13:1**
（面积比 170:1）。**这是刻意的「渺小」**：三次缩尺（26vw → 14vw → 7vw）
每次都同步把 SVG 内描边与标识件半径按同一倍率反向补偿
（`tools/scale-ship-strokes.cjs`，×1.5 之后 ×2），渲染线宽仍落在 1.0px 左右 ——
船变小了，但探针头 / 主舱横肋 / 环形推进器三段仍读得出。
**只改 CSS 宽度不跑那个脚本，船不是变小而是变糊。**
线色夜间 `--text-1` × 0.90、白天 `--text-1` × 0.96（浅黄太阳上读作逆光剪影），
强调件（传感眼 / 信标 / 核心舱口）用 `--accent-1`，尾焰用 `--warp-flame` /
`--warp-core`（昼夜双值，色阶方向恒为「芯亮边深」）。

### 昼夜双模式（v2.2，白天模式 v2.3 重构为宇宙视角）

页头太阳 / 月亮按钮切换，`localStorage` 持久化，首帧内联脚本应用（无闪色）：

| | 夜间模式（默认） | 白天模式 |
| --- | --- | --- |
| 底色 | 墨黑 `#0B0E14` | 浅天青 `#C9DDEC` |
| 背景 | 夏季星空星图 | 宇宙视角昼景：**直径超出画幅的巨物太阳**（平涂圆盘 + 巨型同心环，右中出画）、锐利斜切色块、**黯淡月牙**（左下，与太阳分居两端）、几点黯淡星光 |
| 文字 | 浅色四级（正文 ≈ 9.5:1） | 深藏青四级（正文 > 7:1） |
| 面板 | 墨面色块 | 白色系色块 |
| logo | 白色版 | 深色版（`logo-full-dark.png`） |

> **历史经验保留**：`js/space.js` 与 `tools/probes/retired/` 中的滚动 / 贴图探针
> 记录了 v1.3–v1.6 程序化渲染管线的全部踩坑（值噪声 vs 梯度噪声、密度映射平滑化、
> rIC 环境钳制、降档换内容等），如需重启「拟真星球」路线请先阅读归档。

## 六、本地开发流程

### 1. 修改页面内容

页面主体位于 `.build/pages/*.html`（仅含 `<main>` 内容），
导航、页脚、抽屉、脚本引用由 `tools/build-pages.cjs` 统一套壳生成，
以保证 6 个页面外壳完全一致。

```bash
# 修改 .build/pages/xxx.html 后重新生成全部页面
node tools/build-pages.cjs
```

图标通过占位符引用，避免重复书写 SVG：

```html
{{ico:arrowRight:ico-18}}                 <!-- 单个类名 -->
{{ico:chevronRight:ico-18 breadcrumb__sep}}  <!-- 多个类名 -->
```

可用图标名见 `tools/build-pages.cjs` 中的 `I` 对象。
构建时会自动校验是否有未展开的占位符，发现即报错。

> **内容写作约定（v2.3.19 起）**：每个事实只在一个页面拥有「完整表述权」，
> 其它页面只指路，不复述。同一页面内，总览区块讲「解决什么问题」，
> 细则区块讲「具体怎么做」，两者不得互相重述。
> 新增内容前先搜一遍全站是否已经讲过——`docs/荒启科幻-官网规划文档.md` 第十四节是
> 规则的唯一事实来源，工具页「默认五维」等配置项必须与规则口径逐字一致。
>
> **主体名称（v2.3.20）**：法律主体是「成都荒启文化传播有限公司」，对外品牌是「荒启科幻」。
> 站点标题、导航、logo、社群称谓一律用品牌名；公司全称只出现在页脚版权行、
> 关于页「主体信息」与联系页「运营主体」三处，不进入正文叙事。

### 2. 修改样式

样式由 8 个分区源文件合并为单文件。**改样式请改 `.build/css-parts/` 下的分区文件，
不要直接编辑 `css/style.css`**（下次合并会覆盖）：

```bash
node tools/build-css.cjs
```

| 分区 | 内容 |
| --- | --- |
| 01 | 设计令牌（颜色、字体、间距、圆角、缓动、层级） |
| 02 | 字体声明与基础重置 |
| 03 | 基础排版与通用工具类 |
| 04 | 布局骨架与背景层 |
| 05 | 组件库 |
| 06 | 页面级样式 |
| 07 | 响应式断点（320px ~ 4K） |
| 08 | 动效与降级 |

### 3. 重新生成字体子集

页面文案新增了字符集之外的汉字后，必须重新子集化，否则新字会回退到系统字体。

```bash
python tools/subset-fonts.py
```

脚本会扫描 `.build/pages`、站点 HTML、`css`、`js`、`docs` 下的全部文本，
汇总字符集后裁剪 Noto Sans SC 三个字重，输出到 `assets/fonts/`。

子集化完**必须**再跑一次字形覆盖检查：

```bash
python tools/check-fonts.py
```

它直接读 `assets/fonts/noto-sans-sc-400.woff2` 的 cmap，与站点全部文本逐字比对
（跳过 HTML/CSS 注释，保留 `content: '…'` 里的真实文案），缺字时打印
`字 U+XXXX 来源文件` 并以退出码 1 结束。

> **为什么不在浏览器里查**：`document.fonts.check()` 只知道「字族加载成功」，
> 不知道「某个字有没有字形」；缺字会静默回退系统字体，页面上看起来只是「字体变了」。
> Canvas 像素比对这个思路也走不通 —— 不同字族缺字时落到**不同**的系统回退字体，
> 实测「龘」在 Noto Sans SC 与雅黑下的渲染结果本就不同，「两个都回退」会被判成「都有字形」。
> 唯一可靠的证据是字体文件自己的 cmap。

### 4. 特效引擎视觉验收

```bash
# 渲染全部场景的静态帧为 PNG，用于对比检查
FRAMES=70 node tools/render-space.cjs

# 单场景 + 亮度体检：输出平均色、亮度分位与正文色的 WCAG 对比度
node tools/render-space.cjs home 1440 810
node tools/measure-scene.cjs home          # 默认取左上角（首页星云最亮处）
node tools/measure-scene.cjs works 0 0 1 1 # 自定义取样框 x0 y0 x1 y1，全图为 0 0 1 1
```

依赖 `@napi-rs/canvas`（仅开发用，不参与线上）。

**不要靠肉眼在缩略图上判断「亮不亮」** —— 缩略图缩放、屏幕伽马、看图时的环境光
都会骗人。判断背光明暗请用 `measure-scene.cjs` 给出的 p50 / p95 分位数。

> 渲染脚本的 `BG_COLOR` 环境变量**实际不起作用**：主画布是不透明的（星云贴图 alpha
> 全程 255），预合成的底色会被完整盖住。默认底色已改为页面背景渐变的中间停靠点
> `#1B2550`，只是为了让离线分位数与实拍页面口径一致。

### 5. 站点自检

```bash
node tools/check-site.cjs
```

每一项都可自动验证，出错时退出码为 1，适合接入提交前检查：

| 分类 | 检查内容 |
| --- | --- |
| HTML | `lang` / `title` / `description` / `viewport`、`<h1>` 唯一、`id` 不重复、标签配对、`<button>` 必须带 `type`、`<img>` 必须带 `alt` |
| 链接 | 本地文件是否存在、页内与跨页锚点是否存在 |
| 资源 | 页面与 CSS 引用的字体、图片、脚本、样式是否齐全 |
| 规范 | 禁用 emoji、禁止外部请求、中文正文不得使用英文直引号 |
| 样式 | 花括号平衡、`var()` 引用的令牌是否已定义、运行时变量是否带回退值 |
| 脚本 | 加载顺序（`main.js` 必须早于页面级脚本）、依赖文件是否存在 |

### 6. 浏览器截图与页面探针

```bash
# 全页截图（默认 1140 / 1440 / 375 三档宽度）
node tools/shoot.cjs

# 指定页面与宽度
PAGES=index,works WIDTHS=1440 node tools/shoot.cjs

# 视口截图（固定背景层会正确渲染，适合检查首屏与滚动区块）
PAGES=index WIDTHS=1440 MODE=viewport SCROLLS=0,900,1800 node tools/shoot.cjs

# 高 DPR 截图，用于确认字体与细节渲染
PAGES=index WIDTHS=375 DPR=3 MODE=viewport SCROLLS=150 node tools/shoot.cjs

# 页面探针：在真实浏览器里执行断言脚本并打印结果
PAGES=library WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-library.js node tools/shoot.cjs

# 首屏满屏断言：首屏底边 / 下一区块顶边都必须 ≥ 视口高（v2.3.8 起每页正好覆盖一屏）
PAGES=index,about,community,tools,library,contact WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-viewport-fit.js node tools/shoot.cjs

# 探测器探针：六结构件 / 环形推进器（节点+辐条+信标）自转 / 尾焰穿环 + 错相吞吐 / 传感眼呼吸
# 含「渺小度」断言：整台宽度不得超过视口宽的 9%（v2.3.22 起 7vw = 101px @1440）
MOTION=1 PAGES=index WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-ship.js node tools/shoot.cjs

# 邀请码弹窗探针：第一个动作必须是「获取邀请码 → contact.html#invite」，
# 三个安装包仍在，底部只留「已有邀请码？进入在线阅读」，旧口径零残留
PAGES=index,contact WIDTHS=1440,375 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-modal.js node tools/shoot.cjs

# 对比度探针：逐元素算 WCAG 比值（正文 ≥4.5:1、大字 ≥3:1），两主题都要跑
# THEME 会在导航前预置 localStorage，所以昼夜各跑一次即可
PAGES=index,about,community,tools,library,contact WIDTHS=1440 THEME=night PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-contrast.js node tools/shoot.cjs
PAGES=index,about,community,tools,library,contact WIDTHS=1440 THEME=day PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-contrast.js node tools/shoot.cjs

# 字体探针：字面数 / 加载失败 / 未加载 / 各字重可用性 / 正文第二字族是否为 Noto Sans SC
# / svg text 里带中文却缺 CJK 回退的数量 / <11px 文本数量
PAGES=index,community,tools,library WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-fonts.js node tools/shoot.cjs

# 缩尺补偿：改过 .hero-ship 的 CSS 宽度后必须按同一倍率补一次笔画
# node tools/scale-ship-strokes.cjs <倍率> <标记>   例：2 v2322

# 单元素放大截图：看图标 / SVG 线稿细节（MODE=el，EL 选元素，EL_SCALE 放大倍数）
PAGES=index WIDTHS=1440 MODE=el EL=.hero-ship EL_SCALE=3 THEME=night MOTION=1 node tools/shoot.cjs

# 死代码清理：删除 HTML/JS 均无引用的 CSS 规则（幂等，可重复执行）
node tools/prune-dead-css.cjs

# 反向审计：只读，列出 CSS 里没被任何页面/脚本引用的类（清理前先看这份清单）
node tools/audit-css.cjs

# MOTION=1 关闭「减弱动效」仿真 —— 动画类断言（环自转 / 尾焰吞吐 / 传感眼呼吸）必须加。
# 不加时 shoot.cjs 会注入 prefers-reduced-motion: reduce，动画恒停，断言会「假通过」。
```

实现说明：脚本用 Node 内置 `http` 起本地静态服务器，用系统已安装的 Microsoft Edge
以 `--headless=new` 启动，通过 CDP（Node 22 内置 `WebSocket`）驱动，无需安装
Playwright / Puppeteer。截图前会等待 `document.fonts.ready`、注入样式强制展开
入场动画，并以 `prefers-reduced-motion: reduce` 运行，保证画面稳定。
输出目录 `.build/shots/`。

> **截图为什么还要关掉 `content-visibility`**：v2.3.19 给 `.section` 加了
> `content-visibility: auto`（长页首屏只渲染视口内区块）。它会让「还没滚到」的区块
> 在整页截图里留白，而整页截图的验收意义就是看全篇。注入样式里已带
> `.section{content-visibility:visible!important}`，只作用于截图/探针，页面本身不动。

> **环境注意**：Windows 下若 `HTTPS_PROXY` 与 `https_proxy` 同时存在，Edge 会因
> 环境块重复键而静默启动失败。`shoot.cjs` 已自动为子进程清理代理变量。

---

## 七、响应式断点

| 断点 | 目标设备 |
| --- | --- |
| ≤ 359px | 超小屏手机 |
| ≤ 479px | 手机 |
| ≤ 767px | 大屏手机 / 小尺寸竖屏平板 |
| ≤ 1023px | 平板竖屏 |
| ≤ 1279px | 平板横屏 / 小笔记本 |
| ≤ 1439px | 笔记本 |
| ≥ 1440px | 桌面 |
| ≥ 1680px | 大屏桌面 |
| ≥ 1920px | 2K 屏 |
| ≥ 2560px | 4K 屏 |

另有横屏矮屏（`max-height`）与打印（`print`）两组特殊规则。

**三列以上的数据表在竖屏必须堆叠**。`.data-table` 基础规则带 `min-width: 620px`
（怕列挤成一团），到 375px 屏幕上就成了横向滚动，第三列直接看不见。
给表格加 `class="data-table data-table--stack"` 后，`07-responsive.css` 会把它拆成
「一行一条」：隐藏 `thead`、每行补分隔线、首列变标题、末列降为辅助色，
第三列用 `::before { content: '例：' }` 补回被表头带走的语义。

> 两个容易踩的点：① 堆叠规则必须写在 `.data-table th, td { padding }` **之后**，
> 同特异度靠顺序取胜，写在前面会被覆盖（表现是「改了没反应」）；
> ② 光有 `display: block` 不够，还要 `.data-table--stack { min-width: 0 }` 把
> 基础规则的 `min-width` 压掉，否则容器照样撑到 620px。

---

## 八、无障碍与降级

- 语义化标签与 ARIA 标注（`aria-expanded` / `aria-selected` / `aria-live` / `role="tab"` 等）
- 全站键盘可达，`:focus-visible` 明确聚焦样式
- 跳转到主要内容链接（`skip-link`）
- `prefers-reduced-motion` 下关闭动效并静态化背景特效
- 装饰性元素统一 `aria-hidden="true"`
- 禁用 JS 时内容仍可读；作品库保留静态首屏条目；背景回落为 CSS 渐变，不出现空白
- **对比度可验证**：`probe-contrast.js` 逐元素计算 WCAG 比值，两个主题 × 六个页面必须 0 不达标。
  它会把 `aria-hidden="true"` 的装饰内容与 `-webkit-text-fill-color: transparent` 的渐变裁切字跳过
  （后者没有真实前景色，硬算必然报 1:1）
- **字形覆盖可验证**：`check-fonts.py` 保证站点用到的每一个字都在自托管字体里，
  不存在「看起来正常、其实是系统雅黑顶上的」字符

---

## 九、部署

将以下内容上传到静态托管即可，站点本身无需任何服务端：

```
index.html  about.html  community.html  tools.html  library.html  contact.html
css/  js/  assets/  favicon.ico
```

`docs/`、`tools/`、`.build/`、`README.md` 不需要上传。

### 同域子目录（v2.3.25 起）

页面里有三类「站内绝对路径」，指向站点根目录下的兄弟目录，
**不在上面这份导出清单里**，部署时必须一起就位，否则按钮是死链：

| 路径 | 内容 | 引用位置 |
| --- | --- | --- |
| `/h5/` | 在线阅读站（H5，hash 路由），需邀请码登录 | 顶栏「在线阅读」、邀请码弹窗底部、图书馆与联系页 CTA |
| `/dt/荒启科幻.exe` · `/dt/Huangqisf.apk` · `/dt/huangqisf.mobileconfig` | 三平台客户端安装包 | 邀请码弹窗、联系页下载区 |

> iOS 走的是 `.mobileconfig` 描述文件（引导安装），按钮上的扩展名标签仍显示 `.ipa` ——
> 与线上一致，属于历史文案，改的时候两处一起改（`build-pages.cjs` 的 `APP_DOWNLOAD`）。

地址集中写在 `tools/build-pages.cjs` 顶部：`READER_URL = '/h5/'`。
页面正文里引用它请写 `{{READER_URL}}` 占位符，**不要硬编码**，改地址只需改常量。

### 页脚备案

页脚版权行带 ICP 备案号 `蜀ICP备2025169184号-1`，链接到 `https://beian.miit.gov.cn/`。
`check-site` 的外链白名单已包含该域名；换成别的备案号时改 `build-pages.cjs` 页脚模板即可。

### 部署环境附带文件

| 文件 | 作用 | 是否属于站点 |
| --- | --- | --- |
| `web.json` / `web.js` | 客户端版本与 H5 地址配置（`h5Url` 指向 `/h5/#/`） | 否，随客户端分发 |
| `.user.ini` | 托管面板（PHP）的 `open_basedir` 限制 | 否，仅服务器环境用 |
| `logo*.png` / `logo.jpg` / `start.png` | 原始素材，未被页面引用 | 否，归档备查 |

### 上线前需替换的示例信息

- `js/works-data.js` 中的作品数据（字段结构可直接沿用）
- `assets/app/` 下的三份安装包只是文本占位文件，**页面已不再引用它们**（改指 `/dt/`），
  正式包里可以直接删掉这个目录

> 联系方式**不需要替换**：v1.4 已改为规则 4.1 规定的两个真实渠道 ——
> 交流 QQ 群 `280660235`、微信公众号「荒启科幻」。原先的 `*.example` 占位邮箱
> 与全部表单已删除，加入社群只有「申请加群 + 回答入群问题」一个入口。

---

## 十、已知取舍

- **作品库为示例数据**：18 条条目覆盖第 21–25 期、16 种子类型，
  字段结构与真实归档一致，替换 `js/works-data.js` 即可接入正式数据。
- **加入社群无表单**：加入方式即「申请加 QQ 群 280660235 + 回答入群问题」，
  页面不提供表单与邮箱入口。这不是功能缺失，而是与规则 4.1 保持一致 ——
  表单会把一条一步到位的路径包装成一个并不存在的流程。
- **字体子集化范围**：仅包含站点实际使用的字符（含 ASCII 与常用符号）。
  这是加载体积与完整性之间的取舍，新增文案后请重新运行子集化脚本。
