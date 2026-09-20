/*
 * Field Mode's service worker.
 *
 * ── The scar this is built around ────────────────────────────────────────
 * `public/sw.js` in this repo is a KILL-SWITCH, and its comment says why:
 *
 *   "An earlier build registered a cache-first worker that served stale assets
 *    after deploys."
 *
 * That already happened here once, in production. So this worker is narrow on
 * purpose, and every rule below exists because the alternative is how that
 * incident happened:
 *
 *  1. **Only content-hashed assets are cache-first.** A URL under
 *     `build/assets/…-[hash].js` can never change its bytes, so serving it
 *     from cache is safe by construction. Nothing else gets that treatment.
 *  2. **The shell is network-first.** A stale shell is served ONLY when the
 *     network genuinely failed, never merely because a copy exists.
 *  3. **`skipWaiting` is never automatic.** It happens when the page asks,
 *     after the user has been told. An update that takes over mid-form loses
 *     the form.
 *  4. **This worker never touches IndexedDB.** Not once, anywhere. That is
 *     what makes invariant I-6 true by construction rather than by care: a
 *     worker with no database code cannot destroy pending work, however badly
 *     an update goes.
 *  5. **No API response is ever cached.** Patient data belongs in IndexedDB
 *     under the controls in plan §17, not in an HTTP cache that no policy
 *     governs and no logout clears.
 *  6. **Admin HTML is never cached.** Livewire markup is per-session and
 *     carries an encrypted component snapshot; caching it is the 2026 incident
 *     again with worse consequences. A FAILED navigation to it is answered
 *     with a door into Field Mode — which is not the same thing as caching it.
 *  7. **It can be switched off in one deploy.** See UNREGISTER below.
 *  8. **A shell is never cached without the assets it names**, and **no path
 *     here may ever reject.** Both learned the hard way — see below.
 *
 * ── The second scar ──────────────────────────────────────────────────────
 * The first version of this file did two things wrong, and together they made
 * "Continue in Field Mode" land on the browser's own ERR_FAILED page:
 *
 *  - `cacheFirst` ended in a bare `await fetch(request)` with no catch. Offline
 *    plus a cache miss meant the promise handed to `respondWith` REJECTED, and
 *    a rejected `respondWith` is exactly what ERR_FAILED means. The worker was
 *    working perfectly right up to the point where it produced nothing at all.
 *  - `install` cached the shell HTML and none of the hashed assets it points
 *    at. Those were only ever cached as a side effect of being fetched online.
 *    So after any deploy — or any `npm run build` — the cached HTML named a
 *    bundle that was not in the cache and no longer on the server, and the
 *    cache miss above became a certainty rather than a possibility.
 *
 * Hence: the HTML and its assets are cached TOGETHER or not at all, a cached
 * shell whose assets are missing is not offered (a blank screen is worse than
 * an explanation), and every handler is wrapped so the worst case is a page
 * that says what happened.
 *
 * ── Where the app lives ──────────────────────────────────────────────────
 * Nothing here assumes the app sits at the origin root. It does not: this
 * installation is served from `/true-doctor/`, and an earlier version of this
 * file hardcoded `/field`, `/api/` and `/admin` — so it matched nothing at all
 * and offline mode silently did not work. The base is derived from where this
 * script itself was served, which is a fact the worker can always read.
 */

const VERSION = 'v3';

/* `/true-doctor/field-sw.js` → `/true-doctor`. `/field-sw.js` → ``. */
const BASE = self.location.pathname.replace(/\/field-sw\.js$/, '');

const path = (suffix) => `${BASE}${suffix}`;

const SHELL_URL = path('/field');

const SHELL_CACHE = `td-field-shell-${VERSION}`;
const ASSET_CACHE = `td-field-assets-${VERSION}`;

/* A flag the app can set to retire this worker without shipping a new one.
 * If offline mode has to be withdrawn, this is the lever (plan §21). */
const UNREGISTER = false;

/**
 * Why the last prime did not finish, for the readiness screen to show.
 *
 * Without it, a device that is signed out and a device that is merely offline
 * look identical: "the page has not been downloaded yet", with a button that
 * will fail the same way. `signed-out` is the one a person can actually act
 * on, and it is the common case — `/field` is behind `auth`, and a background
 * install that runs after the session lapses lands on the login page.
 */
let lastPrimeIssue = null;

self.addEventListener('install', (event) => {
    if (UNREGISTER) {
        return;
    }

    event.waitUntil(primeShell());

    // Deliberately NOT self.skipWaiting(). See rule 3.
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        if (UNREGISTER) {
            const names = await caches.keys();
            await Promise.all(names.filter((n) => n.startsWith('td-field-')).map((n) => caches.delete(n)));
            await self.registration.unregister();

            const clients = await self.clients.matchAll({ type: 'window' });
            clients.forEach((client) => {
                try {
                    client.navigate(client.url);
                } catch {
                    // The tab may have gone. Nothing to do.
                }
            });

            return;
        }

        const names = await caches.keys();

        await Promise.all(
            names
                .filter((name) => name.startsWith('td-field-') && name !== SHELL_CACHE && name !== ASSET_CACHE)
                .map((name) => caches.delete(name)),
        );

        await self.clients.claim();
    })());
});

/**
 * The page asks to take the update.
 *
 * Only ever in response to a message, so the user has been told and has
 * agreed. A worker that calls `skipWaiting()` in `install` reloads somebody
 * mid-sentence.
 */
self.addEventListener('message', (event) => {
    if (event.data === 'td:skip-waiting') {
        self.skipWaiting();

        return;
    }

    // Everything below answers a question the readiness screen asked, over a
    // MessagePort the page supplied. A worker that can only be observed
    // through DevTools cannot tell a clinician whether they are ready to walk
    // away from the network, and that is the one thing they need to know.
    const reply = event.ports && event.ports[0];

    if (!reply) {
        return;
    }

    if (event.data === 'td:status') {
        event.waitUntil(cacheReport().then((report) => reply.postMessage(report)));

        return;
    }

    if (event.data === 'td:prime') {
        // The download the admin screen triggers. Deliberately the SAME code
        // path as install, so "prepare this device" and "the worker installed
        // itself" can never end up meaning different things.
        event.waitUntil(
            primeShell()
                .then(() => cacheReport())
                .then((report) => reply.postMessage(report)),
        );
    }
});

/**
 * What this worker currently holds, in terms a person can be shown.
 *
 * Never throws: an unanswerable question here would hang the readiness screen
 * on a spinner, which is a worse answer than "no".
 */
async function cacheReport() {
    const report = {
        version: VERSION,
        base: BASE,
        shell: false,
        assets: { wanted: 0, held: 0 },
        usable: false,
        reason: lastPrimeIssue,
    };

    try {
        const shellCache = await caches.open(SHELL_CACHE);
        const cached = await shellCache.match(SHELL_URL);

        if (!cached) {
            return report;
        }

        // A redirected copy is not a shell, whatever it looks like. Reporting
        // `shell: true` for one is how the readiness screen would come to say
        // "ready" about a device that cannot open Field Mode at all.
        if (cached.redirected) {
            report.reason = 'signed-out';

            return report;
        }

        report.reason = lastPrimeIssue;

        report.shell = true;

        const html = await cached.clone().text();
        const urls = assetUrlsIn(html);
        const assetCache = await caches.open(ASSET_CACHE);

        let held = 0;

        for (const url of urls) {
            if (await assetCache.match(url)) {
                held++;
            }
        }

        report.assets = { wanted: urls.length, held };
        report.usable = urls.length > 0 && held === urls.length;

        return report;
    } catch {
        return report;
    }
}

self.addEventListener('fetch', (event) => {
    if (UNREGISTER) {
        return;
    }

    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    const route = url.pathname.startsWith(BASE) ? url.pathname.slice(BASE.length) : url.pathname;

    // Rule 5: nothing under the API is ever cached. A cached patient record
    // here would be a second copy under nobody's governance.
    if (route.startsWith('/api/')) {
        return;
    }

    // Livewire's own traffic, untouched. Its POSTs already return above on
    // method, but saying it explicitly matters: a future edit that starts
    // handling more must not quietly start caching an encrypted component
    // snapshot, which is the 2026 incident with worse consequences.
    if (route.startsWith('/livewire')) {
        return;
    }

    // Rule 1: content-hashed build output. The bytes behind one of these URLs
    // never change, so cache-first is safe by construction rather than by hope.
    if (isImmutableAsset(route)) {
        event.respondWith(cacheFirst(request, ASSET_CACHE));

        return;
    }

    if (request.mode !== 'navigate') {
        // Rule 6: Livewire's own POSTs and everything else go straight to the
        // network, untouched.
        return;
    }

    // Rule 2: the Field Mode shell, network-first.
    if (route.startsWith('/field')) {
        event.respondWith(networkFirst(request));

        return;
    }

    /*
     * Rule 6, the other half.
     *
     * The admin panel is Livewire and cannot work offline — its state,
     * validation and rendering all live on the server. Its HTML is never
     * cached and never will be.
     *
     * But a clinician who types the admin URL with no connection should not
     * get the browser's "this site can't be reached". They should be told what
     * is going on and offered the thing that DOES work. That needs no cached
     * admin HTML: it is a fallback for a navigation that already failed.
     */
    if (route.startsWith('/admin') || route === '' || route === '/') {
        event.respondWith(adminOrDoor(request));
    }
});

function isImmutableAsset(route) {
    // Vite writes `name-[hash].js`. The hash is what makes the URL immutable;
    // a path without one is NOT cached, however asset-like it looks.
    return /^\/build\/assets\/.+-[A-Za-z0-9_-]{8,}\.(js|css|woff2?|ttf|png|svg|jpg|webp)$/.test(route);
}

/**
 * Cache the shell AND everything it points at, together.
 *
 * Together is the whole point. Caching the HTML alone leaves a document that
 * names a bundle nobody has — which is what happens after every `npm run
 * build`, because the hash in the filename changes — and the page then opens
 * to a blank screen or, worse, fails outright.
 *
 * Best effort throughout: a device that is offline or signed out at install
 * time still gets a worker, and fills on the first successful navigation.
 *
 * @returns {Promise<boolean>} whether the shell and its assets are now here
 */
async function primeShell(html = null) {
    try {
        const shellCache = await caches.open(SHELL_CACHE);

        let body = html;

        if (body === null) {
            // `reload` so priming never copies an HTTP cache entry that is
            // itself stale — one of the ways the first worker went wrong.
            const response = await fetch(new Request(SHELL_URL, { cache: 'reload' }));

            // A redirect to the login page is not the shell.
            //
            // Note what is NOT true, because believing it is what caused the
            // bug: `Cache.put` does **not** refuse this. It refuses an
            // `opaqueredirect`, which is a different thing — a redirect that
            // was FOLLOWED yields an ordinary 200 that stores perfectly well.
            // The refusal comes later and somewhere else, when the browser is
            // asked to hand that response to a navigation.
            if (!response.ok || response.redirected) {
                // Signed out, most likely: `/field` is behind `auth`, and a
                // background install that happens after the session lapses
                // follows the redirect to the login page and gets a perfectly
                // good 200 for entirely the wrong document.
                //
                // Any existing entry goes with it. Leaving a poisoned one in
                // place is how a device ends up confidently offering an
                // offline mode that cannot open.
                await shellCache.delete(SHELL_URL);
                lastPrimeIssue = 'signed-out';

                return false;
            }

            body = await response.clone().text();
            await shellCache.put(SHELL_URL, response);
        }

        const complete = await primeAssets(body);
        lastPrimeIssue = complete ? null : 'assets';

        return complete;
    } catch {
        lastPrimeIssue = 'unreachable';

        return false;
    }
}

/** Fetch and cache every hashed asset the shell names. */
async function primeAssets(html) {
    try {
        const cache = await caches.open(ASSET_CACHE);
        const urls = assetUrlsIn(html);

        // Individually, not `addAll`: `addAll` is all-or-nothing, so one
        // missing font would throw away a perfectly good bundle.
        const results = await Promise.all(urls.map(async (url) => {
            try {
                if (await cache.match(url)) {
                    return true;
                }

                const response = await fetch(new Request(url, { cache: 'reload' }));

                if (!response.ok) {
                    return false;
                }

                await cache.put(url, response);

                return true;
            } catch {
                return false;
            }
        }));

        return urls.length > 0 && results.every(Boolean);
    } catch {
        return false;
    }
}

/**
 * The same-origin build output a page references.
 *
 * A regex rather than a parser because a service worker has no DOM. It only
 * has to find `src="…"` and `href="…"` pointing into `build/assets`, which
 * Vite writes in exactly one shape.
 *
 * Cross-origin references — the Font Awesome stylesheet — are deliberately not
 * cached. An opaque cross-origin response cannot be used as a stylesheet, so
 * storing one would buy nothing; offline, the icons are missing and the words
 * beside them are not.
 */
function assetUrlsIn(html) {
    const found = new Set();
    const pattern = /(?:src|href)="([^"]*\/build\/assets\/[^"]+)"/g;

    let match;

    while ((match = pattern.exec(html)) !== null) {
        try {
            const url = new URL(match[1], self.location.origin);

            if (url.origin === self.location.origin) {
                found.add(url.pathname);
            }
        } catch {
            // Not a URL we can resolve. Skip it rather than fail the install.
        }
    }

    return [...found];
}

/**
 * A hashed asset: cache first, because its bytes can never change.
 *
 * Every exit returns a Response. The version of this that ended in a bare
 * `await fetch(request)` handed `respondWith` a REJECTED promise the moment
 * the device was offline and the asset was not cached — and a rejected
 * `respondWith` is precisely what the browser reports as ERR_FAILED.
 */
async function cacheFirst(request, cacheName) {
    try {
        const cache = await caches.open(cacheName);
        const hit = await cache.match(request);

        if (hit) {
            return hit;
        }

        // Cloned before it is read: a Response body can only be consumed once,
        // and putting the original back would hand the page an empty stream.
        const response = await fetch(request);

        if (response.ok) {
            await cache.put(request, response.clone());
        }

        return response;
    } catch {
        return missingAsset(request);
    }
}

/**
 * The shell: network first, cache only when the network genuinely failed.
 *
 * A cached shell is offered only if the assets it names are cached too. A
 * document whose bundle is missing renders a blank page, and a blank page is
 * worse than a sentence explaining what to do about it.
 */
async function networkFirst(request) {
    let cache = null;

    try {
        cache = await caches.open(SHELL_CACHE);
    } catch {
        // Storage is unavailable. Nothing to serve from, but we must still
        // answer — see the comment on cacheFirst.
        cache = null;
    }

    try {
        const response = await fetch(request);

        if (response.ok && !response.redirected && cache !== null) {
            const body = await response.clone().text();

            await cache.put(SHELL_URL, response.clone());
            // Not awaited: the page must not wait on a background prime, and
            // a failure here is already reported by `shellIsUsable` later.
            primeAssets(body);
        }

        return response;
    } catch {
        const cached = cache === null ? null : await cache.match(SHELL_URL);

        if (cached === null || cached === undefined) {
            return offlineDoor(false);
        }

        if (!(await shellIsUsable(cached))) {
            // Throw the bad copy away rather than failing the same way on
            // every navigation for ever. A cache that cannot be served is not
            // worth keeping, and the next online visit will refill it.
            await cache.delete(SHELL_URL).catch(() => {});

            return offlineDoor(false);
        }

        return cached;
    }
}

/**
 * Is this cached shell actually openable with no network?
 *
 * It is only usable if every asset it names is cached as well. This is the
 * check that turns "a blank Field Mode" into "open it once online and it will
 * work from then on".
 */
async function shellIsUsable(cachedShell) {
    try {
        // FIRST, and absolutely. A `redirected` response handed to a
        // navigation — whose redirect mode is `manual` — is a network error,
        // which the browser reports as ERR_FAILED. This is what actually
        // happened: `cache.add()` followed `/field` to the login page when the
        // session had lapsed, stored the 200 it landed on, and every offline
        // navigation afterwards died on it.
        //
        // It is checked before anything else because the page it redirected TO
        // is usually the login page, which has its own build assets — so every
        // check below would happily pass on the wrong document.
        if (cachedShell.redirected) {
            return false;
        }

        const html = await cachedShell.clone().text();

        // And it must be the Field Mode shell, not merely a page. The login
        // page is a 200 with assets of its own; only the shell carries the two
        // ids that say which local database this is.
        if (!html.includes('name="td-user"') || !html.includes('name="td-hospital"')) {
            return false;
        }

        const urls = assetUrlsIn(html);

        if (urls.length === 0) {
            // A shell that references no build output at all is not one this
            // app produces, so something is wrong with the cached copy.
            return false;
        }

        const cache = await caches.open(ASSET_CACHE);

        for (const url of urls) {
            if (!(await cache.match(url))) {
                return false;
            }
        }

        return true;
    } catch {
        return false;
    }
}

/**
 * An asset that is not cached and cannot be fetched.
 *
 * A 504 with an empty body, except for scripts — a blank page with a silently
 * missing bundle is the least debuggable outcome there is, so a missing script
 * says so out loud instead.
 */
function missingAsset(request) {
    const isScript = request.destination === 'script' || /\.js$/.test(new URL(request.url).pathname);

    if (!isScript) {
        return new Response('', { status: 504, headers: { 'Cache-Control': 'no-store' } });
    }

    return new Response(
        `document.body.innerHTML = '<main style="font:15px/1.6 system-ui;padding:40px;max-width:32rem;margin:auto">'
          + '<h1 style="font-size:18px">Field Mode is not fully downloaded on this device</h1>'
          + '<p>Open it once while you have a connection and it will work offline from then on.</p></main>';`,
        { status: 200, headers: { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store' } },
    );
}

/**
 * A navigation to the admin panel, and what to do when it fails.
 *
 * On success: straight through, and **nothing is stored**. On failure: the
 * door, which is a page this worker generates rather than one it cached.
 */
async function adminOrDoor(request) {
    try {
        return await fetch(request);
    } catch {
        return offlineDoor(await haveWorkingFieldMode());
    }
}

/**
 * Whether the door can honestly offer Field Mode.
 *
 * It asks the same question `networkFirst` will ask a moment later, because
 * the two answering differently is what produced the original report: the
 * door said "Field Mode works without one", and the link it offered led to a
 * browser error page. A door that promises something has to be sure of it.
 */
async function haveWorkingFieldMode() {
    try {
        const cache = await caches.open(SHELL_CACHE);
        const cached = await cache.match(SHELL_URL);

        return cached ? await shellIsUsable(cached) : false;
    } catch {
        return false;
    }
}

/**
 * "There is no connection, and here is what you can still do."
 *
 * Generated, not cached, so it is always consistent with this worker and can
 * never itself go stale. It says plainly that the main panel needs a
 * connection — that is a fact about the architecture, not a fault — and
 * points at the surface that does not.
 */
function offlineDoor(haveFieldMode) {
    const body = haveFieldMode
        ? `<p>The main panel needs a connection: every screen in it is rendered by the server.</p>
           <p><strong>Field Mode works without one.</strong> You can register patients, record vitals,
              write nursing notes and record medication. Everything is saved on this device and sent
              automatically when the connection returns.</p>
           <p><a class="go" href="${SHELL_URL}">Continue in Field Mode</a>
              <a class="again" href="javascript:location.reload()">Try again</a></p>`
        : `<p>The main panel needs a connection: every screen in it is rendered by the server.</p>
           <p>Field Mode has not been set up on this device yet, so there is nothing to work from
              offline. Open Field Mode once while you have a connection and it will be available
              from then on.</p>
           <p><a class="again" href="javascript:location.reload()">Try again</a></p>`;

    return new Response(
        `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>No connection · True-Doctor</title>
<style>
  :root{color-scheme:light dark}
  body{font:15px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
       margin:0;padding:48px 20px;background:#f5f7fa;color:#111c2e;display:flex;
       justify-content:center}
  @media (prefers-color-scheme:dark){body{background:#0f1520;color:#e6ebf2}}
  main{max-width:34rem}
  h1{font-size:20px;margin:0 0 6px}
  .sub{color:#5a6b80;font-size:13.5px;margin:0 0 20px}
  p{margin:0 0 14px}
  a{display:inline-block;padding:9px 16px;text-decoration:none;font-size:14px;font-weight:500;
    margin:6px 8px 0 0}
  a.go{background:#0a6ebd;color:#fff}
  a.again{border:1px solid #cbd5e1;color:inherit}
</style></head><body><main>
<h1>No connection</h1>
<p class="sub">Nothing has been lost. Anything already saved on this device is still here.</p>
${body}
</main></body></html>`,
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } },
    );
}
