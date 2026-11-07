/*
 * 银河图书馆板块页探针（v2.5）
 *   这一页的职责：讲清这座馆「为了把什么做成而设计」，以及进去之后能拿到什么。
 *   方法细节与产出样式仍在馆内页面，官网不抄第二份 —— 抄了必漂，漂的那份没人维护。
 *
 *   本探针守六件事：
 *     1. 入口到位：所有 /library 链接是新窗口 + noopener，且不少于三处
 *     2. **全页零「邀请码」**：馆内阅读不需要登录，要求是「不设邀请码按钮」，
 *        出现这三个字就是口径倒退（页脚也只写「加入社群」）
 *     3. 免登录要写明，不能只靠读者猜
 *     4. 关键词口径是「5 是上限，不是配额」
 *     5. 影视化只写文字，页面里不得出现概念图/影视化配图
 *     6. 方法论词与旧口径零回流；无占位符
 *
 * 用法：PROBE_FILE=tools/probes/probe-library.js node tools/shoot.cjs
 */
(async () => {
  const out = {};
  const q = (s) => document.querySelector(s);
  const qa = (s) => [...document.querySelectorAll(s)];
  const txt = document.body.textContent;

  out['1_页面'] = document.body.getAttribute('data-scene');

  /* ---- 1. 入口 ---- */
  const entries = qa('a[href]').filter((a) => /\/library(\/|$|\?)/.test(a.getAttribute('href')));
  out['2_进馆入口数'] = entries.length + '（至少 3：页头 / 正文入口卡 / 底部 CTA）';
  out['3_入口地址'] = [...new Set(entries.map((a) => a.getAttribute('href')))].join(' , ') || '无';
  out['4_入口新窗口'] = entries.filter((a) => a.target === '_blank').length + ' / ' + entries.length;
  out['5_入口带noopener'] = entries.filter((a) => (a.getAttribute('rel') || '').indexOf('noopener') >= 0).length + ' / ' + entries.length;

  /* ---- 2. 不设邀请码：这一页连「邀请码」三个字都不该出现 ---- */
  out['6_邀请码字样(应为0)'] = (txt.match(/邀请码/g) || []).length;
  out['7_邀请码按钮(应为0)'] = qa('a,button').filter((el) => /邀请码/.test(el.textContent)).length;
  out['8_免登录有交代'] = /免登录|不需要账号|不用注册/.test(txt);

  /* ---- 3. 关键词口径 ---- */
  out['9_五词是上限'] = /上限/.test(txt) && /5|五/.test(txt);
  out['10_不是配额'] = /不是配额|不是必须/.test(txt);
  out['11_宁缺勿滥'] = /宁缺|抽不出/.test(txt);

  /* ---- 4. 影视化只有文字 ---- */
  out['12_影视化有讲'] = /影视化|拍成片子|画面/.test(txt);
  out['13_正文图片数(应为0)'] = qa('.page-hero img, .section img, .card img, figure img').length;

  /* ---- 5. 方法论不进官网 ---- */
  const methodWords = [
    '五类索引词', '四件产出', '六步做完', '六步流程',
    '题材母题', '情感基调', '社会制度',
    '六维度', '知识图谱', '报告结构', '作品库',
  ];
  out['14_方法论残留'] = methodWords.filter((w) => txt.includes(w)).join(' , ') || '无';

  /* ---- 6. 外链与占位符 ---- */
  const blanks = qa('a[target="_blank"]');
  out['15_外链总数'] = blanks.length;
  out['16_rel异常(应为0)'] = blanks.filter((a) => (a.getAttribute('rel') || '').indexOf('noopener') < 0).length;
  out['17_占位符残留'] = /\{\{/.test(document.documentElement.outerHTML) ? '有' : '无';
  out['18_页面标题'] = (q('h1') || {}).textContent || '缺失';

  return out;
})();
