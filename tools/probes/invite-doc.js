(async function () {
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var de = document.documentElement;

  var snap = function () {
    return { scrollW: de.scrollWidth, clientW: de.clientWidth, overflow: de.scrollWidth - de.clientWidth };
  };

  var closed = snap();

  var opener = document.querySelector('[data-invite-open]');
  if (opener) opener.click();
  await sleep(500);
  var d = document.querySelector('#invite-modal');
  if (d && !d.open) { try { d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
  await sleep(300);
  var opened = snap();

  // 全文档扫描：右边界超出 clientWidth 的元素
  var limit = de.clientWidth;
  var bad = [];
  var all = document.body.querySelectorAll('*');
  for (var i = 0; i < all.length; i++) {
    var el = all[i];
    var r = el.getBoundingClientRect();
    if (r.width < 1) continue;
    if (r.right > limit + 0.5) {
      bad.push({
        tag: el.tagName.toLowerCase(),
        cls: String(el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className).slice(0, 40),
        left: Math.round(r.left), right: Math.round(r.right), w: Math.round(r.width),
        inModal: !!el.closest('#invite-modal')
      });
    }
  }
  bad.sort(function (a, b) { return b.right - a.right; });

  return {
    vw: window.innerWidth,
    closed: closed,
    opened: opened,
    worst: bad.slice(0, 6)
  };
})()
