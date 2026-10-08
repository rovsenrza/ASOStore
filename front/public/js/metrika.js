// Yandex.Metrika counter 113565588. A file rather than an inline script, so pages served with a
// strict Content-Security-Policy (the catalog pages rendered by the backend) can run it too.
(function (win, doc, tag, src, name) {
  win[name] = win[name] || function (...args) { (win[name].a = win[name].a || []).push(args); };
  win[name].l = Date.now();
  for (const script of doc.scripts) {
    if (script.src === src) return;
  }
  const loader = doc.createElement(tag);
  const first = doc.getElementsByTagName(tag)[0];
  loader.async = true;
  loader.src = src;
  first.parentNode.insertBefore(loader, first);
})(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js?id=113565588', 'ym');

window.ym(113565588, 'init', {
  ssr: true,
  webvisor: true,
  clickmap: true,
  ecommerce: 'dataLayer',
  referrer: document.referrer,
  url: location.href,
  accurateTrackBounce: true,
  trackLinks: true,
});
