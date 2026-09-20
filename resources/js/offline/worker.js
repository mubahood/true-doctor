import { appPath } from './base.js';

/**
 * Registering the service worker, and handling an update without eating
 * anybody's work.
 *
 * The update path is the dangerous one. A worker that takes over while
 * somebody is halfway through a form reloads the page and loses what they were
 * typing — and, if it went badly wrong, could serve a build that does not
 * understand the local schema. So the new worker WAITS, the user is told, and
 * nothing happens until they say so (plan §14).
 *
 * What this never does is touch IndexedDB. Neither does the worker. That is
 * what makes invariant I-6 — "a service-worker update never destroys pending
 * work" — true by construction rather than by care.
 */

const SCRIPT = '/field-sw.js';

/**
 * The worker's scope is the whole app, not just `/field`.
 *
 * It has to see a navigation to the ADMIN panel to answer a failed one with a
 * door into Field Mode — which needs no cached admin HTML, only the chance to
 * respond when the network has already failed. The fetch handler stays narrow:
 * everything it does not explicitly claim falls straight through.
 */
function scriptUrl() {
    return appPath(SCRIPT);
}

function scopeUrl() {
    // A worker can only control paths under its own directory, so the script
    // must be served from the app root for this scope to be allowed.
    return appPath('/');
}

export async function registerFieldWorker({ onUpdateReady = () => {} } = {}) {
    if (!('serviceWorker' in navigator)) {
        // Field Mode still works: the local database and the outbox do not
        // need a worker. Only the "open the app with no network at all" case
        // is lost, and saying so beats pretending.
        return { supported: false };
    }

    let registration;

    try {
        registration = await navigator.serviceWorker.register(scriptUrl(), { scope: scopeUrl() });
    } catch (error) {
        return { supported: true, registered: false, error: String(error?.message ?? error) };
    }

    // A worker already waiting means an update arrived while the tab was open.
    if (registration.waiting && navigator.serviceWorker.controller) {
        onUpdateReady(() => activate(registration));
    }

    registration.addEventListener('updatefound', () => {
        const installing = registration.installing;

        if (!installing) {
            return;
        }

        installing.addEventListener('statechange', () => {
            // `controller` being set means this is an UPDATE rather than the
            // very first install — on a first install there is nothing to
            // interrupt and nothing to ask about.
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                onUpdateReady(() => activate(registration));
            }
        });
    });

    return { supported: true, registered: true, registration };
}

/**
 * Take the update.
 *
 * The reload happens on `controllerchange`, after the new worker has actually
 * taken over — reloading before that gets the old one again.
 */
function activate(registration) {
    if (!registration.waiting) {
        globalThis.location.reload();

        return;
    }

    let reloading = false;

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) {
            return;
        }

        reloading = true;
        globalThis.location.reload();
    });

    registration.waiting.postMessage('td:skip-waiting');
}

/** Withdraw offline mode from this browser entirely. */
export async function unregisterFieldWorker() {
    if (!('serviceWorker' in navigator)) {
        return false;
    }

    const registrations = await navigator.serviceWorker.getRegistrations();
    let removed = false;

    for (const registration of registrations) {
        if (registration.active?.scriptURL?.endsWith(SCRIPT)) {
            removed = (await registration.unregister()) || removed;
        }
    }

    // The caches go too. The local DATABASE does not — that is the user's
    // work, and turning off a worker is not consent to delete it.
    for (const name of await caches.keys()) {
        if (name.startsWith('td-field-')) {
            await caches.delete(name);
        }
    }

    return removed;
}
