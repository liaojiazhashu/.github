(function () {
  function R(sel) {
    var el = document.querySelector(sel);
    if (!el) return null;
    var b = el.getBoundingClientRect();
    return { left: +b.left.toFixed(2), right: +b.right.toFixed(2), w: +b.width.toFixed(2) };
  }
  var de = document.documentElement;
  var t = R('.nav-toggle');
  var a = R('.site-header__actions');
  var brand = R('a.brand');
  var invite = R('.site-header__actions a.btn');
  return {
    clientWidth: de.clientWidth,
    scrollWidth: de.scrollWidth,
    brand: brand,
    actions: a,
    '邀请码按钮': invite,
    '菜单按钮': t,
    '菜单按钮被切掉': t ? +(t.right - de.clientWidth).toFixed(2) : null,
    '按钮可见宽': t ? +(de.clientWidth - t.left).toFixed(2) : null
  };
})()
