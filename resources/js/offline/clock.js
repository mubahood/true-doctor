/**
 * Time on a device you do not control.
 *
 * A vitals round stamped three hours wrong is a clinical record nobody can
 * interpret, and device clocks are wrong all the time — a laptop that slept
 * through a timezone change, a workstation whose CMOS battery died, a tablet
 * somebody set forward to beat a lock screen.
 *
 * So two clocks are kept and both are recorded:
 *
 *  - `clientNow()` is the device's own wall clock. It is what the user sees
 *    and what goes into `client_created_at` — provenance, not authority.
 *  - The SERVER's clock is authority. Ordering decisions, revisions and
 *    `created_at` are all assigned server-side (plan §7.3).
 *
 * The offset between them is measured at every successful sync and used for
 * one job only: deciding whether this device is fit to capture at all.
 */

const SKEW_WARN_MS = 5 * 60 * 1000;   // past this, say so
const SKEW_BLOCK_MS = 60 * 60 * 1000; // past this, stop taking clinical records

/** Monotonic milliseconds that a clock change cannot move. */
function monotonic() {
    return typeof performance !== 'undefined' && typeof performance.now === 'function'
        ? performance.now()
        : Date.now();
}

export class Clock {
    constructor() {
        this.offsetMs = 0;          // serverTime - deviceTime, at the last sync
        this.measuredAt = null;     // monotonic reading when that was taken
        this.measuredWall = null;   // wall clock at the same instant
    }

    /**
     * Record the server's view of now.
     *
     * `roundTripMs` lets us subtract half the round trip, which is the usual
     * approximation — we cannot know how the latency split, but assuming it
     * was symmetric beats assuming it was zero.
     */
    syncWithServer(serverIso, roundTripMs = 0) {
        const server = Date.parse(serverIso);

        if (Number.isNaN(server)) {
            return false;
        }

        const device = Date.now();
        this.offsetMs = server + Math.round(roundTripMs / 2) - device;
        this.measuredAt = monotonic();
        this.measuredWall = device;

        return true;
    }

    /** The device's own wall clock, as an ISO string with offset. */
    clientNow() {
        return new Date().toISOString();
    }

    /** Our best guess at the server's clock. Never persisted as authority. */
    estimatedServerNow() {
        return new Date(Date.now() + this.offsetMs).toISOString();
    }

    /**
     * Has the wall clock jumped since we last measured?
     *
     * Monotonic time and wall time should advance together. When they disagree
     * by more than a second, somebody moved the clock — which invalidates the
     * offset we measured and every timestamp taken since.
     */
    detectJump() {
        if (this.measuredAt === null) {
            return 0;
        }

        const monotonicElapsed = monotonic() - this.measuredAt;
        const wallElapsed = Date.now() - this.measuredWall;

        return Math.abs(wallElapsed - monotonicElapsed) > 1000
            ? wallElapsed - monotonicElapsed
            : 0;
    }

    /**
     * @returns {{state: 'ok'|'warn'|'blocked', skewMs: number, jumpMs: number, message: string|null}}
     */
    health() {
        const jumpMs = this.detectJump();
        const skewMs = Math.abs(this.offsetMs) + Math.abs(jumpMs);

        if (this.measuredAt === null) {
            // Never synced. We cannot judge the clock, so we do not pretend to;
            // the device simply has not been trusted with offline capture yet.
            return { state: 'warn', skewMs: 0, jumpMs: 0, message: 'This device has not checked its clock against the server yet.' };
        }

        if (skewMs >= SKEW_BLOCK_MS) {
            return {
                state: 'blocked',
                skewMs,
                jumpMs,
                message: `This device's clock is out by about ${Math.round(skewMs / 60000)} minutes. Correct it before recording anything — a record stamped with the wrong time cannot be read back safely.`,
            };
        }

        if (skewMs >= SKEW_WARN_MS) {
            return {
                state: 'warn',
                skewMs,
                jumpMs,
                message: `This device's clock is out by about ${Math.round(skewMs / 60000)} minutes. Times you record will be corrected when they sync.`,
            };
        }

        return { state: 'ok', skewMs, jumpMs, message: null };
    }

    /** Clinical capture is refused outright on a badly wrong clock. */
    mayCapture() {
        return this.health().state !== 'blocked';
    }
}

export const clock = new Clock();
export { SKEW_WARN_MS, SKEW_BLOCK_MS };
