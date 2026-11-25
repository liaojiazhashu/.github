#!/usr/bin/env node
/* ==========================================================================
   荒启科幻官网 · 一条命令跑完整构建链
   --------------------------------------------------------------------------
   为什么要有这个文件

   改完页面源或样式源，本来要手工按顺序跑五步：

     node tools/build-pages.cjs     用 .build/pages/*.html 生成七个页面
     python tools/subset-fonts.py   按当前文案重新裁字体子集
     node tools/build-css.cjs       合并 .build/css-parts/*.css 成 css/style.css
     python tools/check-fonts.py    逐字比对 cmap，查有没有缺字
     node tools/check-site.cjs      站内自检

   漏掉任何一步都不会报错：
     · 漏 build-pages → 线上还是旧页面，但文件都在，看着挺正常
     · 漏 subset-fonts → 新加的汉字静默回退到系统字体，字重和字形都断了
     · 漏 check-fonts → 连「缺了哪些字」都没人知道
   2026-11-25 那次字体子集落后文案 22 个字，就是这么漏出来的。

   字体内容真的变了的时候，只重裁字体还不够：css 里三个 woff2 的 ?v= 版本号
   必须跟着换，否则老访客按 max-age=604800 继续用缓存里那份旧字体，修复对他们
   七天不生效。这一步由脚本自动做（改 .build/css-parts/01-tokens.css 里的
   ?v=，再重跑 build-css），因为它是「字体一变就必然要做」的动作，留给人做
   迟早会忘。脚本会把改前改后都打出来。

   用法
     npm run build
     node tools/build-all.mjs              # 等价
     node tools/build-all.mjs --skip-fonts # 没改文案、只想重建页面与样式

   退出码
     0 = 全部通过
     1 = 有步骤失败
   ========================================================================== */
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const SKIP_FONTS = process.argv.includes('--skip-fonts');

const FONT_FILES = [
  'assets/fonts/noto-sans-sc-400.woff2',
  'assets/fonts/noto-sans-sc-500.woff2',
  'assets/fonts/noto-sans-sc-700.woff2',
];
const TOKENS_CSS = '.build/css-parts/01-tokens.css';

const useColor = process.stdout.isTTY && !process.env.NO_COLOR;
const C = useColor
  ? { dim: '\u001b[2m', red: '\u001b[31m', green: '\u001b[32m', yellow: '\u001b[33m', cyan: '\u001b[36m', reset: '\u001b[0m' }
  : { dim: '', red: '', green: '', yellow: '', cyan: '', reset: '' };

const say = (msg = '') => console.log(msg);
const step = (msg) => say(`\n${C.cyan}▸ ${msg}${C.reset}`);
const ok = (msg) => say(`  ${C.green}✓${C.reset} ${msg}`);
const note = (msg) => say(`  ${C.dim}${msg}${C.reset}`);
const warn = (msg) => say(`  ${C.yellow}!${C.reset} ${msg}`);
const bad = (msg) => say(`  ${C.red}✗${C.reset} ${msg}`);

/* --------------------------------------------------------------- 字体快照 */
function fontHashes() {
  const out = {};
  for (const rel of FONT_FILES) {
    const abs = path.join(ROOT, rel);
    out[rel] = fs.existsSync(abs)
      ? crypto.createHash('sha256').update(fs.readFileSync(abs)).digest('hex').slice(0, 16)
      : 'missing';
  }
  return out;
}

/* ------------------------------------------------- 字体版本号（?v=）读写 */
function readFontVersion() {
  const abs = path.join(ROOT, TOKENS_CSS);
  if (!fs.existsSync(abs)) return null;
  const m = fs.readFileSync(abs, 'utf8').match(/\?v=([0-9A-Za-z]+)/);
  return m ? m[1] : null;
}

/* 新版本 = 今天日期；同一天内再次变化就顺延后缀 b、c、d…，用完 z 回到纯日期 */
function bumpFontVersion(oldVersion) {
  const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
  const m = /^(\d{8})([a-z]?)$/.exec(oldVersion || '');
  if (!m) return today;
  if (m[1] !== today) return today;
  if (!m[2]) return today + 'b';
  const next = String.fromCharCode(m[2].charCodeAt(0) + 1);
  return /^[a-z]$/.test(next) ? today + next : today + 'b';
}

function writeFontVersion(newVersion) {
  const abs = path.join(ROOT, TOKENS_CSS);
  const src = fs.readFileSync(abs, 'utf8');
  const out = src.replace(/(\?v=)[0-9A-Za-z]+/g, `$1${newVersion}`);
  if (out === src) return 0;
  fs.writeFileSync(abs, out, 'utf8');
  return (out.match(new RegExp(`\\?v=${newVersion}`, 'g')) || []).length;
}

/* ------------------------------------------------------ Python 与依赖探测 */
/* 本机的 `python` 是 D:\AAAAI\_tools\bin\python.cmd 这个 shim（按顺序试几处真解释器）。
   Node 直接调 .cmd 不可靠：不加 shell 会 9009，加了 shell 则 %* 展开丢引号、连
   `import sys` 都返回 1。所以这里绕开 shim，直接按 shim 里的顺序试真解释器。
   换机器时把解释器路径放进 HUANGQI_PYTHON 环境变量即可，下面几条硬编码找不到就跳过。 */
function pythonCandidates() {
  const list = [];
  if (process.env.HUANGQI_PYTHON) list.push(process.env.HUANGQI_PYTHON);
  if (process.platform === 'win32') {
    list.push(
      'C:\\Users\\Administrator\\.workbuddy\\binaries\\python\\python.exe',
      'D:\\workbuddy\\resources\\vendor\\python\\python.exe',
      'C:\\Users\\Administrator\\.dsh\\dsh-runtimes\\dsh-primary-runtime\\dependencies\\python\\python.exe'
    );
  }
  list.push(...(process.platform === 'win32' ? ['python.exe', 'python3.exe', 'py.exe'] : ['python3', 'python']));
  return list;
}

function findPython() {
  const vendored = path.join(ROOT, '.review', 'pylibs');
  const hasVendored = fs.existsSync(vendored);

  for (const bin of pythonCandidates()) {
    const baseEnv = { ...process.env, PYTHONIOENCODING: 'utf-8' };
    if (hasVendored) {
      baseEnv.PYTHONPATH = vendored + (baseEnv.PYTHONPATH ? path.delimiter + baseEnv.PYTHONPATH : '');
    }
    // 只取退出码，不捕获输出：Node 在受限沙箱里用管道 stdio 会 EPERM
    const probe = spawnSync(bin, ['-c', 'import fontTools, brotli'], { env: baseEnv, stdio: 'ignore' });
    if (probe.status === 0) return { bin, env: baseEnv, vendored: hasVendored };
  }
  return null;
}

function run(label, bin, args, env = process.env) {
  step(label);
  const started = Date.now();
  const r = spawnSync(bin, args, { cwd: ROOT, env, stdio: 'inherit' });
  const ms = Date.now() - started;
  if (r.error) {
    bad(`${label} 起不来：${r.error.message}`);
    return false;
  }
  if (r.status !== 0) {
    bad(`${label} 退出码 ${r.status}（${ms} ms）`);
    return false;
  }
  ok(`${label} 通过（${ms} ms）`);
  return true;
}

/* ------------------------------------------------------------------ 主流程
   顺序有讲究：先出页面（决定字符集），再裁字体，字体变了就换版本号，
   最后才合并 css —— 这样一次跑完，css 里的 ?v= 与字体内容必然对齐。 */
say(`${C.dim}荒启科幻官网 · 构建链${C.reset}${SKIP_FONTS ? `${C.dim}（跳过字体）${C.reset}` : ''}`);

const failures = [];
const before = SKIP_FONTS ? null : fontHashes();

if (!run('build-pages', process.execPath, ['tools/build-pages.cjs'])) failures.push('build-pages');

let fontsChanged = false;
let versionFrom = null;
let versionTo = null;

if (!SKIP_FONTS) {
  const py = findPython();
  if (!py) {
    warn('没有可用的 python + fontTools + brotli，字体子集这一步跑不了。');
    note('装好依赖，或把 wheel 解到 .review/pylibs（本仓库现成一份），再跑一次。');
    note('这一步跳过意味着新增汉字会静默回退系统字体，字重与字形都会断。');
    failures.push('subset-fonts（环境缺依赖）');
  } else {
    if (py.vendored) note(`python 依赖来自 .review/pylibs：${py.bin}`);
    if (!run('subset-fonts', py.bin, ['tools/subset-fonts.py'], py.env)) failures.push('subset-fonts');
  }

  const after = fontHashes();
  const changed = FONT_FILES.filter((f) => before[f] !== after[f]);
  fontsChanged = changed.length > 0;

  step('字体内容与版本号');
  if (!fontsChanged) {
    ok('三个 woff2 内容未变，?v= 不用动');
  } else {
    for (const f of changed) say(`  · ${path.basename(f)}  ${before[f]} → ${after[f]}`);
    versionFrom = readFontVersion();
    versionTo = bumpFontVersion(versionFrom);
    const n = writeFontVersion(versionTo);
    if (n === 0) {
      bad(`改不动 ${TOKENS_CSS} 里的 ?v=（文件不存在或格式不符）。请手工改成 ${versionTo} 再跑一次。`);
      failures.push('字体 ?v= 版本号未更新');
    } else {
      ok(`字体变了，?v= 已从 ${versionFrom || '无'} 升到 ${versionTo}（改了 ${n} 处）`);
      note('老访客的 max-age=604800 缓存会因此失效，这是升它的目的。');
    }
  }
}

if (!run('build-css', process.execPath, ['tools/build-css.cjs'])) failures.push('build-css');

if (!SKIP_FONTS && !failures.includes('subset-fonts（环境缺依赖）')) {
  const py = findPython();
  if (py) {
    if (!run('check-fonts', py.bin, ['tools/check-fonts.py'], py.env)) failures.push('check-fonts');
  } else {
    failures.push('check-fonts（环境缺依赖）');
  }
}

if (!run('check-site', process.execPath, ['tools/check-site.cjs'])) failures.push('check-site');

/* ------------------------------------------------------------------ 汇总 */
say('');
if (failures.length === 0) {
  say(`${C.green}构建链全部通过${C.reset}`);
  if (fontsChanged) {
    note(`字体已重裁，?v= = ${versionTo}；提交时把 ${TOKENS_CSS}、assets/fonts/、css/style.css 与七个页面一起带上。`);
  }
  process.exit(0);
}
say(`${C.red}构建链有 ${failures.length} 处未通过：${C.reset}`);
for (const f of failures) say(`  · ${f}`);
process.exit(1);
