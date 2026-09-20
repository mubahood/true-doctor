import { describe, it, expect, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The service worker, actually run.
 *
 * Until now this file was only ever asserted against its own source, because
 * PHPUnit has no service-worker runtime. That was not good enough: the worker
 * has produced four bugs, every one of them a runtime behaviour a regex could
 * not see, and the last one — a REDIRECTED response served to a navigation —
 * killed the whole feature while every test in the project passed.
 *
 * So the source is loaded into a sandbox with the handful of globals a worker
 * gets, and the real functions are called. Not a browser, and it does not
 * pretend to be: there is no install/activate lifecycle here and no real
 * `respondWith`. What it does exercise is every decision the worker makes
 * about what to store and what to serve, which is where all four bugs were.
 */

const ORIGIN = 'http://localhost:8888';
const BASE = '/true-doctor';
const SHELL = `${BASE}/field`;

/** A Response that reports itself as having followed a redirect. */
function redirected(body, init = {}) {
    const response = new Response(body, { status: 200, ...init });

    // `redirected` is a getter on the prototype and always false for a
    // constructed Response, so the only way to simulate the real thing is to
    // shadow it. This is the exact property the browser refuses to serve to a
    // navigation, so a test that cannot set it cannot test the bug.
    Object.defineProperty(response, 'redirected', { value: true });

    return response;
}

/** The markup Vite actually emits for the shell, reduced to what matters. */
function shellHtml({ assets = ['field-abc12345.js', 'field-def67890.css'] } = {}) {
    const tags = assets
        .map((a) => (a.endsWith('.css')
            ? `<link rel="stylesheet" href="${ORIGIN}${BASE}/build/assets/${a}" />`
            : `<script type="module" src="${ORIGIN}${BASE}/build/assets/${a}"></script>`))
        .join('\n');

    return `<!doctype html><html><head>
        <meta name="td-base" content="${BASE}">
        <meta name="td-hospital" content="1">
        <meta name="td-user" content="7">
        ${tags}
        </head><body></body></html>`;
}

/** The login page: a perfectly good 200, with build assets of its own. */
function loginHtml() {
    return `<!doctype html><html><head>
        <meta name="csrf-token" content="x">
        <script type="module" src="${ORIGIN}${BASE}/build/assets/auth-99999999.js"></script>
        </head><body>Sign in</body></html>`;
}

function memoryCaches() {
    const stores = new Map();

    const keyOf = (request) => (typeof request === 'string' ? request : new URL(request.url).pathname);

    const makeCache = (entries) => ({
        async match(request) {
            return entries.get(keyOf(request)) ?? undefined;
        },
        async put(request, response) {
            entries.set(keyOf(request), response);
        },
        async delete(request) {
            return entries.delete(keyOf(request));
        },
        async keys() {
            return [...entries.keys()];
        },
        _entries: entries,
    });

    return {
        async open(name) {
            if (!stores.has(name)) {
                stores.set(name, new Map());
            }

            return makeCache(stores.get(name));
        },
        async keys() {
            return [...stores.keys()];
        },
        async delete(name) {
            return stores.delete(name);
        },
        _stores: stores,
    };
}

/**
 * Load `public/field-sw.js` and hand back its internals.
 *
 * The file's top level is all declarations plus `self.addEventListener`
 * calls, so evaluating it inside a function body puts everything in scope and
 * a trailing `return` exposes it.
 */
function loadWorker({ fetch: fetchStub } = {}) {
    const source = readFileSync(resolve(process.cwd(), 'public/field-sw.js'), 'utf8');
    const listeners = {};

    const self = {
        location: { pathname: `${BASE}/field-sw.js`, origin: ORIGIN },
        addEventListener: (type, handler) => { listeners[type] = handler; },
        skipWaiting: () => {},
        clients: { matchAll: async () => [], claim: async () => {} },
        registration: { unregister: async () => true },
    };

    const caches = memoryCaches();
    const fetch = fetchStub ?? (async () => { throw new TypeError('Failed to fetch'); });

    const exposed = [
        'VERSION', 'BASE', 'SHELL_URL', 'SHELL_CACHE', 'ASSET_CACHE',
        'primeShell', 'primeAssets', 'assetUrlsIn', 'cacheFirst', 'networkFirst',
        'shellIsUsable', 'adminOrDoor', 'haveWorkingFieldMode', 'cacheReport',
        'isImmutableAsset', 'offlineDoor',
    ];

    /**
     * A `Request` that resolves a relative URL, as a browser does.
     *
     * The worker builds requests from paths — `/true-doctor/build/assets/…` —
     * which a browser resolves against the worker's own scope. Node has no
     * base and throws, so without this shim the harness would report bugs the
     * real thing does not have.
     */
    class ScopedRequest extends Request {
        constructor(input, init) {
            super(typeof input === 'string' ? new URL(input, ORIGIN).toString() : input, init);
        }
    }

    const factory = new Function(
        'self', 'caches', 'fetch', 'Request',
        `${source}\n;return { ${exposed.join(', ')} };`,
    );

    return { ...factory(self, caches, fetch, ScopedRequest), caches, listeners };
}

/**
 * Everything cached and correct EXCEPT that the response is redirected.
 *
 * The assets matter: without them the shell would be refused anyway, for
 * a different reason, and the test would pass while proving nothing. The
 * first version of this test did exactly that — it went green with the
 * redirect guard deleted.
 */
async function poisonedButOtherwisePerfect(worker) {
    const shellCache = await worker.caches.open(worker.SHELL_CACHE);
    const assetCache = await worker.caches.open(worker.ASSET_CACHE);

    await shellCache.put(SHELL, redirected(shellHtml()));
    await assetCache.put(`${BASE}/build/assets/field-abc12345.js`, new Response('/* js */'));
    await assetCache.put(`${BASE}/build/assets/field-def67890.css`, new Response('/* css */'));

    return shellCache;
}

let sw;

beforeEach(() => {
    sw = null;
});

describe('where the app lives', () => {
    it('derives its base from its own script url', () => {
        sw = loadWorker();

        expect(sw.BASE).toBe(BASE);
        expect(sw.SHELL_URL).toBe(SHELL);
    });

    it('only treats content-hashed build output as immutable', () => {
        sw = loadWorker();

        expect(sw.isImmutableAsset('/build/assets/field-abc12345.js')).toBe(true);
        expect(sw.isImmutableAsset('/build/assets/field.js')).toBe(false);
        expect(sw.isImmutableAsset('/images/logo.png')).toBe(false);
    });

    it('finds the assets in the markup Vite actually writes', () => {
        sw = loadWorker();

        const urls = sw.assetUrlsIn(shellHtml());

        expect(urls).toEqual([
            `${BASE}/build/assets/field-abc12345.js`,
            `${BASE}/build/assets/field-def67890.css`,
        ]);
    });

    it('ignores cross-origin references, which cannot be cached usefully', () => {
        sw = loadWorker();

        const html = '<link rel="stylesheet" href="https://cdn.example.com/build/assets/x-12345678.css">';

        expect(sw.assetUrlsIn(html)).toEqual([]);
    });
});

describe('a redirected response — the bug that killed the feature', () => {
    /**
     * `/field` is behind `auth`. A background install that runs after the
     * session has lapsed follows the redirect to the login page and gets a
     * perfectly good 200 for entirely the wrong document. Serving THAT to a
     * navigation, whose redirect mode is `manual`, is a network error:
     *
     *   "a redirected response was used for a request whose redirect mode is
     *    not follow"
     *
     * which the browser shows as ERR_FAILED.
     */
    it('is never stored by a prime', async () => {
        sw = loadWorker({ fetch: async () => redirected(loginHtml()) });

        expect(await sw.primeShell()).toBe(false);

        const cache = await sw.caches.open(sw.SHELL_CACHE);
        expect(await cache.match(SHELL)).toBeUndefined();
    });

    it('is deleted from the cache when a prime finds the session gone', async () => {
        sw = loadWorker({ fetch: async () => redirected(loginHtml()) });

        // A good copy from earlier.
        const cache = await sw.caches.open(sw.SHELL_CACHE);
        await cache.put(SHELL, new Response(shellHtml()));

        await sw.primeShell();

        // Not kept. A stale-but-good shell would be defensible; the point is
        // that the prime must not leave a copy it has just failed to verify.
        expect(await cache.match(SHELL)).toBeUndefined();
    });


    it('is refused by shellIsUsable on its own, with nothing else wrong', async () => {
        sw = loadWorker();
        const cache = await poisonedButOtherwisePerfect(sw);

        expect(await sw.shellIsUsable(await cache.match(SHELL))).toBe(false);
    });

    it('is never served to a navigation, even when it is sitting in the cache', async () => {
        sw = loadWorker();
        await poisonedButOtherwisePerfect(sw);

        const response = await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        // The door, not the poisoned copy, and above all NOT a rejection.
        expect(response.status).toBe(503);
        expect(await response.text()).toContain('No connection');
    });

    it('is thrown away when found, so it cannot fail the same way for ever', async () => {
        sw = loadWorker();
        const cache = await poisonedButOtherwisePerfect(sw);

        await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        expect(await cache.match(SHELL)).toBeUndefined();
    });

    it('is not counted as a downloaded shell by the readiness report', async () => {
        sw = loadWorker();

        await poisonedButOtherwisePerfect(sw);

        const report = await sw.cacheReport();

        expect(report.shell).toBe(false);
        expect(report.usable).toBe(false);
        expect(report.reason).toBe('signed-out');
    });
});

describe('a cached page that is not the shell', () => {
    it('is refused even though it is a valid 200 with assets of its own', async () => {
        // The login page would pass every structural check: 200, not
        // redirected, references build output. Only the shell carries the two
        // ids that say which local database this is.
        sw = loadWorker();

        const cache = await sw.caches.open(sw.SHELL_CACHE);
        await cache.put(SHELL, new Response(loginHtml()));

        expect(await sw.shellIsUsable(await cache.match(SHELL))).toBe(false);
    });

    it('is refused when it references no build output at all', async () => {
        sw = loadWorker();

        const cache = await sw.caches.open(sw.SHELL_CACHE);
        await cache.put(SHELL, new Response('<meta name="td-user" content="7"><meta name="td-hospital" content="1">'));

        expect(await sw.shellIsUsable(await cache.match(SHELL))).toBe(false);
    });
});

describe('the shell and its assets are cached together', () => {
    it('primes both in one go', async () => {
        const html = shellHtml();
        sw = loadWorker({
            fetch: async (request) => {
                const path = new URL(typeof request === 'string' ? request : request.url).pathname;

                return path === SHELL ? new Response(html) : new Response('/* bundle */');
            },
        });

        expect(await sw.primeShell()).toBe(true);

        const shellCache = await sw.caches.open(sw.SHELL_CACHE);
        const assetCache = await sw.caches.open(sw.ASSET_CACHE);

        expect(await shellCache.match(SHELL)).toBeDefined();
        expect(await assetCache.match(`${BASE}/build/assets/field-abc12345.js`)).toBeDefined();
        expect(await assetCache.match(`${BASE}/build/assets/field-def67890.css`)).toBeDefined();
    });

    it('reports failure when an asset could not be fetched', async () => {
        sw = loadWorker({
            fetch: async (request) => {
                const path = new URL(typeof request === 'string' ? request : request.url).pathname;

                if (path === SHELL) {
                    return new Response(shellHtml());
                }

                if (path.endsWith('.css')) {
                    throw new TypeError('Failed to fetch');
                }

                return new Response('/* bundle */');
            },
        });

        // One missing file means the shell would not open, so "mostly primed"
        // is not a thing this can report.
        expect(await sw.primeShell()).toBe(false);
    });

    it('does not offer a shell whose assets are missing', async () => {
        // Before this, the cached HTML named a bundle nobody had — which is
        // the state after every `npm run build` — and the page opened blank
        // or died on the missing script.
        sw = loadWorker();

        const cache = await sw.caches.open(sw.SHELL_CACHE);
        await cache.put(SHELL, new Response(shellHtml()));

        const response = await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        expect(response.status).toBe(503);
        expect(await response.text()).toContain('has not been set up');
    });

    it('serves a shell whose assets are all present', async () => {
        sw = loadWorker();

        const shellCache = await sw.caches.open(sw.SHELL_CACHE);
        const assetCache = await sw.caches.open(sw.ASSET_CACHE);

        await shellCache.put(SHELL, new Response(shellHtml()));
        await assetCache.put(`${BASE}/build/assets/field-abc12345.js`, new Response('/* js */'));
        await assetCache.put(`${BASE}/build/assets/field-def67890.css`, new Response('/* css */'));

        const response = await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        expect(response.status).toBe(200);
        expect(await response.text()).toContain('td-user');
    });
});

describe('nothing may ever fail to answer', () => {
    it('answers an uncacheable asset with a Response, not a rejection', async () => {
        // The original: a bare `await fetch(request)` with no catch. Offline
        // with a cache miss, the promise handed to respondWith REJECTED — and
        // that is precisely what ERR_FAILED means.
        sw = loadWorker();

        const response = await sw.cacheFirst(
            new Request(`${ORIGIN}${BASE}/build/assets/field-abc12345.js`),
            sw.ASSET_CACHE,
        );

        expect(response).toBeInstanceOf(Response);
    });

    it('makes a missing script say so out loud instead of leaving a blank page', async () => {
        sw = loadWorker();

        const response = await sw.cacheFirst(
            new Request(`${ORIGIN}${BASE}/build/assets/field-abc12345.js`),
            sw.ASSET_CACHE,
        );

        expect(response.headers.get('Content-Type')).toContain('javascript');
        expect(await response.text()).toContain('not fully downloaded');
    });

    it('answers a missing stylesheet without pretending it succeeded', async () => {
        sw = loadWorker();

        const response = await sw.cacheFirst(
            new Request(`${ORIGIN}${BASE}/build/assets/field-def67890.css`),
            sw.ASSET_CACHE,
        );

        expect(response.status).toBe(504);
    });

    it('answers a navigation with the door when there is nothing cached at all', async () => {
        sw = loadWorker();

        const response = await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        expect(response.status).toBe(503);
    });

    it('answers a failed admin navigation with the door rather than the browser error', async () => {
        sw = loadWorker();

        const response = await sw.adminOrDoor(new Request(`${ORIGIN}${BASE}/admin`));

        expect(response.status).toBe(503);
        expect(await response.text()).toContain('The main panel needs a connection');
    });
});

describe('the door tells the truth about what it is offering', () => {
    it('does not offer Field Mode when the cached shell could not open', async () => {
        // The user-visible bug exactly: the door said "Field Mode works
        // without one" and the link it offered led to a browser error page.
        sw = loadWorker();

        await poisonedButOtherwisePerfect(sw);

        expect(await sw.haveWorkingFieldMode()).toBe(false);

        const door = await sw.adminOrDoor(new Request(`${ORIGIN}${BASE}/admin`));
        const html = await door.text();

        expect(html).toContain('has not been set up on this device yet');
        expect(html).not.toContain('Field Mode works without one');
    });

    it('offers it when it really will open', async () => {
        sw = loadWorker();

        const shellCache = await sw.caches.open(sw.SHELL_CACHE);
        const assetCache = await sw.caches.open(sw.ASSET_CACHE);

        await shellCache.put(SHELL, new Response(shellHtml()));
        await assetCache.put(`${BASE}/build/assets/field-abc12345.js`, new Response('/* js */'));
        await assetCache.put(`${BASE}/build/assets/field-def67890.css`, new Response('/* css */'));

        expect(await sw.haveWorkingFieldMode()).toBe(true);

        const html = await (await sw.adminOrDoor(new Request(`${ORIGIN}${BASE}/admin`))).text();

        expect(html).toContain('Field Mode works without one');
        // And the link it offers points at this installation, not the origin root.
        expect(html).toContain(`href="${SHELL}"`);
    });

    it('agrees with what the readiness screen is told', async () => {
        // Two answers to one question is how the original report happened.
        sw = loadWorker();

        const shellCache = await sw.caches.open(sw.SHELL_CACHE);
        const assetCache = await sw.caches.open(sw.ASSET_CACHE);

        await shellCache.put(SHELL, new Response(shellHtml()));
        await assetCache.put(`${BASE}/build/assets/field-abc12345.js`, new Response('/* js */'));

        const report = await sw.cacheReport();

        expect(report.usable).toBe(false);
        expect(report.assets).toEqual({ wanted: 2, held: 1 });
        expect(await sw.haveWorkingFieldMode()).toBe(report.usable);
    });
});

describe('the online path', () => {
    it('caches the shell and its assets when the network answers', async () => {
        const html = shellHtml();
        sw = loadWorker({
            fetch: async (request) => {
                const path = new URL(typeof request === 'string' ? request : request.url).pathname;

                return path === SHELL ? new Response(html) : new Response('/* bundle */');
            },
        });

        const response = await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        expect(response.status).toBe(200);

        const shellCache = await sw.caches.open(sw.SHELL_CACHE);
        expect(await shellCache.match(SHELL)).toBeDefined();
    });

    it('never caches a redirect to the login page as the shell', async () => {
        sw = loadWorker({ fetch: async () => redirected(loginHtml()) });

        await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        const shellCache = await sw.caches.open(sw.SHELL_CACHE);
        expect(await shellCache.match(SHELL)).toBeUndefined();
    });

    it('never caches a failed response as the shell', async () => {
        sw = loadWorker({ fetch: async () => new Response('nope', { status: 500 }) });

        await sw.networkFirst(new Request(`${ORIGIN}${SHELL}`));

        const shellCache = await sw.caches.open(sw.SHELL_CACHE);
        expect(await shellCache.match(SHELL)).toBeUndefined();
    });
});
