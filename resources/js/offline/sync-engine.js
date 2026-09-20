import { Outbox, OP_SYNCED, OP_CONFLICT, OP_REJECTED, OP_PENDING } from './outbox.js';
import { Repository } from './repository.js';
import { HttpError, TransportError } from './transport.js';
import { withSyncLock, TabChannel } from './lock.js';
import { ConflictResolver } from './conflicts.js';
import { clock } from './clock.js';

/**
 * Push, then pull, then tell the tabs.
 *
 * Three rules, each of which exists because breaking it loses or duplicates a
 * clinician's work:
 *
 * **A transport failure is not a rejection.** If the request never got an
 * answer we do not know whether the server applied it, so the operations go
 * back to `pending` and are sent again. That is only safe because the server
 * deduplicates on `operation_id` — the client half of invariant I-2.
 *
 * **The cursor advances only after the page is committed** (invariant I-4).
 * Advancing first loses the whole page on a crash, permanently, because the
 * server will never send it again.
 *
 * **Push before pull.** Local work goes up before server changes come down, so
 * a record the device created is on the server before anything can arrive
 * claiming to describe it.
 */

export const IDLE = 'idle';
export const SYNCING = 'syncing';

export class SyncEngine {
    /**
     * @param {{db: import('dexie').Dexie, transport: import('./transport.js').Transport,
     *          context: {deviceId: string, userId: number, hospitalId: number},
     *          protocolVersion?: number, batchSize?: number,
     *          onEvent?: (event: {type: string, detail?: object}) => void}} options
     */
    constructor({ db, transport, context, protocolVersion = 1, batchSize = 50, onEvent = null, channel = null }) {
        this.db = db;
        this.transport = transport;
        this.context = context;
        this.protocolVersion = protocolVersion;
        this.batchSize = batchSize;
        this.outbox = new Outbox(db);
        this.repo = new Repository(db, context);
        this.conflicts = new ConflictResolver(db, context);
        this.onEvent = onEvent ?? (() => {});
        this.channel = channel ?? null;
        this.state = IDLE;
    }

    emit(type, detail = {}) {
        // No payload contents and no patient identifiers beyond a uuid — a
        // diagnostic log is not a place for a clinical record (plan §20).
        this.onEvent({ type, detail });
    }

    /**
     * One full round. Held under a cross-tab lock: two tabs pushing the same
     * batch produces local bookkeeping that describes a state that never
     * happened, even though the server itself stays correct.
     */
    async sync({ pull = true } = {}) {
        const outcome = await withSyncLock(async () => {
            this.state = SYNCING;
            this.emit('SYNC_STARTED');

            const summary = { pushed: 0, accepted: 0, rejected: 0, conflicts: 0, duplicates: 0, pulled: 0, errors: [] };

            try {
                await this.outbox.expireStale();
                await this.pushAll(summary);

                if (pull) {
                    await this.pullAll(summary);
                }

                this.emit('SYNC_COMPLETED', summary);
            } catch (error) {
                summary.errors.push(String(error?.message ?? error));
                this.emit('SYNC_FAILED', { message: String(error?.message ?? error) });
            } finally {
                this.state = IDLE;
            }

            this.channel?.post({ type: 'synced', summary });

            return summary;
        });

        if (!outcome.ran) {
            // Another tab is already doing it. Not an error and not worth
            // queueing — whatever it pushes is what we would have pushed.
            this.emit('SYNC_SKIPPED', { reason: 'another tab is syncing' });

            return { skipped: true };
        }

        return outcome.result;
    }

    // ── Push ─────────────────────────────────────────────────────────────

    async pushAll(summary) {
        this.emit('SYNC_PUSH_STARTED');

        // Bounded: a device with ten thousand pending operations must drain
        // without one request that never finishes, and must yield between
        // batches so the UI stays answerable.
        for (let round = 0; round < 500; round++) {
            const due = await this.outbox.due(this.batchSize);

            if (due.length === 0) {
                break;
            }

            const sent = await this.pushBatch(due, summary);

            if (!sent) {
                // Transport failure — the batch is back on the queue with a
                // backoff. Stopping rather than hammering a server that is not
                // answering.
                break;
            }
        }

        this.emit('SYNC_PUSH_COMPLETED', { pushed: summary.pushed });
    }

    /** @returns {Promise<boolean>} false when the server never answered */
    async pushBatch(operations, summary) {
        const ids = operations.map((o) => o.operation_id);

        await this.outbox.markProcessing(ids);

        let response;

        try {
            response = await this.transport.push(operations.map(this.wire), this.protocolVersion);
        } catch (error) {
            // We do NOT know whether the server applied these. Back to pending;
            // the retry is safe because the server deduplicates (I-2).
            await this.outbox.releaseProcessing(ids, error?.message);

            if (error instanceof HttpError && error.code === 'device_revoked') {
                this.emit('DEVICE_REVOKED', { message: error.message });
                throw error;
            }

            if (error instanceof HttpError && error.code === 'protocol_mismatch') {
                this.emit('PROTOCOL_MISMATCH', { message: error.message });
                throw error;
            }

            // 401: the session is gone. 419: the session is gone and Laravel
            // noticed at the CSRF check first, because a dead session has no
            // token to match. They are the same event to a nurse — "you need
            // to sign in again" — and neither is retryable, so hammering the
            // server with a batch it will refuse identically is pointless.
            //
            // The operations are already back on `pending` above. Nothing is
            // discarded: signing in again sends the whole shift.
            if (error instanceof HttpError && (error.status === 401 || error.status === 419)) {
                this.emit('AUTH_EXPIRED', { status: error.status });
                throw error;
            }

            this.emit('SYNC_RETRY', { count: ids.length, reason: error instanceof TransportError ? 'unreachable' : 'server' });

            return false;
        }

        await this.applyResults(response?.results ?? [], operations, summary);

        summary.pushed += operations.length;

        return true;
    }

    /** What actually goes over the wire — local bookkeeping stays local. */
    wire(op) {
        return {
            operation_id: op.operation_id,
            entity: op.entity,
            entity_uuid: op.entity_uuid,
            operation: op.operation,
            payload: op.payload,
            base_version: op.base_version,
            base_fields: op.base_fields ?? null,
            client_created_at: op.created_at,
            atomic_group_id: op.atomic_group_id,
        };
    }

    /**
     * Record what the server made of each operation.
     *
     * Everything in one transaction per operation, so a crash halfway through
     * a batch leaves each operation either fully recorded or untouched — never
     * marked synced with its record still saying `pending`.
     */
    async applyResults(results, operations, summary) {
        const byId = new Map(operations.map((o) => [o.operation_id, o]));
        const refused = [];

        for (const result of results) {
            const op = byId.get(result.operation_id);

            if (!op) {
                continue;
            }

            const store = op.entity;

            switch (result.status) {
                case 'accepted':
                case 'already_processed':
                    await this.markAccepted(op, result, store);
                    result.status === 'accepted' ? summary.accepted++ : summary.duplicates++;
                    break;

                case 'conflict':
                    await this.markConflict(op, result);
                    summary.conflicts++;
                    this.emit('SYNC_CONFLICT', {
                        entity: op.entity,
                        entity_uuid: op.entity_uuid,
                        contested: result.conflict?.contested ?? [],
                    });
                    break;

                case 'rejected':
                    await this.markRejected(op, result);
                    refused.push(op.operation_id);
                    summary.rejected++;
                    break;

                default:
                    // `failed` / `in_flight` — the server is not finished with
                    // it. Back to pending with a backoff.
                    await this.outbox.releaseProcessing([op.operation_id], result.message ?? result.reason_code);
                    break;
            }
        }

        if (refused.length) {
            // A child whose parent was refused can never succeed. Refusing it
            // now beats a queue that never drains and a pending count that
            // never reaches zero.
            await this.outbox.rejectDependantsOf(refused);
        }
    }

    async markAccepted(op, result, store) {
        await this.db.transaction('rw', this.db[store], this.db.outbox, this.db.audit, async () => {
            const row = await this.db[store].get(op.entity_uuid);

            if (row) {
                await this.db[store].put({
                    ...row,
                    // Server-allocated values the device could not know — the
                    // real patient number replacing a provisional label.
                    ...(result.assigned ?? {}),
                    server_id: result.server_id ?? row.server_id,
                    version: result.version ?? row.version,
                    _server_version: result.version ?? row._server_version,
                    _sync: 'synced',
                    // The base for the next three-way merge, now confirmed.
                    _server_snapshot: stripLocal({ ...row, ...(result.assigned ?? {}) }),
                    ...(row._intent ? { _intent_state: 'accepted' } : {}),
                });
            }

            await this.db.outbox.update(op.operation_id, { status: OP_SYNCED, result, last_error: null });
            await this.db.audit.add({
                operation_id: op.operation_id,
                entity_uuid: op.entity_uuid,
                at: new Date().toISOString(),
                event: result.status === 'accepted' ? 'accepted_by_server' : 'already_on_server',
                detail: { entity: op.entity, server_id: result.server_id ?? null },
            });
        });
    }

    async markConflict(op, result) {
        await this.db.transaction('rw', this.db.outbox, this.db.conflicts, this.db.audit, async () => {
            await this.db.outbox.update(op.operation_id, {
                status: OP_CONFLICT,
                result,
                last_error: 'Somebody else changed this first.',
            });

            await this.db.conflicts.put({
                id: op.operation_id,
                entity: op.entity,
                entity_uuid: op.entity_uuid,
                strategy: result.conflict?.strategy ?? 'manual',
                contested: result.conflict?.contested ?? [],
                merged: result.conflict?.merged ?? [],
                server_state: result.conflict?.server_state ?? {},
                // What WE wanted, so a person can see both sides and choose.
                mine: op.payload,
                created_at: new Date().toISOString(),
                resolved_at: null,
            });

            await this.db.audit.add({
                operation_id: op.operation_id,
                entity_uuid: op.entity_uuid,
                at: new Date().toISOString(),
                event: 'conflict',
                detail: { entity: op.entity, contested: result.conflict?.contested ?? [] },
            });
        });
    }

    async markRejected(op, result) {
        await this.db.transaction('rw', this.db.outbox, this.db.audit, async () => {
            await this.db.outbox.update(op.operation_id, {
                status: OP_REJECTED,
                result,
                next_retry_at: null,
                // The payload stays. A rejected operation is still the only
                // copy of that work and a person may want to correct and
                // resend it (invariant I-10).
                last_error: result.message ?? result.reason_code ?? 'The server refused this.',
            });

            await this.db.audit.add({
                operation_id: op.operation_id,
                entity_uuid: op.entity_uuid,
                at: new Date().toISOString(),
                event: 'rejected_by_server',
                detail: { entity: op.entity, reason: result.reason_code ?? null },
            });
        });
    }

    // ── Pull ─────────────────────────────────────────────────────────────

    async pullAll(summary) {
        this.emit('SYNC_PULL_STARTED');

        let cursor = await this.cursor();

        for (let page = 0; page < 200; page++) {
            let response;

            try {
                response = await this.transport.pull(cursor);
            } catch (error) {
                // A pull-only round is the common case — `syncIfUseful()` takes
                // it whenever the outbox is empty. Without this an expired
                // session on a device with nothing to send would look exactly
                // like a flaky network, for ever, and nobody would be told to
                // sign in again.
                if (error instanceof HttpError && (error.status === 401 || error.status === 419)) {
                    this.emit('AUTH_EXPIRED', { status: error.status, phase: 'pull' });

                    // `return`, not `break`: breaking falls through to
                    // SYNC_PULL_COMPLETED, which would report a pull that did
                    // not happen and — because a completed round is what
                    // proves the session is alive — clear the warning in the
                    // same breath as raising it.
                    return;
                }

                this.emit('SYNC_RETRY', { phase: 'pull', reason: String(error?.message ?? error) });
                break;
            }

            const changes = response?.changes ?? [];

            if (changes.length) {
                // PERSIST FIRST. The cursor moves only once this has committed
                // (invariant I-4) — the other order loses the page for ever.
                await this.applyChanges(changes);
                summary.pulled += changes.length;
            }

            cursor = response?.next_cursor ?? cursor;
            await this.saveCursor(cursor);

            // Best-effort: the device's own cursor is authority, so a failed
            // ack costs an administrator a stale "last seen", nothing more.
            try {
                await this.transport.ack(cursor, changes.length);
            } catch {
                // Ignored on purpose.
            }

            if (!response?.has_more) {
                break;
            }
        }

        this.emit('SYNC_PULL_COMPLETED', { pulled: summary.pulled });
    }

    /**
     * Commit a page.
     *
     * One transaction for the whole page across every store it touches, so a
     * crash mid-page leaves the device exactly where it was — and the cursor,
     * which has not moved, asks for the same page again.
     */
    async applyChanges(changes) {
        const stores = [...new Set(changes.map((c) => c.entity))].filter((s) => this.db[s]);

        if (stores.length === 0) {
            return;
        }

        await this.db.transaction('rw', ...stores.map((s) => this.db[s]), async () => {
            for (const change of changes) {
                if (!this.db[change.entity]) {
                    // A newer server sending an entity this client does not
                    // know. Skipped rather than fatal — the cursor still moves,
                    // and an old client is not broken by a deploy.
                    continue;
                }

                await this.repo.applyServerChange(change.entity, change.record);
            }
        });

        // A record whose version has moved past the one a conflict was raised
        // against has been settled by somebody else. Leaving it on a list of
        // decisions to make would have a person adjudicating a question nobody
        // is asking any more.
        for (const change of changes) {
            const version = change.record?.version;

            if (typeof version === 'number' && change.record?.uuid) {
                await this.conflicts.closeSettled(change.entity, change.record.uuid, version);
            }
        }
    }

    // ── Cursor ───────────────────────────────────────────────────────────

    async cursor() {
        return (await this.db.sync_state.get('pull_cursor'))?.value ?? null;
    }

    async saveCursor(cursor) {
        await this.db.sync_state.put({ key: 'pull_cursor', value: cursor, at: new Date().toISOString() });
    }

    // ── What the UI asks ─────────────────────────────────────────────────

    async summary() {
        const counts = await this.outbox.countByStatus();
        const lastSync = (await this.db.sync_state.get('last_sync'))?.value ?? null;

        return {
            state: this.state,
            pending: (counts.pending ?? 0) + (counts.processing ?? 0),
            failed: counts.failed ?? 0,
            rejected: counts.rejected ?? 0,
            conflicts: await this.db.conflicts.filter((c) => !c.resolved_at).count(),
            lastSync,
        };
    }

    async recordSyncTime() {
        await this.db.sync_state.put({ key: 'last_sync', value: new Date().toISOString() });
    }
}

/** Local bookkeeping never travels. */
function stripLocal(row) {
    const out = {};

    for (const [key, value] of Object.entries(row)) {
        if (!key.startsWith('_')) {
            out[key] = value;
        }
    }

    return out;
}

export { TabChannel };
