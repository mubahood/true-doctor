{{-- Tell the browser & dark-mode extensions this app themes itself, so
     "Force Dark Mode" / Dark Reader don't re-invert it into a black screen. --}}
<meta name="color-scheme" content="dark light">
<meta name="darkreader-lock">

{{-- Service-worker cleanup + diagnostics. Logs to console with the CX-DIAG tag
     so we can see exactly what a stuck browser is loading. Retires any old
     cache-first worker (which caused blank screens) and reloads once. --}}
<script>
(function () {
  var TAG = 'CX-DIAG';
  function log() {
    try { console.log.apply(console,
      ['%c' + TAG, 'background:#f59e0b;color:#000;padding:2px 6px;border-radius:3px;font-weight:bold']
      .concat([].slice.call(arguments))); } catch (e) {}
  }
  log('running · url=', location.href, '· built={{ now()->timestamp }}');

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(function (regs) {
      log('SW registrations=', regs.length, '· controller=', !!navigator.serviceWorker.controller);
      if (window.caches && caches.keys) caches.keys().then(function (ks) { log('caches=', ks); });
      if (regs.length) {
        Promise.all(regs.map(function (r) { return r.unregister(); }))
          .then(function () {
            return (window.caches && caches.keys)
              ? caches.keys().then(function (ks) { return Promise.all(ks.map(function (k) { return caches.delete(k); })); })
              : null;
          })
          .then(function () {
            log('cleaned old SW + caches');
            if (!sessionStorage.getItem('cx_sw_cleared')) {
              sessionStorage.setItem('cx_sw_cleared', '1');
              log('reloading once to fetch fresh assets…');
              location.reload();
            }
          }).catch(function (e) { log('cleanup error', e && e.message); });
      }
    }).catch(function (e) { log('getRegistrations error', e && e.message); });
  } else { log('no serviceWorker support'); }

  window.addEventListener('load', function () {
    try {
      var sheets = [].map.call(document.styleSheets, function (s) {
        var n; try { n = s.cssRules ? s.cssRules.length : 0; } catch (e) { n = 'BLOCKED'; }
        return ((s.href || 'inline').split('/').pop()) + '=' + n;
      });
      log('stylesheets:', sheets.join('  |  '));
      var el = document.querySelector('.ad-sidebar') || document.querySelector('.ta-nav') || document.querySelector('.hero') || document.body;
      log('layout el', el.className || el.tagName, '· bg=', getComputedStyle(el).backgroundColor, '· width=', Math.round(el.getBoundingClientRect().width));
    } catch (e) { log('load diag error', e && e.message); }
  });
})();
</script>
