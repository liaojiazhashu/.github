(async function () {
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var opener = document.querySelector('[data-invite-open]');
  if (opener) opener.click();
  await sleep(500);

  var d = document.querySelector('#invite-modal');
  if (!d) return { modal: null, opener: !!opener };
  if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
  await sleep(300);

  var qr = document.querySelector('.invite-modal__qr');
  var group = document.querySelector('.invite-modal__group');
  var mono = group && group.querySelector('.mono');
  var card = document.querySelector('.invite-modal__card');
  var actions = document.querySelector('.invite-modal__actions');
  var cb = card ? card.getBoundingClientRect() : null;
  var qb = qr ? qr.getBoundingClientRect() : null;

  return {
    vw: window.innerWidth,
    open: d.open,
    card: cb ? { w: Math.round(cb.width), h: Math.round(cb.height) } : null,
    qr: qb ? { w: Math.round(qb.width), h: Math.round(qb.height) } : null,
    groupText: group ? group.textContent.trim() : null,
    monoUserSelect: mono ? getComputedStyle(mono).userSelect : null,
    hasCopyBtn: !!document.querySelector('[data-copy], .invite-modal__copy'),
    overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    cardBottom: cb ? Math.round(cb.bottom) : null,
    vh: window.innerHeight,
    actionsText: actions ? actions.textContent.trim().replace(/\s+/g, ' ') : null
  };
})()
