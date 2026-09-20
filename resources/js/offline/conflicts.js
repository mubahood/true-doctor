import { buildOperation, OP_CANCELLED, OP_SYNCED } from './outbox.js';
import { clock } from './clock.js';

/**
 * Deciding a conflict.
 *
 * The server can refuse a write because somebody else changed the record
 * first. Detecting that is half a feature: without a way to DECIDE, the
 * operation sits in the outbox as `conflict` for ever, the "needs you" count
 * never clears, and the user is told they have unfinished work they cannot
 * finish. That is the same failure as a queue that never drains, and it is why
 * rejected operations already cascade to their dependants.
 *
 * Two answers, and only two, because a third would be a merge and a merge is
 * what the server already tried:
 *
 *   **Keep theirs** — my change is dropped. The local record takes the
 *   server's values so the device stops showing something the server
 *   disagrees with, and the operation is cancelled with a reason.
 *
 *   **Keep mine** — my change is re-sent, this time against the version the
 *   server actually holds. Because the base now matches, the server's own
 *   three-way merge applies it cleanly rather than contesting it again.
 *
 * Both are audited. Neither is silent, and neither happens without somebody
 * choosing — a conflict on a date of birth or an allergy list is exactly the
 * decision a machine should not be making (plan §10.1).
 */

export const KEPT_SERVER = 'kept_server';
export const KEPT_MINE = 'kept_mine';

export class ConflictResolver {
    /**
     * @param {import('dexie').Dexie} db
     * @param {{deviceId: string, userId: number}} context
     */
    constructor(db, context) {
        this.db = db;
        this.context = context;
    }

    /** Everything still waiting on a person. */
    async open() {
        const rows = await this.db.conflicts.filter((c) => !c.resolved_at).toArray();

        return rows.sort((a, b) => (a.created_at ?? '').localeCompare(b.created_at ?? ''));
    }

    async count() {
        return this.db.conflicts.filter((c) => !c.resolved_at).count();
    }

    /**
     * What the person is actually choosing between, field by field.
     *
     * Only the CONTESTED fields: the rest were merged or were never in
     * dispute, and showing forty unchanged values around the one that matters
     * is how somebody clicks the wrong button.
     */
    async describe(conflictId) {
        const conflict = await this.db.conflicts.get(conflictId);

        if (!conflict) {
            return null;
        }

        const fields = (conflict.contested ?? []).map((field) => ({
            field,
            mine: conflict.mine?.[field] ?? null,
            theirs: conflict.server_state?.[field] ?? null,
        }));

        return {
            id: conflict.id,
            entity: conflict.entity,
            entity_uuid: conflict.entity_uuid,
            strategy: conflict.strategy,
            created_at: conflict.created_at,
            merged: conflict.merged ?? [],
            fields,
        };
    }

    /**
     * Take the server's values and let my change go.
     *
     * The local record is updated to match, because a device that keeps
     * showing a value the server has rejected will produce the same conflict
     * again the next time anybody edits that record.
     */
    async keepServer(conflictId, reason = null) {
        const conflict = await this.db.conflicts.get(conflictId);

        if (!conflict || conflict.resolved_at) {
            return { resolved: false };
        }

        const now = clock.clientNow();
        const store = conflict.entity;

        await this.db.transaction(
            'rw',
            this.db[store],
            this.db.outbox,
            this.db.conflicts,
            this.db.audit,
            async () => {
                const row = await this.db[store].get(conflict.entity_uuid);

                if (row) {
                    await this.db[store].put({
                        ...row,
                        ...(conflict.server_state ?? {}),
                        _sync: 'synced',
                        _server_version: conflict.server_version ?? row._server_version,
                        // The base for the next merge is now what the server
                        // holds, which is what we just accepted.
                        _server_snapshot: { ...(row._server_snapshot ?? {}), ...(conflict.server_state ?? {}) },
                    });
                }

                await this.db.outbox.update(conflict.id, {
                    status: OP_CANCELLED,
                    last_error: 'Replaced by the version already on the server.',
                });

                await this.db.conflicts.update(conflictId, {
                    resolved_at: now,
                    resolution: KEPT_SERVER,
                    resolution_reason: reason,
                });

                await this.db.audit.add({
                    operation_id: conflict.id,
                    entity_uuid: conflict.entity_uuid,
                    at: now,
                    event: 'conflict_resolved',
                    detail: {
                        entity: conflict.entity,
                        resolution: KEPT_SERVER,
                        // Field names only, never their values.
                        fields: conflict.contested ?? [],
                        user_id: this.context.userId,
                        device_id: this.context.deviceId,
                    },
                });
            },
        );

        return { resolved: true, resolution: KEPT_SERVER };
    }

    /**
     * Insist on my values, against the version the server actually holds.
     *
     * A NEW operation, not a retry of the old one: the old one was made
     * against a version that no longer exists, and re-sending it would be
     * contested again for exactly the same reason. This one carries the
     * server's current version as its base and the server's current values as
     * `base_fields`, so the merge sees "nobody has moved these since" and
     * applies them.
     */
    async keepMine(conflictId, reason = null) {
        const conflict = await this.db.conflicts.get(conflictId);

        if (!conflict || conflict.resolved_at) {
            return { resolved: false };
        }

        const now = clock.clientNow();
        const store = conflict.entity;

        // Only the contested fields. Re-sending the whole record would drag
        // along every value the server merged a moment ago and put them all
        // back in dispute.
        const mine = {};
        for (const field of conflict.contested ?? []) {
            if (conflict.mine && field in conflict.mine) {
                mine[field] = conflict.mine[field];
            }
        }

        if (Object.keys(mine).length === 0) {
            // Nothing recoverable — an older conflict record, or a strategy
            // that contested nothing. Falling through to "keep theirs" rather
            // than queueing an empty operation.
            return this.keepServer(conflictId, 'Nothing of mine was left to send.');
        }

        let operation;

        await this.db.transaction(
            'rw',
            this.db[store],
            this.db.outbox,
            this.db.conflicts,
            this.db.audit,
            async () => {
                const row = await this.db[store].get(conflict.entity_uuid);

                operation = buildOperation({
                    entity: conflict.entity,
                    entityUuid: conflict.entity_uuid,
                    operation: 'update',
                    payload: { uuid: conflict.entity_uuid, ...mine },
                    // The version the server told us it holds, so the merge
                    // sees a base it agrees with.
                    baseVersion: conflict.server_version ?? null,
                    baseFields: conflict.server_state ?? {},
                    deviceId: this.context.deviceId,
                    userId: this.context.userId,
                    clientCreatedAt: now,
                });

                if (row) {
                    await this.db[store].put({
                        ...row,
                        ...mine,
                        updated_at: now,
                        _sync: 'pending',
                        _server_version: conflict.server_version ?? row._server_version,
                        _server_snapshot: { ...(row._server_snapshot ?? {}), ...(conflict.server_state ?? {}) },
                    });
                }

                await this.db.outbox.add(operation);

                await this.db.outbox.update(conflict.id, {
                    status: OP_CANCELLED,
                    last_error: 'Replaced by a corrected version sent afterwards.',
                });

                await this.db.conflicts.update(conflictId, {
                    resolved_at: now,
                    resolution: KEPT_MINE,
                    resolution_reason: reason,
                    replaced_by: operation.operation_id,
                });

                await this.db.audit.add({
                    operation_id: operation.operation_id,
                    entity_uuid: conflict.entity_uuid,
                    at: now,
                    event: 'conflict_resolved',
                    detail: {
                        entity: conflict.entity,
                        resolution: KEPT_MINE,
                        fields: Object.keys(mine),
                        replaces: conflict.id,
                        user_id: this.context.userId,
                        device_id: this.context.deviceId,
                    },
                });
            },
        );

        return { resolved: true, resolution: KEPT_MINE, operation };
    }

    /**
     * A conflict the server has since settled some other way.
     *
     * Called when a later pull brings a record whose version has moved past
     * the one the conflict was raised against: the argument is over, somebody
     * else ended it, and leaving it on a list of decisions to make would have
     * a person adjudicating a question nobody is asking any more.
     */
    async closeSettled(entity, entityUuid, serverVersion) {
        const stale = (await this.open()).filter(
            (c) => c.entity === entity
                && c.entity_uuid === entityUuid
                && (c.server_version ?? 0) < serverVersion,
        );

        for (const conflict of stale) {
            await this.db.transaction('rw', this.db.conflicts, this.db.outbox, this.db.audit, async () => {
                await this.db.conflicts.update(conflict.id, {
                    resolved_at: clock.clientNow(),
                    resolution: 'settled_elsewhere',
                });

                const op = await this.db.outbox.get(conflict.id);

                if (op && op.status !== OP_SYNCED) {
                    await this.db.outbox.update(conflict.id, {
                        status: OP_CANCELLED,
                        last_error: 'This record was changed again before the conflict was decided.',
                    });
                }

                await this.db.audit.add({
                    operation_id: conflict.id,
                    entity_uuid: entityUuid,
                    at: clock.clientNow(),
                    event: 'conflict_closed',
                    detail: { entity, resolution: 'settled_elsewhere' },
                });
            });
        }

        return stale.length;
    }
}
