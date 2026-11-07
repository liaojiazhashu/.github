(function () {
  var de = document.documentElement;
  var before = de.scrollWidth;
  var h = document.querySelector('.site-header');
  var prev = h ? h.style.display : null;
  if (h) h.style.display = 'none';
  var afterHeader = de.scrollWidth;
  if (h) h.style.display = prev;
  var inner = document.querySelector('.site-header__inner');
  var brand = document.querySelector('a.brand');
  var acts = document.querySelector('.site-header__actions');
  return {
    clientWidth: de.clientWidth,
    scrollWidth_原始: before,
    scrollWidth_隐藏顶栏后: afterHeader,
    '差值(顶栏贡献)': before - afterHeader,
    headerInner_scrollWidth: inner ? inner.scrollWidth : null,
    headerInner_width: inner ? +inner.getBoundingClientRect().width.toFixed(2) : null,
    brand: brand ? +brand.getBoundingClientRect().width.toFixed(2) : null,
    actions: acts ? +acts.getBoundingClientRect().width.toFixed(2) : null,
    gap: inner ? getComputedStyle(inner).columnGap : null,
    paddingLeft: inner ? getComputedStyle(inner).paddingLeft : null,
    brand_flex: brand ? getComputedStyle(brand).flex : null,
    actions_flex: acts ? getComputedStyle(acts).flex : null
  };
})()
