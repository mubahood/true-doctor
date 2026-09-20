import { TransportError, HttpError } from './transport.js';

/**
 * Whether the server is actually reachable.
 *
 * `navigator.onLine` answers "is there a network interface", which is not the
 * question. A captive portal at an airport, a VPN that dropped, a hospital
 * wifi that is up while the app server is down — all of them report `true`,
 * and the existing banner in the admin layout believes them.
 *
 * So the real signals, in order of authority:
 *
 *  1. **What actually happened to a request.** The strongest signal there is.
 *     A failed sync means offline whatever the browser claims.
 *  2. **A cheap probe** against `/sync/status`, with a short timeout.
 *  3. **The browser's events** — a hint that triggers a probe, never a
 *     conclusion on their own.
 *
 * `DEGRADED` exists because "reachable but answering 500s" is a real state
 * that is neither online nor offline: capture continues, sync backs off, and
 * the user is told something true rather than something reassuring.
 */

export const ONLINE = 'online';
export const OFFLINE = 'offline';
export const CONNECTING = 'connecting';
export const SYNCING = 'syncing';
export const SYNCED = 'synced';
export const DEGRADED = 'degraded';
export const SYNC_ERROR = 'sync_error';

const PROBE_WHILE_OFFLINE_MS = 15_000;
const PROBE_WHILE_ONLINE_MS = 60_000;

/** After this, "synced" stops being news and goes back to plain "online". */
const SYNCED_BADGE_MS = 8_000;

export class Connectivity {
    constructor({ transport, onChange = () => {}, now = () => Date.now() } = {}) {
        this.transport = transport;
        this.onChange = onChange;
        this.now = now;

        this.state = CONNECTING;
        this.lastReachableAt = null;
        this.lastProbeAt = null;
        this.consecutiveFailures = 0;
        this.timer = null;
        this.started = false;
    }

    start() {
        if (this.started) {
            return;
        }

        this.started = true;

        if (typeof globalThis.addEventListener === 'function') {
            // A hint, not a verdict: both handlers probe rather than decide.
            this._onOnline = () => this.probe();
            this._onOffline = () => this.set(OFFLINE);
            this._onVisible = () => {
                if (globalThis.document?.visibilityState === 'visible') {
                    this.probe();
                }
            };

            globalThis.addEventListener('online', this._onOnline);
            globalThis.addEventListener('offline', this._onOffline);
            globalThis.document?.addEventListener?.('visibilitychange', this._onVisible);
        }

        this.probe();
        this._schedule();
    }

    stop() {
        this.started = false;
        clearTimeout(this.timer);

        if (typeof globalThis.removeEventListener === 'function') {
            globalThis.removeEventListener('online', this._onOnline);
            globalThis.removeEventListener('offline', this._onOffline);
            globalThis.document?.removeEventListener?.('visibilitychange', this._onVisible);
        }
    }

    _schedule() {
        clearTimeout(this.timer);

        if (!this.started) {
            return;
        }

        // A hidden tab probes nothing. A ward screen left open overnight should
        // not spend the night waking the radio.
        if (globalThis.document?.visibilityState === 'hidden') {
            this.timer = setTimeout(() => this._schedule(), PROBE_WHILE_ONLINE_MS);

            return;
        }

        const delay = this.isReachable() ? PROBE_WHILE_ONLINE_MS : PROBE_WHILE_OFFLINE_MS;

        this.timer = setTimeout(() => {
            this.probe().finally(() => this._schedule());
        }, delay);
    }

    /** Ask the server whether it is there. */
    async probe() {
        this.lastProbeAt = this.now();

        try {
            const status = await this.transport.status();

            this.consecutiveFailures = 0;
            this.lastReachableAt = this.now();
            this.set(ONLINE);

            return status;
        } catch (error) {
            this.observeFailure(error);

            return null;
        }
    }

    /**
     * Learn from a request that already happened.
     *
     * This is what makes the state honest: the sync engine's own failures feed
     * straight in, so the indicator cannot say "online" while every push is
     * timing out.
     */
    observeFailure(error) {
        this.consecutiveFailures++;

        if (error instanceof TransportError) {
            this.set(OFFLINE);

            return;
        }

        if (error instanceof HttpError) {
            // 401 is not a network problem and must not be reported as one —
            // the user needs to sign in, not wait for a signal.
            if (error.status === 401 || error.status === 403) {
                this.set(this.state === OFFLINE ? OFFLINE : ONLINE);

                return;
            }

            // Reachable, but not well. Capture continues; sync backs off.
            this.set(DEGRADED);

            return;
        }

        this.set(OFFLINE);
    }

    observeSuccess() {
        this.consecutiveFailures = 0;
        this.lastReachableAt = this.now();

        if (this.state !== SYNCING) {
            this.set(ONLINE);
        }
    }

    syncStarted() {
        this.set(SYNCING);
    }

    syncFinished(summary) {
        if (summary?.errors?.length) {
            this.set(SYNC_ERROR);

            return;
        }

        this.lastReachableAt = this.now();
        this.set(SYNCED);

        // "Synced" is news for a moment, then it is just "online".
        setTimeout(() => {
            if (this.state === SYNCED) {
                this.set(ONLINE);
            }
        }, SYNCED_BADGE_MS);
    }

    set(state) {
        if (this.state === state) {
            return;
        }

        this.state = state;
        this.onChange(this.describe());
    }

    isReachable() {
        return [ONLINE, SYNCING, SYNCED, DEGRADED].includes(this.state);
    }

    /** Everything the indicator needs, in words a person can read. */
    describe() {
        const labels = {
            [ONLINE]: 'Online',
            [OFFLINE]: 'Working offline',
            [CONNECTING]: 'Checking the connection…',
            [SYNCING]: 'Syncing…',
            [SYNCED]: 'Everything is saved',
            [DEGRADED]: 'The server is having trouble',
            [SYNC_ERROR]: 'Some changes could not be sent',
        };

        const explanations = {
            [OFFLINE]: 'Your work is being saved on this device and will be sent when the connection returns.',
            [DEGRADED]: 'It is reachable but not answering properly. Your work is safe and will be sent again shortly.',
            [SYNC_ERROR]: 'Nothing has been lost. Open the sync panel to see what needs attention.',
        };

        return {
            state: this.state,
            label: labels[this.state] ?? this.state,
            explanation: explanations[this.state] ?? null,
            reachable: this.isReachable(),
            lastReachableAt: this.lastReachableAt,
            consecutiveFailures: this.consecutiveFailures,
        };
    }
}

export { PROBE_WHILE_OFFLINE_MS, PROBE_WHILE_ONLINE_MS };
