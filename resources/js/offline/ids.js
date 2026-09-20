/**
 * Identifiers for records and operations created on a device.
 *
 * Two kinds, for two different jobs:
 *
 *  - **UUID v4** identifies a RECORD. It is collision-resistant, carries no
 *    information, and is what the server already uses on twenty of its tables —
 *    so an offline-created patient arrives with the identity it will keep for
 *    life (invariant I-8).
 *
 *  - **ULID** identifies an OPERATION. It is sortable by creation time, which
 *    is the whole point: the outbox has to ship a visit before the vitals
 *    recorded on it, and a lexicographic sort of the operation ids is that
 *    order for free. A UUID v4 would have needed a separate sequence column
 *    that a clock change could scramble.
 *
 * Neither is ever allocated for a HUMAN-FACING number. `patient_no`,
 * `visit_no` and `invoice_no` come from the server's `Sequence` under a row
 * lock; a device that invented one would hand somebody a number that later
 * changes (plan §11, invariant I-11).
 */

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // no I, L, O, U

/** @returns {string} a v4 UUID, from the platform where it exists. */
export function uuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    // Older Safari and some WebViews have getRandomValues but not randomUUID.
    const bytes = randomBytes(16);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * A ULID: 48 bits of millisecond timestamp + 80 bits of randomness, Crockford
 * base32, 26 characters, sortable as a string.
 *
 * Monotonic within a millisecond: two operations enqueued in the same tick
 * must not sort arbitrarily, or a create and the update that follows it could
 * swap. The random component is incremented rather than redrawn, which is what
 * the ULID spec calls for.
 */
let lastMs = 0;
let lastRandom = null;

export function ulid(now = Date.now()) {
    // A clock that went backwards must not produce ids that sort before ones
    // already in the outbox. Pin to the last time we saw and keep incrementing.
    const ms = now > lastMs ? now : lastMs;

    if (ms === lastMs && lastRandom !== null) {
        lastRandom = increment(lastRandom);
    } else {
        lastRandom = randomBytes(10);
    }

    lastMs = ms;

    return encodeTime(ms) + encodeRandom(lastRandom);
}

/** Exposed for tests that need a clean monotonic state. */
export function resetUlidState() {
    lastMs = 0;
    lastRandom = null;
}

function randomBytes(n) {
    const bytes = new Uint8Array(n);

    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(bytes);

        return bytes;
    }

    // No CSPRNG at all. This is not a security boundary — the id only has to
    // not collide — but it is worth not pretending otherwise.
    for (let i = 0; i < n; i++) {
        bytes[i] = Math.floor(Math.random() * 256);
    }

    return bytes;
}

function increment(bytes) {
    const out = Uint8Array.from(bytes);

    for (let i = out.length - 1; i >= 0; i--) {
        if (out[i] < 255) {
            out[i]++;

            return out;
        }
        out[i] = 0;
    }

    // Overflowed 80 bits inside one millisecond, which cannot happen at any
    // rate a UI produces. Start again rather than return a duplicate.
    return randomBytes(out.length);
}

function encodeTime(ms) {
    let out = '';

    for (let i = 9; i >= 0; i--) {
        out = CROCKFORD[ms % 32] + out;
        ms = Math.floor(ms / 32);
    }

    return out;
}

function encodeRandom(bytes) {
    // 80 bits → 16 base32 characters, read as one big-endian bit string.
    let bits = '';
    for (const b of bytes) {
        bits += b.toString(2).padStart(8, '0');
    }

    let out = '';
    for (let i = 0; i < 80; i += 5) {
        out += CROCKFORD[parseInt(bits.slice(i, i + 5), 2)];
    }

    return out;
}

/** The millisecond a ULID was minted, for diagnostics and expiry checks. */
export function ulidTime(id) {
    let ms = 0;

    for (const ch of id.slice(0, 10)) {
        const v = CROCKFORD.indexOf(ch);
        if (v < 0) {
            return null;
        }
        ms = ms * 32 + v;
    }

    return ms;
}
