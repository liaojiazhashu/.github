(() => {
  const de = document.documentElement, cw = de.clientWidth;
  const q = (s) => document.querySelector(s);
  const cs = (el) => el ? getComputedStyle(el) : null;
  const rect = (el) => el ? el.getBoundingClientRect() : null;
  const vis = (el) => { if (!el) return false; const s = cs(el), r = rect(el); return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0; };

  const inner = q('.site-header__inner');
  const brand = q('.site-header__inner a.brand') || q('.brand');
  const acts = q('.site-header__actions');
  const cta = q('.site-header__actions .btn--primary') || q('.site-header__actions .btn');
  const tg = q('.nav-toggle');

  const ics = cs(inner);
  const padL = ics ? parseFloat(ics.paddingLeft) : null;
  const padR = ics ? parseFloat(ics.paddingRight) : null;
  const iGap = ics ? ics.gap : null;

  const acs = cs(acts);
  const aGap = acs ? parseFloat(acs.gap) : null;
  const kids = acts ? Array.from(acts.children).map((c) => ({ s: c.tagName.toLowerCase() + (typeof c.className === 'string' && c.className ? '.' + c.className.trim().split(/\s+/).join('.') : ''), w: +rect(c).width.toFixed(2), d: cs(c).display })) : [];
  const shownKids = kids.filter((k) => k.d !== 'none');

  const bw = brand ? +rect(brand).width.toFixed(2) : null;
  const aw = acts ? +rect(acts).width.toFixed(2) : null;
  const t = rect(tg);

  // 顶栏这一行的最小宽：左右 padding + brand + actions。两子项是 space-between，gap 不参与。
  const need = (padL != null && bw != null && aw != null) ? +(padL + bw + aw + padR).toFixed(2) : null;
  const childCount = inner ? inner.children.length : null;
  const innerCls = inner ? inner.className : null;

  return {
    cw, scrollW: de.scrollWidth, overflow: de.scrollWidth - cw,
    innerW: inner ? +rect(inner).width.toFixed(2) : null,
    padL, padR, iGap,
    brand: { w: bw, right: brand ? +rect(brand).right.toFixed(2) : null },
    actions: { w: aw, right: acts ? +rect(acts).right.toFixed(2) : null, gap: aGap, kids: shownKids },
    cta: cta ? { w: +rect(cta).width.toFixed(2), d: cs(cta).display, padInline: cs(cta).paddingInline } : null,
    toggle: tg ? { w: +t.width.toFixed(2), left: +t.left.toFixed(2), right: +t.right.toFixed(2), clip: +(t.right - cw).toFixed(2) } : null,
    sumWithPad: need,
    childCount, innerCls,
    innerBoxSum: (bw != null && aw != null) ? +(bw + aw).toFixed(2) : null,
    hdrH: +rect(q('.site-header')).height.toFixed(2),
    inviteLinksVisible: Array.from(document.querySelectorAll('a[href*="contact.html#invite"], [data-invite-open]')).filter(vis).length,
    lastInviteText: (() => { const a = Array.from(document.querySelectorAll('a[href*="contact.html#invite"], [data-invite-open]')).filter(vis); return a.length ? (a[a.length - 1].textContent || '').trim().slice(0, 16) : null; })()
  };
})()
