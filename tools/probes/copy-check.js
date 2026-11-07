(async function () {
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var opener = document.querySelector('[data-invite-open]');
  if (opener) opener.click();
  await sleep(400);
  var d = document.querySelector('#invite-modal');
  if (d && !d.open) { try { d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
  await sleep(250);

  var btn = document.querySelector('.invite-modal__copy');
  if (!btn) return { error: 'no copy button' };
  var b0 = btn.getBoundingClientRect();

  btn.click();
  await sleep(400);
  var afterClick = { text: btn.textContent.trim(), cls: btn.className, aria: btn.getAttribute('aria-label') };

  await sleep(2200);
  var afterReset = { text: btn.textContent.trim(), cls: btn.className, aria: btn.getAttribute('aria-label') };

  var hint = document.querySelector('.invite-modal__hint');
  var group = document.querySelector('.invite-modal__group');
  return {
    vw: window.innerWidth,
    btn: { w: Math.round(b0.width), h: Math.round(b0.height), visible: b0.width > 0 },
    afterClick: afterClick,
    afterReset: afterReset,
    hint: hint ? { text: hint.textContent.trim(), h: Math.round(hint.getBoundingClientRect().height) } : null,
    groupH: group ? Math.round(group.getBoundingClientRect().height) : null,
    order: Array.prototype.map.call(
      document.querySelectorAll('.invite-modal__group, .invite-modal__hint, .invite-modal__qr'),
      function (e) { return e.className.split(' ')[0] + '@' + Math.round(e.getBoundingClientRect().top); }
    )
  };
})()
