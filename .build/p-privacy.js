(() => {
  const de = document.documentElement;
  const sec = document.getElementById('privacy');
  const panels = sec ? sec.querySelectorAll('.panel') : [];
  const rows = [];
  panels.forEach((p) => {
    const r = p.getBoundingClientRect();
    rows.push({
      w: Math.round(r.width),
      h: Math.round(r.height),
      title: (p.querySelector('.panel__title') || {}).textContent?.trim().slice(0, 26),
      bodyW: Math.round((p.querySelector('.panel__body') || p).getBoundingClientRect().width)
    });
  });
  const h2 = sec ? sec.querySelector('.section-head__title') : null;
  const notice = sec ? sec.querySelector('.notice') : null;
  return {
    cw: de.clientWidth,
    overflow: de.scrollWidth - de.clientWidth,
    secH: sec ? Math.round(sec.getBoundingClientRect().height) : null,
    h2: h2 ? h2.textContent.trim() : null,
    panels: rows,
    noticeH: notice ? Math.round(notice.getBoundingClientRect().height) : null,
    // 正文列宽：取最长段落的实际宽度
    bodyMaxW: rows.length ? Math.max.apply(null, rows.map((r) => r.bodyW)) : null,
    iconsRendered: sec ? sec.querySelectorAll('svg.ico').length : 0,
    iconsEmpty: sec ? Array.prototype.filter.call(sec.querySelectorAll('svg.ico'), (s) => s.children.length === 0).length : 0
  };
})()
