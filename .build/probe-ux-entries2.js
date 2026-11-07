(() => {
  const cw = document.documentElement.clientWidth;
  const vis = (el) => { const s = getComputedStyle(el), r = el.getBoundingClientRect(); return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0; };
  const seen = new Set();
  const list = [];
  document.querySelectorAll('a[href*="contact.html#invite"], a[href*="#invite"], [data-invite-open]').forEach((el) => {
    if (seen.has(el)) return; seen.add(el);
    const r = el.getBoundingClientRect();
    const inHeader = !!el.closest('.site-header');
    const inDrawer = !!el.closest('[data-drawer], .drawer, .nav-drawer, .mobile-nav, .site-nav');
    const inFooter = !!el.closest('footer, .site-footer');
    list.push({
      t: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 12),
      tag: el.tagName.toLowerCase(),
      cls: (typeof el.className === 'string' ? el.className : '').trim().split(/\s+/).slice(0, 2).join('.'),
      shown: vis(el),
      top: +r.top.toFixed(0),
      left: +r.left.toFixed(0),
      href: (el.getAttribute('href') || '').slice(0, 24),
      where: inHeader ? 'header' : inFooter ? 'footer' : inDrawer ? 'drawer' : 'body'
    });
  });
  const shownList = list.filter((x) => x.shown);
  return {
    cw, path: location.pathname, total: list.length, shown: shownList.length,
    shownWhere: shownList.reduce((a, x) => { a[x.where] = (a[x.where] || 0) + 1; return a; }, {}),
    items: list
  };
})()
