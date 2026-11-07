(async () => {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const de = document.documentElement;
  const hdr = document.querySelector('.site-header');
  const cs = getComputedStyle(de);

  const out = {
    innerW: window.innerWidth,
    clientW: de.clientWidth,
    overflow: de.scrollWidth - de.clientWidth,
    pad: cs.scrollPaddingTop,
    padRaw: cs.getPropertyValue('scroll-padding-top'),
    idleHeaderH: Math.round(hdr.getBoundingClientRect().height),
    state: 'n/a',
    probes: []
  };

  for (const id of ['privacy', 'invite']) {
    const el = document.getElementById(id);
    if (!el) { out.probes.push({ id, missing: true }); continue; }

    window.scrollTo({ top: 0, behavior: 'instant' });
    await sleep(120);
    location.hash = '#' + id;
    await sleep(1400);

    const t = el.getBoundingClientRect().top;
    const hb = hdr.getBoundingClientRect().bottom;
    out.probes.push({
      id,
      elTop: Math.round(t * 100) / 100,
      headerBottom: Math.round(hb * 100) / 100,
      clearance: Math.round((t - hb) * 100) / 100,
      headerNow: Math.round(hdr.getBoundingClientRect().height),
      stuck: hdr.classList.contains('is-stuck'),
      y: Math.round(window.scrollY)
    });
  }

  /* 落回顶部，报告未吸附态页头高（锚点净空公式的基准） */
  window.scrollTo({ top: 0, behavior: 'instant' });
  await sleep(400);
  out.state = 'top';
  out.topHeaderH = Math.round(hdr.getBoundingClientRect().height);
  out.stuckAtTop = hdr.classList.contains('is-stuck');
  return out;
})()
