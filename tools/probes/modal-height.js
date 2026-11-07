(async function () {
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var opener = document.querySelector('[data-invite-open]');
  if (opener) opener.click();
  await sleep(400);
  var d = document.querySelector('#invite-modal');
  if (d && !d.open) { try { d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
  await sleep(250);

  var card = document.querySelector('.invite-modal__card');
  var foot = document.querySelector('.invite-modal__foot');
  var cs = getComputedStyle(d);
  var crect = card ? card.getBoundingClientRect() : null;
  var frect = foot ? foot.getBoundingClientRect() : null;

  return {
    vh: window.innerHeight,
    dialogH: Math.round(d.getBoundingClientRect().height),
    dialogOverflowY: cs.overflowY,
    dialogMaxH: cs.maxHeight,
    dialogScrollH: d.scrollHeight,
    dialogClientH: d.clientHeight,
    scrollable: d.scrollHeight > d.clientHeight + 1,
    cardH: crect ? Math.round(crect.height) : null,
    cardBottom: crect ? Math.round(crect.bottom) : null,
    footTop: frect ? Math.round(frect.top) : null,
    footBottom: frect ? Math.round(frect.bottom) : null,
    footBelowViewport: frect ? frect.bottom > window.innerHeight : null
  };
})()
