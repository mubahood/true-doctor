import { describe, it, expect } from 'vitest';
import { Connectivity, ONLINE, OFFLINE, DEGRADED, SYNCING, SYNCED, SYNC_ERROR } from '../../resources/js/offline/connectivity.js';
import { TransportError, HttpError } from '../../resources/js/offline/transport.js';

/**
 * Knowing whether the server is actually there.
 *
 * The bug this module exists to prevent is the one already in the admin
 * layout: `navigator.onLine` is true on hospital wifi whose app server is
 * down, and the banner says "you're offline — changes can't be saved" or
 * nothing at all, both of which are wrong.
 */

function probeReturning(sequence) {
    let i = 0;

    return {
        async status() {
            const next = sequence[Math.min(i++, sequence.length - 1)];

            if (next instanceof Error) {
                throw next;
            }

            return next;
        },
    };
}

describe('connectivity', () => {
    it('reports online once the server actually answers', async () => {
        const c = new Connectivity({ transport: probeReturning([{ protocol_version: 1 }]) });

        await c.probe();

        expect(c.state).toBe(ONLINE);
        expect(c.isReachable()).toBe(true);
        expect(c.lastReachableAt).toBeTruthy();
    });

    it('reports offline when the server cannot be reached, whatever the browser says', async () => {
        const c = new Connectivity({ transport: probeReturning([new TransportError('Could not reach the server.')]) });

        await c.probe();

        // The case that matters: connected to wifi, no server behind it.
        expect(c.state).toBe(OFFLINE);
        expect(c.isReachable()).toBe(false);
        expect(c.describe().explanation).toMatch(/saved on this device/);
    });

    it('distinguishes a server that is there but unwell', async () => {
        const c = new Connectivity({ transport: probeReturning([new HttpError(503, null, 'Service unavailable')]) });

        await c.probe();

        // Neither online nor offline: capture continues, sync backs off, and
        // the user is told something true rather than something reassuring.
        expect(c.state).toBe(DEGRADED);
        expect(c.isReachable()).toBe(true);
        expect(c.describe().label).toMatch(/trouble/);
    });

    it('does not call an expired session a network problem', async () => {
        const c = new Connectivity({ transport: probeReturning([{ ok: true }]) });
        await c.probe();

        c.observeFailure(new HttpError(401, null, 'Unauthenticated.'));

        // The user needs to sign in, not wait for a signal.
        expect(c.state).toBe(ONLINE);
    });

    it('learns from a request that already failed, not only from its own probe', async () => {
        const c = new Connectivity({ transport: probeReturning([{ ok: true }]) });
        await c.probe();
        expect(c.state).toBe(ONLINE);

        // The sync engine's own failure feeds straight in, so the indicator
        // cannot say "online" while every push is timing out.
        c.observeFailure(new TransportError('timeout'));

        expect(c.state).toBe(OFFLINE);
        expect(c.consecutiveFailures).toBe(1);
    });

    it('counts consecutive failures and resets on success', async () => {
        const c = new Connectivity({ transport: probeReturning([{ ok: true }]) });

        c.observeFailure(new TransportError('a'));
        c.observeFailure(new TransportError('b'));
        expect(c.consecutiveFailures).toBe(2);

        c.observeSuccess();
        expect(c.consecutiveFailures).toBe(0);
        expect(c.state).toBe(ONLINE);
    });

    it('shows syncing, then saved, then settles back to online', async () => {
        const seen = [];
        const c = new Connectivity({
            transport: probeReturning([{ ok: true }]),
            onChange: (d) => seen.push(d.state),
        });

        c.syncStarted();
        expect(c.state).toBe(SYNCING);

        c.syncFinished({ errors: [] });
        expect(c.state).toBe(SYNCED);
        expect(c.describe().label).toBe('Everything is saved');

        expect(seen).toContain(SYNCING);
        expect(seen).toContain(SYNCED);
    });

    it('says so when a sync came back with problems, without alarming', async () => {
        const c = new Connectivity({ transport: probeReturning([{ ok: true }]) });

        c.syncFinished({ errors: ['one push failed'] });

        expect(c.state).toBe(SYNC_ERROR);
        // Nothing has been lost, and the message says that first.
        expect(c.describe().explanation).toMatch(/Nothing has been lost/);
    });

    it('only tells the listener when something actually changed', async () => {
        let calls = 0;
        const c = new Connectivity({
            transport: probeReturning([{ ok: true }, { ok: true }, { ok: true }]),
            onChange: () => calls++,
        });

        await c.probe();
        await c.probe();
        await c.probe();

        // Three probes, one state change — an indicator that repaints on every
        // poll is an indicator people learn to ignore.
        expect(calls).toBe(1);
    });

    it('describes every state in words a person can act on', () => {
        const c = new Connectivity({ transport: probeReturning([{ ok: true }]) });

        for (const state of [ONLINE, OFFLINE, DEGRADED, SYNCING, SYNCED, SYNC_ERROR]) {
            c.state = state;
            const described = c.describe();

            expect(described.label).toBeTruthy();
            expect(described.label).not.toBe(state); // not the raw enum
        }
    });
});
