/* 主题在首帧前应用，避免先亮后暗地闪一下。
   CSP 是 script-src 'self'，所以这段只能外链、不能内联 —— 别嫌它文件小。
   主题跟随官网：官网的「银河图书馆」链接会带上 ?theme=day|night，
   这里的取值优先级是 URL 参数 > 本地记忆，取到参数就顺手存回本地。 */
(function () {
  try {
    var m = location.search.match(/[?&]theme=(day|night)(?:[&?#]|$)/);
    if (m) {
      localStorage.setItem('hq-theme', m[1]);
      document.documentElement.setAttribute('data-theme', m[1]);
      return;
    }
    var saved = localStorage.getItem('hq-theme');
    if (saved === 'day' || saved === 'night') {
      document.documentElement.setAttribute('data-theme', saved);
    }
  } catch (e) {
    /* 隐私模式下 localStorage 可能抛错，忽略即可 */
  }
})();
