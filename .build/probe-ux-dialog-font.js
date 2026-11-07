(function () {
  var d = document.getElementById('invite-modal');
  var cs = getComputedStyle(d);
  function fs(sel) { var e = document.querySelector(sel); return e ? getComputedStyle(e).fontSize : null; }
  return {
    root: getComputedStyle(document.documentElement).fontSize,
    body: getComputedStyle(document.body).fontSize,
    'dialog font-size': cs.fontSize,
    'dialog max-height': cs.maxHeight,
    '2em + 6px 折算': (6 + 2 * parseFloat(cs.fontSize)) + 'px',
    '--fs-body': getComputedStyle(document.documentElement).getPropertyValue('--fs-body').trim(),
    '--fs-sm': getComputedStyle(document.documentElement).getPropertyValue('--fs-sm').trim()
  };
})()
