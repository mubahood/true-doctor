/**
 * Where this application lives.
 *
 * Not at the origin root — this installation is served from `/true-doctor/`,
 * and an earlier version of the offline client hardcoded `/field`, `/api/v1`
 * and `/field-sw.js`. Every one of those resolved to a path that does not
 * exist, so the service worker never registered, the API was never reachable
 * and offline mode silently did not work at all.
 *
 * The base comes from a meta tag the server renders from `url('/')`, because
 * the server is the only thing that actually knows. Falling back to deriving
 * it from the current path keeps a page working if the tag is ever missing,
 * rather than failing in the same silent way.
 */

let cached = null;

export function appBase() {
    if (cached !== null) {
        return cached;
    }

    const meta = globalThis.document?.querySelector('meta[name="td-base"]')?.content;

    if (meta) {
        cached = normalise(meta);

        return cached;
    }

    // No tag. Derive it from where we are: `/true-doctor/field` → `/true-doctor`.
    const here = globalThis.location?.pathname ?? '';
    const match = here.match(/^(.*?)\/(field|admin)(\/|$)/);

    cached = match ? normalise(match[1]) : '';

    return cached;
}

/** An absolute path within the app: `url('/field')` → `/true-doctor/field`. */
export function appPath(suffix = '') {
    return `${appBase()}${suffix}`;
}

/** For tests, and for a client that switches installations mid-session. */
export function setAppBase(base) {
    cached = base === null ? null : normalise(base);
}

/**
 * `http://localhost:8888/true-doctor/` → `/true-doctor`
 * `/true-doctor/`                      → `/true-doctor`
 * `/`                                  → ``
 */
function normalise(value) {
    let path = value;

    if (/^https?:\/\//i.test(path)) {
        try {
            path = new URL(path).pathname;
        } catch {
            path = '';
        }
    }

    return path.replace(/\/+$/, '');
}
