(async function () {
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var opener = document.querySelector('[data-invite-open]');
  if (opener) opener.click();
  await sleep(400);
  var d = document.querySelector('#invite-modal');
  if (d && !d.open) { try { d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
  await sleep(250);

  var qr = document.querySelector('.invite-modal__qr');
  var hint = document.querySelector('.invite-modal__hint');

  var snap = function (tag) {
    return {
      tag: tag,
      dialogScrollH: d.scrollHeight,
      dialogClientH: d.clientHeight,
      needScroll: Math.max(0, d.scrollHeight - d.clientHeight),
      cardH: Math.round(document.querySelector('.invite-modal__card').getBoundingClientRect().height),
      qrW: qr ? Math.round(qr.getBoundingClientRect().width) : null,
      hintH: hint ? Math.round(hint.getBoundingClientRect().height) : null
    };
  };

  var out = [snap('现状')];

  if (qr) { qr.style.width = '160px'; qr.style.height = '160px'; }
  await sleep(150);
  out.push(snap('二维码 160'));

  return { vw: window.innerWidth, vh: window.innerHeight, steps: out };
})()
