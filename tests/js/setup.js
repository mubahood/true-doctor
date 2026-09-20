import 'fake-indexeddb/auto';
import { webcrypto } from 'node:crypto';

// Node has had webcrypto on `globalThis.crypto` since 19, but `randomUUID` and
// `getRandomValues` are what the id module reaches for and it is worth being
// explicit rather than depending on the runtime's mood.
if (!globalThis.crypto) {
    globalThis.crypto = webcrypto;
}

/**
 * A `localStorage` that behaves like the browser's.
 *
 * Node has none, and without it the cross-tab lock falls through to its last
 * resort — "no storage at all, so run anyway" — which is the one path a test
 * for the lock must not take. Shimming it means the fallback lease is
 * exercised on every run instead of being dead code nobody measures.
 */
if (!globalThis.localStorage) {
    const store = new Map();

    globalThis.localStorage = {
        getItem: (key) => (store.has(key) ? store.get(key) : null),
        setItem: (key, value) => store.set(key, String(value)),
        removeItem: (key) => store.delete(key),
        clear: () => store.clear(),
        key: (i) => [...store.keys()][i] ?? null,
        get length() {
            return store.size;
        },
    };
}
