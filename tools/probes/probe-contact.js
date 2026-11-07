/* ==========================================================================
   联系我们页面探针
   --------------------------------------------------------------------------
   加入练笔的唯一入口是 QQ 群，页面不应再出现任何表单与邮箱咨询线。
   本探针断言：
     · 页面不存在任何 form / 输入控件 / 邮件链接
     · QQ 群号 280660235 在正文中可见
     · 页面头四个统计标签是可核验的事实
     · 四项入群须知卡片齐全
   用法：
     PAGES=contact WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-contact.js node tools/shoot.cjs
   ========================================================================== */
(async () => {
  const out = {};
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => [...document.querySelectorAll(s)];
  const text = document.body.innerText;

  out['1_表单元素数'] = $$('form, input, select, textarea').length + '（应为 0）';
  out['1_mailto 链接数'] = $$('a[href^="mailto:"]').length + '（应为 0）';
  out['1_邮箱残留'] = (text.match(/[\w.+-]+@[\w.-]+/g) || []).join(' | ') || '（无）';

  out['2_QQ群号出现次数'] = (text.match(/280660235/g) || []).length;
  out['2_群名出现'] = text.includes('荒启科幻交流群');
  out['2_入群问题出现'] = text.includes('入群问题');
  out['2_无其他环节声明'] = text.includes('不需要填');

  out['3_页面头统计标签'] = $$('.page-hero__stats .hero__meta-label')
    .map((e) => e.textContent.trim()).join(' / ');

  const cards = $$('.section .card');
  out['4_入群须知卡片数'] = cards.length;
  out['4_卡片标题'] = cards.map((c) => {
    const h = c.querySelector('.card__title');
    return h ? h.textContent.trim() : '';
  }).join(' | ');

  out['5_旧表述残留'] = [
    '可对应的联系身份', '业务咨询线', '处刑', 'Discord', '飞书'
  ].filter((k) => text.includes(k)).join(' / ') || '（无）';

  out['6_FAQ 条目数'] = $$('.acc-item').length;
  out['6_CTA 按钮数'] = $$('.cta-block__actions a').length;

  /* 全站「获取邀请码」都跳到本页 #invite，所以这一节必须是讲「怎么拿到」的：
     标题就叫获取邀请码、有群二维码、有三步流程。 */
  const inv = $('#invite');
  out['7_邀请码节标题'] = inv ? inv.querySelector('.section-head__title').textContent.trim() : '缺失';
  out['7_邀请码节含二维码'] = !!(inv && inv.querySelector('img'));
  out['7_邀请码步骤数(应为3)'] = inv ? inv.querySelectorAll('.step').length : 0;
  /* 旧口径：曾经写「阅读站不设门槛」—— 在线阅读其实必须先注册（要邀请码）。 */
  out['7_旧口径残留'] = ['不设门槛', '不需要邀请码', '浏览阅读站都不需要']
    .filter((k) => text.includes(k)).join(' / ') || '（无）';
  out['7_读完要登录的说法'] = text.includes('打开即需要登录');

  return out;
})()
