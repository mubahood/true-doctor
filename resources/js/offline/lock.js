/**
 * One sync at a time, across every tab this browser has open.
 *
 * Two tabs syncing together is not merely wasteful. They read the same due
 * operations, mark the same ones `processing`, and push the same batch twice.
 * The server deduplicates, so nothing is written twice — but the SECOND tab
 * gets `already_processed` for work the first tab has not finished recording
 * locally yet, and the local bookkeeping ends up describing a state that never
 * happened.
 *
 * The Web Locks API is exactly the right tool and is available in every
 * browser this system targets. The fallback exists because a lock is a
 * correctness requirement, not a nicety: a browser without Web Locks must
 * still be prevented from syncing twice, so it falls back to a lease in
 * `localStorage` — weaker (it cannot detect a tab that was killed mid-sync
 * except by timeout) but far better than nothing.
 */

const LOCK_NAME = 'td-offline-sync';
const LEASE_KEY = 'td-offline-sync-lease';

/** A tab that dies holding the fallback lease must not block the others for
 *  ever. Long enough for a slow batch, short enough to recover a shift. */
const LEASE_MS = 90_000;

export function supportsWebLocks() {
    return typeof navigator !== 'undefined' && navigator.locks && typeof navigator.locks.request === 'function';
}

/**
 * Run `work` while holding the sync lock. Returns `{ ran: false }` without
 * waiting if another tab already holds it — a queued second sync would only
 * repeat what the first is doing.
 *
 * @param {() => Promise<T>} work
 * @returns {Promise<{ran: boolean, result?: T}>}
 * @template T
 */
export async function withSyncLock(work, options = {}) {
    const storage = options.storage ?? globalThis.localStorage;
    const now = options.now ?? (() => Date.now());

    if (!options.forceFallback && supportsWebLocks()) {
        let outcome = { ran: false };

        await navigator.locks.request(LOCK_NAME, { ifAvailable: true }, async (lock) => {
            if (lock === null) {
                return;
            }

            outcome = { ran: true, result: await work() };
        });

        return outcome;
    }

    return leaseLock(work, storage, now, options.owner ?? randomOwner());
}

function randomOwner() {
    return Math.random().toString(36).slice(2) + now36();
}

function now36() {
    return Date.now().toString(36);
}

/**
 * The fallback. Not as good as Web Locks and not pretending to be: two tabs
 * can still both take it if they read and write in the same millisecond. The
 * re-read after writing narrows that window to almost nothing, and the worst
 * case is the thing the server's idempotency already makes safe.
 */
async function leaseLock(work, storage, now, owner) {
    if (!storage) {
        // No storage at all — a private window with everything blocked. Run
        // rather than refuse: one tab syncing is the normal case, and refusing
        // would mean a browser that can never sync.
        return { ran: true, result: await work() };
    }

    const current = readLease(storage);

    if (current && current.until > now() && current.owner !== owner) {
        return { ran: false };
    }

    writeLease(storage, { owner, until: now() + LEASE_MS });

    // Re-read: if another tab wrote between our read and our write, its value
    // is there now and we back off.
    if (readLease(storage)?.owner !== owner) {
        return { ran: false };
    }

    try {
        return { ran: true, result: await work() };
    } finally {
        if (readLease(storage)?.owner === owner) {
            try {
                storage.removeItem(LEASE_KEY);
            } catch {
                // Quota or a locked-down profile. The lease expires anyway.
            }
        }
    }
}

function readLease(storage) {
    try {
        const raw = storage.getItem(LEASE_KEY);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeLease(storage, lease) {
    try {
        storage.setItem(LEASE_KEY, JSON.stringify(lease));
    } catch {
        // Nothing to do: the caller proceeds, and the server is the real guard.
    }
}

/**
 * Tell the other tabs something happened, so a second screen showing the same
 * patient does not sit there stale.
 *
 * BroadcastChannel where it exists; a `localStorage` write otherwise, which
 * fires a `storage` event in every OTHER tab and is the oldest trick there is.
 */
export class TabChannel {
    constructor(name = 'td-offline', options = {}) {
        this.name = name;
        this.listeners = new Set();
        this.storage = options.storage ?? globalThis.localStorage;

        if (typeof BroadcastChannel === 'function') {
            this.channel = new BroadcastChannel(name);
            this.channel.onmessage = (event) => this._emit(event.data);
        } else if (typeof globalThis.addEventListener === 'function') {
            this._onStorage = (event) => {
                if (event.key === `${name}-signal` && event.newValue) {
                    try {
                        this._emit(JSON.parse(event.newValue));
                    } catch {
                        // A half-written value from a tab that died mid-write.
                    }
                }
            };
            globalThis.addEventListener('storage', this._onStorage);
        }
    }

    post(message) {
        if (this.channel) {
            this.channel.postMessage(message);

            return;
        }

        try {
            // The value has to differ every time or the event does not fire.
            this.storage?.setItem(`${this.name}-signal`, JSON.stringify({ ...message, _at: Date.now() }));
        } catch {
            // Storage unavailable; other tabs simply refresh on their own.
        }
    }

    on(listener) {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    _emit(message) {
        for (const listener of this.listeners) {
            try {
                listener(message);
            } catch {
                // One bad listener must not stop the others hearing it.
            }
        }
    }

    close() {
        this.channel?.close();

        if (this._onStorage) {
            globalThis.removeEventListener('storage', this._onStorage);
        }

        this.listeners.clear();
    }
}

export { LEASE_MS, LOCK_NAME, LEASE_KEY };
