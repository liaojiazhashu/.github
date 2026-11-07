(() => {
  const h = document.querySelector('.site-header');
  const r = h.getBoundingClientRect();
  const cs = getComputedStyle(h);
  return { h: Math.round(r.height), pos: cs.position, top: cs.top, cw: document.documentElement.clientWidth };
})()
