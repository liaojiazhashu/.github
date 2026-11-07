(async function () {
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var opener = document.querySelector('[data-invite-open]');
  if (opener) opener.click();
  await sleep(500);
  var d = document.querySelector('#invite-modal');
  if (d && !d.open) { try { d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
  await sleep(300);

  var de = document.documentElement;
  var limit = de.clientWidth;
  var bad = [];
  var all = document.querySelectorAll('#invite-modal *');
  for (var i = 0; i < all.length; i++) {
    var el = all[i];
    var r = el.getBoundingClientRect();
    if (r.width === 0) continue;
    if (r.right > limit + 0.5 || r.left < -0.5) {
      bad.push({
        tag: el.tagName.toLowerCase(),
        cls: (el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className) || '',
        left: Math.round(r.left * 10) / 10,
        right: Math.round(r.right * 10) / 10,
        w: Math.round(r.width * 10) / 10
      });
    }
  }
  var card = document.querySelector('.invite-modal__card');
  return {
    vw: window.innerWidth,
    clientW: limit,
    overflowX: de.scrollWidth - limit,
    cardW: card ? Math.round(card.getBoundingClientRect().width) : null,
    offenders: bad.slice(0, 8)
  };
})()
