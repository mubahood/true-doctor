/* Service worker kill-switch.
 * An earlier build registered a cache-first worker that served stale assets
 * after deploys. This worker only cleans up: it deletes every cache,
 * unregisters itself and reloads open tabs. Remove after one release cycle. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    /* Everything EXCEPT Field Mode's. This worker exists to clean up after a
     * cache-first worker that predates Field Mode; a browser that still has it
     * registered would otherwise delete the offline shell out from under
     * field-sw.js on its way out, and the clinician would find Field Mode gone
     * with no explanation. */
    try {
      const keys = await caches.keys();
      await Promise.all(keys.filter((k) => !k.startsWith('td-field-')).map((k) => caches.delete(k)));
    } catch (e) {}
    try { await self.registration.unregister(); } catch (e) {}
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach((c) => { try { c.navigate(c.url); } catch (e) {} });
  })());
});
