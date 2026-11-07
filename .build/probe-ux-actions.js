(function () {
  var box = document.querySelector('.site-header__actions');
  var out = [];
  [].forEach.call(box.children, function (el) {
    var cs = getComputedStyle(el);
    var b = el.getBoundingClientRect();
    out.push({
      el: el.tagName.toLowerCase() + '.' + (el.className || '').toString().trim().split(/\s+/).join('.'),
      text: (el.textContent || '').trim().slice(0, 12),
      display: cs.display,
      w: +b.width.toFixed(2),
      right: +b.right.toFixed(2)
    });
  });
  return {
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    actions: out
  };
})()
