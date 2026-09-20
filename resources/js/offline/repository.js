import { uuid } from './ids.js';
import { buildOperation, OP_PENDING } from './outbox.js';
import { clock } from './clock.js';

/**
 * The only way the UI writes.
 *
 * Every write does two things that must both happen or neither: it stores the
 * record, and it enqueues the operation that will one day tell the server
 * about it. **They go in one IndexedDB transaction** (invariant I-5). If they
 * were two, a crash in between leaves either a record nobody will ever sync or
 * an operation describing a record that was never written — and the first of
 * those is silent data loss that looks exactly like success.
 *
 * The UI therefore never touches `db.patients` or `db.outbox` directly. It
 * calls a repository, gets its optimistic row back, and carries on.
 */

/** Entities whose rows may be edited after creation, and therefore need a
 *  version to detect that somebody else edited them first (plan §10.1). */
const VERSIONED = new Set(['patients', 'visits', 'lab_items']);

/** Entities that are append-only: written once, never updated. Trying to
 *  update one is a programming error, not a user error, so it throws. */
const APPEND_ONLY = new Set([
    'vitals',
    'nursing_notes',
    'med_administrations',
    'clinical_notes',
    'dispense_intents',
]);

/** Clinical records that must not be captured on a device whose clock is
 *  badly wrong — a vitals round stamped three hours out is unreadable. */
const CLOCK_SENSITIVE = new Set([
    'vitals',
    'nursing_notes',
    'med_administrations',
    'clinical_notes',
    'lab_items',
]);

export class Repository {
    /**
     * @param {import('dexie').Dexie} db
     * @param {{deviceId: string, userId: number, hospitalId: number}} context
     */
    constructor(db, context) {
        this.db = db;
        this.context = context;
    }

    _assertCanCapture(store) {
        if (!CLOCK_SENSITIVE.has(store)) {
            return;
        }

        const health = clock.health();

        if (health.state === 'blocked') {
            const error = new Error(health.message);
            error.code = 'clock_unsafe';

            throw error;
        }
    }

    /**
     * Create a record and queue its creation, atomically.
     *
     * @param {string} store
     * @param {object} attributes
     * @param {{dependsOn?: string[], atomicGroupId?: string|null, entity?: string}} [options]
     */
    async create(store, attributes, options = {}) {
        this._assertCanCapture(store);

        const now = clock.clientNow();
        const row = {
            uuid: attributes.uuid ?? uuid(),
            server_id: null,
            version: 0,
            created_at: now,
            updated_at: now,
            deleted_at: null,
            _sync: 'pending',
            _device_id: this.context.deviceId,
            _origin: 'local',
            _server_version: null,
            ...attributes,
        };

        const operation = buildOperation({
            entity: options.entity ?? store,
            entityUuid: row.uuid,
            operation: 'create',
            payload: this._payload(row),
            baseVersion: null,
            deviceId: this.context.deviceId,
            userId: this.context.userId,
            dependsOn: options.dependsOn ?? [],
            atomicGroupId: options.atomicGroupId ?? null,
            clientCreatedAt: now,
        });

        await this.db.transaction('rw', this.db[store], this.db.outbox, this.db.audit, async () => {
            await this.db[store].add(row);
            await this.db.outbox.add(operation);
            await this.db.audit.add({
                operation_id: operation.operation_id,
                entity_uuid: row.uuid,
                at: now,
                event: 'created_offline',
                detail: { entity: operation.entity, user_id: this.context.userId, device_id: this.context.deviceId },
            });
        });

        return { row, operation };
    }

    /**
     * Update a record and queue the update, atomically.
     *
     * `base_version` is the version this edit was made against — not the
     * version it will become. The server compares it with what it holds and
     * that comparison is the whole of conflict detection (plan §10).
     */
    async update(store, recordUuid, changes, options = {}) {
        this._assertCanCapture(store);

        if (APPEND_ONLY.has(store)) {
            throw new Error(`${store} is append-only: correct it with a new superseding record, never an update.`);
        }

        const now = clock.clientNow();
        let result;

        await this.db.transaction('rw', this.db[store], this.db.outbox, this.db.audit, async () => {
            const existing = await this.db[store].get(recordUuid);

            if (!existing) {
                throw new Error(`No local ${store} with uuid ${recordUuid}.`);
            }

            const row = {
                ...existing,
                ...changes,
                uuid: existing.uuid,
                updated_at: now,
                _sync: 'pending',
            };

            // A three-way merge needs three sides, and the third is the one
            // everybody forgets: what the SERVER last told this device the
            // field held. With it, "the server changed this too" is a fact;
            // without it the server can only guess, and guessing conservatively
            // makes every online edit block every offline one.
            const baseFields = this._baseFor(existing, changes);

            const operation = buildOperation({
                entity: options.entity ?? store,
                entityUuid: recordUuid,
                operation: 'update',
                payload: this._payload(row),
                baseFields,
                // Never trust a version supplied by the caller: the one that
                // matters is what this device last heard from the server.
                baseVersion: VERSIONED.has(store) ? (existing._server_version ?? existing.version ?? 0) : null,
                deviceId: this.context.deviceId,
                userId: this.context.userId,
                dependsOn: options.dependsOn ?? [],
                atomicGroupId: options.atomicGroupId ?? null,
                clientCreatedAt: now,
            });

            await this.db[store].put(row);
            await this.db.outbox.add(operation);
            await this.db.audit.add({
                operation_id: operation.operation_id,
                entity_uuid: recordUuid,
                at: now,
                event: 'updated_offline',
                detail: {
                    entity: operation.entity,
                    user_id: this.context.userId,
                    device_id: this.context.deviceId,
                    fields: Object.keys(changes),
                },
            });

            result = { row, operation };
        });

        return result;
    }

    /**
     * Tombstone a record. Rows are never removed locally: a device that has
     * forgotten a record cannot tell the server it was deleted, and a pulled
     * change would simply resurrect it (plan §10.2).
     */
    async softDelete(store, recordUuid, options = {}) {
        const now = clock.clientNow();
        let result;

        await this.db.transaction('rw', this.db[store], this.db.outbox, this.db.audit, async () => {
            const existing = await this.db[store].get(recordUuid);

            if (!existing || existing.deleted_at) {
                return;
            }

            const row = { ...existing, deleted_at: now, updated_at: now, _sync: 'pending' };

            const operation = buildOperation({
                entity: options.entity ?? store,
                entityUuid: recordUuid,
                operation: 'delete',
                payload: { uuid: recordUuid, deleted_at: now },
                baseVersion: VERSIONED.has(store) ? (existing._server_version ?? existing.version ?? 0) : null,
                deviceId: this.context.deviceId,
                userId: this.context.userId,
                dependsOn: options.dependsOn ?? [],
                clientCreatedAt: now,
            });

            await this.db[store].put(row);
            await this.db.outbox.add(operation);
            await this.db.audit.add({
                operation_id: operation.operation_id,
                entity_uuid: recordUuid,
                at: now,
                event: 'deleted_offline',
                detail: { entity: operation.entity, user_id: this.context.userId },
            });

            result = { row, operation };
        });

        return result;
    }

    /**
     * Record an INTENT rather than a fact.
     *
     * Used where the device cannot know the truth: dispensing needs a stock
     * balance computed under a lock, admitting needs a bed nobody else took,
     * booking needs a slot. The device says what it wants to happen; the
     * server decides whether it can, and may reject (plan §4.5, §10.1).
     *
     * The local row is deliberately marked so the UI can render it as
     * "requested" rather than "done" — the difference matters to a
     * pharmacist looking at a shelf.
     */
    async intent(store, attributes, options = {}) {
        const { row, operation } = await this.create(
            store,
            { ...attributes, _intent: true, _intent_state: 'requested' },
            { ...options, entity: options.entity ?? store },
        );

        return { row, operation };
    }

    // ── Reads ────────────────────────────────────────────────────────────

    async get(store, recordUuid) {
        return this.db[store].get(recordUuid);
    }

    /** Live rows only — tombstones stay in the table but out of the UI. */
    async all(store) {
        return this.db[store].filter((r) => !r.deleted_at).toArray();
    }

    async where(store, index, value) {
        return this.db[store].where(index).equals(value).filter((r) => !r.deleted_at).toArray();
    }

    /**
     * Apply a change that came from the server.
     *
     * Never enqueues anything — this is the pull path, and a pulled row that
     * queued its own operation would bounce back and forth for ever.
     *
     * A row with local edits still pending is NOT overwritten: those edits are
     * on their way and the push will resolve them properly. Clobbering them
     * here would lose work that the user believes is saved.
     */
    async applyServerChange(store, change) {
        await this.db.transaction('rw', this.db[store], async () => {
            const existing = await this.db[store].get(change.uuid);

            if (existing && existing._sync === 'pending') {
                // Remember what the server holds so the eventual push carries
                // the right base_version, but leave the user's row alone.
                await this.db[store].update(change.uuid, {
                    _server_version: change.version ?? null,
                    server_id: change.server_id ?? existing.server_id,
                });

                return;
            }

            await this.db[store].put({
                ...existing,
                ...change,
                _sync: 'synced',
                _origin: 'server',
                _server_version: change.version ?? null,
                // The base for the next three-way merge.
                _server_snapshot: this._payload(change),
            });
        });
    }

    /**
     * The values the server last confirmed, for the fields being changed.
     *
     * Only those fields: sending the whole snapshot would put every value the
     * device holds back on the wire for no gain.
     */
    _baseFor(existing, changes) {
        const snapshot = existing._server_snapshot ?? {};
        const base = {};

        for (const field of Object.keys(changes)) {
            if (field in snapshot) {
                base[field] = snapshot[field];
            }
        }

        return base;
    }

    /**
     * What goes on the wire.
     *
     * Underscore-prefixed fields are local bookkeeping — sync state, device
     * id, intent flags — and the server has no business seeing them. Sending
     * them would also mean any future local-only field silently becomes part
     * of the API contract.
     */
    _payload(row) {
        const payload = {};

        for (const [key, value] of Object.entries(row)) {
            if (!key.startsWith('_')) {
                payload[key] = value;
            }
        }

        return payload;
    }
}
