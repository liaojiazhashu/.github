/*
 * 邀请码弹窗探针（v2.3.22）
 * --------------------------------------------------------------------------
 * 弹窗是「下载 / 阅读」两个顶栏入口的共同前置，它自己的动作顺序就是口径本身，
 * 所以这里断言的是顺序与指向，不只是「弹窗能打开」：
 *
 *   1. 弹窗是 <dialog>，顶栏两个按钮都能唤起它，关闭按钮有效
 *   2. **第一个动作是「获取邀请码」且指向 contact.html#invite** ——
 *      不能指向 contact.html 首页，更不能指向本页（那等于没指）
 *   3. 三个平台的安装包都还在（客户端可以直接下载，这是允许的）
 *   4. 底部只有「已有邀请码？进入在线阅读」，不再有「查看获取方式」这种含糊入口
 *   5. 文案不得出现「不设门槛 / 不需要邀请码」这类旧口径
 *
 * 用法：
 *   PAGES=index WIDTHS=1440 PROBE_ONLY=1 PROBE_FILE=tools/probes/probe-modal.js node tools/shoot.cjs
 */
(async () => {
  const out = {};
  const dlg = document.getElementById('invite-modal');
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  out['1_弹窗存在'] = !!dlg && dlg.tagName === 'DIALOG';
  if (!dlg) return out;

  const read = document.querySelector('.btn--read');
  const dl = document.querySelector('.btn--download');
  out['2_顶栏阅读按钮'] = read ? read.textContent.trim() : '缺失';
  out['2_顶栏下载按钮'] = dl ? dl.textContent.trim() : '缺失';

  read.click();
  await sleep(120);
  out['3_点击阅读后打开'] = dlg.open;

  const acts = [...dlg.querySelectorAll('.invite-modal__actions a')];
  const first = acts[0];
  out['4_第一个动作'] = first ? first.textContent.trim() : '缺失';
  out['4_第一个动作指向'] = first ? first.getAttribute('href') : '缺失';
  out['4_指向获取邀请码节'] = !!first && first.getAttribute('href') === 'contact.html#invite';
  out['5_动作总数(应为4)'] = acts.length;
  out['5_安装包按钮数(应为3)'] = acts.filter((a) => a.hasAttribute('download')).length;

  const foot = dlg.querySelector('.invite-modal__foot');
  out['6_底部入口'] = foot ? foot.textContent.replace(/\s+/g, ' ').trim() : '缺失';
  /* 阅读站已改为同域绝对路径 /h5/，不再限定 http(s) 外链 */
  out['6_底部指向阅读站'] = !!foot && /^(https?:|\/)/.test(foot.querySelector('a').getAttribute('href'));

  const txt = dlg.textContent.replace(/\s+/g, '');
  out['7_旧口径残留'] = ['不设门槛', '不需要邀请码', '随时可以进行']
    .filter((k) => txt.includes(k)).join(' / ') || '（无）';
  out['7_说到在线阅读需要邀请码'] = txt.includes('在线阅读') && txt.includes('邀请码');
  out['8_二维码尺寸(原生)'] = (() => {
    const img = dlg.querySelector('.invite-modal__qr');
    return img ? `${img.naturalWidth}×${img.naturalHeight}` : '缺失';
  })();

  dlg.querySelector('[data-invite-close]').click();
  await sleep(80);
  out['9_关闭生效'] = !dlg.open;

  return out;
})();
