import { ulid, ulidTime } from './ids.js';

/**
 * The durable operation queue.
 *
 * Everything a user does offline becomes a row here, and the one rule the
 * whole design hangs on is invariant I-3:
 *
 *   **An operation leaves the outbox only by being synced, or by an explicit,
 *   audited cancellation. Never by expiry, never by cleanup, never because a
 *   retry budget ran out.**
 *
 * A retry budget running out makes an operation `failed`, which is a state it
 * sits in visibly until a human retries it or throws it away on purpose. The
 * alternative — quietly dropping work after twenty-four attempts — is exactly
 * the failure the plan exists to prevent, and it would be invisible.
 */

export const OP_PENDING = 'pending';
export const OP_PROCESSING = 'processing';
export const OP_SYNCED = 'synced';
export const OP_CONFLICT = 'conflict';
export const OP_REJECTED = 'rejected';
export const OP_FAILED = 'failed';
export const OP_CANCELLED = 'cancelled';

/** States that still want the network. */
export const LIVE_STATES = [OP_PENDING, OP_PROCESSING];

/** States a human has to look at. */
export const ATTENTION_STATES = [OP_CONFLICT, OP_REJECTED, OP_FAILED];

/**
 * 5s · 15s · 30s · 60s · 5m · 15m, then hourly.
 *
 * Jittered by ±20% because a ward of tablets reconnecting to the same
 * wifi access point at the same moment would otherwise arrive as one spike.
 */
const BACKOFF_MS = [5_000, 15_000, 30_000, 60_000, 300_000, 900_000];
const BACKOFF_CAP_MS = 3_600_000;
export const MAX_ATTEMPTS = 24;

/** The server prunes its idempotency ledger at 90 days; past that a replay
 *  cannot be recognised as one, so the client must not send it blind. */
export const OPERATION_MAX_AGE_MS = 90 * 24 * 3600 * 1000;

export function backoffFor(retryCount, random = Math.random) {
    const base = retryCount < BACKOFF_MS.length ? BACKOFF_MS[retryCount] : BACKOFF_CAP_MS;
    const jitter = base * 0.2 * (random() * 2 - 1);

    return Math.max(1000, Math.round(base + jitter));
}

/**
 * Build an operation. Pure — it touches no storage — so the caller can put it
 * in the same transaction as the entity write it describes.
 */
export function buildOperation({
    entity,
    entityUuid,
    operation,
    payload,
    baseVersion = null,
    baseFields = null,
    deviceId,
    userId,
    dependsOn = [],
    atomicGroupId = null,
    clientCreatedAt,
}) {
    if (!entity || !entityUuid || !operation) {
        throw new Error('An outbox operation needs an entity, an entity uuid and an operation.');
    }

    return {
        operation_id: ulid(),
        entity,
        entity_uuid: entityUuid,
        operation,
        // Complete, never a diff. A delta computed against a base the server
        // has since moved past is unapplicable; a whole payload plus the
        // version it was written against lets the server decide (plan §8).
        payload,
        base_version: baseVersion,
        // What the server last told this device these fields held. The third
        // side of a three-way merge (plan §10.1).
        base_fields: baseFields,
        created_at: clientCreatedAt ?? new Date().toISOString(),
        device_id: deviceId,
        user_id: userId,
        status: OP_PENDING,
        retry_count: 0,
        next_retry_at: null,
        last_error: null,
        depends_on: dependsOn,
        atomic_group_id: atomicGroupId,
        // Filled from the push result, so a synced operation still says what
        // the server made of it without a second lookup.
        result: null,
    };
}

export class Outbox {
    constructor(db) {
        this.db = db;
    }

    /**
     * Operations ready to go, oldest first.
     *
     * "Ready" means pending, not waiting out a backoff, and — critically — not
     * blocked behind a dependency that has not landed yet. A vitals round
     * whose visit is still queued must not be sent on its own: the server
     * would reject it for referencing a visit that does not exist, and the
     * rejection would look like a data problem rather than an ordering one.
     */
    async due(limit = 50, now = Date.now()) {
        // Every operation, not only the pending ones: a dependency that has
        // been REJECTED, or is still in flight, must block its dependants —
        // and neither shows up in a query for `pending`. Reading only the
        // pending set made an unsatisfied dependency look satisfied simply
        // because it was no longer queued, which would send a vitals round
        // whose visit the server had just refused.
        const all = await this.db.outbox.toArray();
        const byId = new Map(all.map((op) => [op.operation_id, op]));

        const candidates = all
            .filter((op) => op.status === OP_PENDING)
            // ULIDs sort by mint time, so this is enqueue order (plan §8.2).
            .sort((a, b) => (a.operation_id < b.operation_id ? -1 : 1));

        const ready = [];
        const readyIds = new Set();
        const blocked = new Set();

        for (const op of candidates) {
            if (op.next_retry_at && Date.parse(op.next_retry_at) > now) {
                blocked.add(op.operation_id);
                continue;
            }

            const held = (op.depends_on ?? []).some(
                (id) => !this._dependencyIsClear(id, byId, readyIds, blocked),
            );

            if (held) {
                blocked.add(op.operation_id);
                continue;
            }

            ready.push(op);
            readyIds.add(op.operation_id);

            if (ready.length >= limit) {
                break;
            }
        }

        return ready;
    }

    /**
     * May an operation that depends on `id` go now?
     *
     *  - gone from the outbox, or synced → yes, it landed.
     *  - already in this batch, ahead of us → yes, it goes first.
     *  - anything else (pending but held, processing, failed, rejected, in
     *    conflict, cancelled) → no. Sending a child whose parent has not
     *    landed produces a server-side rejection that reads like a data
     *    problem when it is really an ordering one.
     */
    _dependencyIsClear(id, byId, readyIds, blocked) {
        if (readyIds.has(id)) {
            return true;
        }

        if (blocked.has(id)) {
            return false;
        }

        const dep = byId.get(id);

        return dep === undefined || dep.status === OP_SYNCED;
    }

    /**
     * Refuse the children of an operation the server turned down.
     *
     * They are marked rejected rather than left pending, because a pending
     * operation that can never be satisfied is a queue that never drains and
     * a pending count that never reaches zero — the user would be told they
     * have unsaved work for ever with no way to act on it.
     *
     * @returns {Promise<number>} how many were cascaded
     */
    async rejectDependantsOf(operationIds, reason = 'The operation this depended on was refused.') {
        const seen = new Set(operationIds);
        const frontier = [...operationIds];
        let touched = 0;

        while (frontier.length) {
            const parent = frontier.shift();

            const children = (await this.db.outbox
                .where('status')
                .anyOf([OP_PENDING, OP_PROCESSING])
                .toArray()).filter((op) => (op.depends_on ?? []).includes(parent));

            for (const child of children) {
                if (seen.has(child.operation_id)) {
                    continue;
                }

                seen.add(child.operation_id);
                frontier.push(child.operation_id);

                await this.db.outbox.update(child.operation_id, {
                    status: OP_REJECTED,
                    next_retry_at: null,
                    last_error: reason,
                });

                touched++;
            }
        }

        return touched;
    }

    async countByStatus() {
        const rows = await this.db.outbox.toArray();

        return rows.reduce((acc, op) => {
            acc[op.status] = (acc[op.status] ?? 0) + 1;

            return acc;
        }, {});
    }

    async pendingCount() {
        return this.db.outbox.where('status').anyOf(LIVE_STATES).count();
    }

    async needingAttention() {
        return this.db.outbox.where('status').anyOf(ATTENTION_STATES).toArray();
    }

    /** Mark a batch as in flight, so a second tab cannot pick it up. */
    async markProcessing(operationIds) {
        await this.db.outbox.where('operation_id').anyOf(operationIds).modify({
            status: OP_PROCESSING,
        });
    }

    /**
     * Put an in-flight batch back.
     *
     * Called when a push never got a reply — the request may or may not have
     * reached the server, so the operations go back to pending and are sent
     * again. That is safe precisely because the server deduplicates on
     * `operation_id`; without idempotency this method would be a duplicate
     * factory (plan §9.3).
     */
    async releaseProcessing(operationIds, error = null, now = Date.now(), random = Math.random) {
        await this.db.transaction('rw', this.db.outbox, async () => {
            const ops = await this.db.outbox.where('operation_id').anyOf(operationIds).toArray();

            for (const op of ops) {
                const retry = (op.retry_count ?? 0) + 1;
                const exhausted = retry >= MAX_ATTEMPTS;

                await this.db.outbox.update(op.operation_id, {
                    // Still in the outbox either way. `failed` means "a human
                    // must look", not "discarded" (invariant I-3).
                    status: exhausted ? OP_FAILED : OP_PENDING,
                    retry_count: retry,
                    next_retry_at: exhausted
                        ? null
                        : new Date(now + backoffFor(retry, random)).toISOString(),
                    last_error: error ? String(error).slice(0, 500) : op.last_error,
                });
            }
        });
    }

    /** Wind an operation back for another try — from the dashboard, or after
     *  the user fixed whatever the server complained about. */
    async retry(operationId, now = Date.now()) {
        await this.db.outbox.update(operationId, {
            status: OP_PENDING,
            retry_count: 0,
            next_retry_at: new Date(now).toISOString(),
            last_error: null,
        });
    }

    async retryAllFailed(now = Date.now()) {
        const ops = await this.db.outbox.where('status').anyOf([OP_FAILED, OP_REJECTED]).toArray();

        for (const op of ops) {
            await this.retry(op.operation_id, now);
        }

        return ops.length;
    }

    /**
     * Throw an operation away. The only path out of the outbox other than a
     * successful sync, and it writes an audit row saying who did it and why.
     */
    async cancel(operationId, reason, actor) {
        await this.db.transaction('rw', this.db.outbox, this.db.audit, async () => {
            const op = await this.db.outbox.get(operationId);

            if (!op || op.status === OP_SYNCED) {
                return;
            }

            await this.db.outbox.update(operationId, {
                status: OP_CANCELLED,
                last_error: reason ?? 'Cancelled',
            });

            await this.db.audit.add({
                operation_id: operationId,
                entity_uuid: op.entity_uuid,
                at: new Date().toISOString(),
                event: 'operation_cancelled',
                detail: { reason: reason ?? null, actor: actor ?? null, entity: op.entity },
            });
        });
    }

    /**
     * Operations too old for the server to recognise as replays.
     *
     * They are not deleted — they are marked `failed` with a reason, because
     * the payload is still the only copy of that work and a human may want to
     * re-enter it. Sending them blind is the one thing that would be unsafe.
     */
    async expireStale(now = Date.now()) {
        const stale = [];
        const ops = await this.db.outbox.where('status').anyOf([OP_PENDING, OP_FAILED]).toArray();

        for (const op of ops) {
            const minted = ulidTime(op.operation_id) ?? Date.parse(op.created_at);

            if (minted && now - minted > OPERATION_MAX_AGE_MS && op.status !== OP_FAILED) {
                stale.push(op.operation_id);
            }
        }

        if (stale.length) {
            await this.db.outbox.where('operation_id').anyOf(stale).modify({
                status: OP_FAILED,
                next_retry_at: null,
                last_error: 'Older than 90 days — the server can no longer tell a replay from a new operation, so this must be re-entered rather than sent.',
            });
        }

        return stale.length;
    }
}
