import { describe, it, expect, beforeEach } from 'vitest';
import { freshDb, repoFor, outboxFor, reopen, healthyClock } from './helpers.js';
import { uuid, ulid, ulidTime, resetUlidState } from '../../resources/js/offline/ids.js';
import { Clock, SKEW_BLOCK_MS } from '../../resources/js/offline/clock.js';
import { clock } from '../../resources/js/offline/clock.js';
import { indexedDB, IDBKeyRange } from 'fake-indexeddb';
import { ENTITY_STORES, REFERENCE_STORES, SYSTEM_STORES, openOfflineDb } from '../../resources/js/offline/db.js';
import { describeThisMachine } from '../../resources/js/offline/index.js';
import {
    backoffFor, MAX_ATTEMPTS, OP_PENDING, OP_FAILED, OP_CANCELLED, OP_SYNCED,
} from '../../resources/js/offline/outbox.js';

/**
 * Phase 1 + 2 — the local persistence layer and the outbox.
 *
 * Nothing here talks to a server. These tests hold the two properties every
 * later phase depends on: a record and the operation describing it are written
 * together or not at all, and neither disappears when the browser does.
 */

beforeEach(() => {
    healthyClock();
    resetUlidState();
});

describe('identifiers', () => {
    it('mints distinct uuids', () => {
        const ids = new Set(Array.from({ length: 5000 }, () => uuid()));

        expect(ids.size).toBe(5000);
        expect(uuid()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });

    it('mints ulids that sort by the order they were created', () => {
        const ids = Array.from({ length: 1000 }, () => ulid());
        const sorted = [...ids].sort();

        // The outbox ships in this order, so "sorted" and "created" must be
        // the same sequence or a visit goes out after its own vitals.
        expect(sorted).toEqual(ids);
        expect(new Set(ids).size).toBe(1000);
        expect(ids[0]).toHaveLength(26);
    });

    it('keeps ulids ordered even when the clock goes backwards', () => {
        const before = ulid(1_700_000_000_000);
        const jumped = ulid(1_600_000_000_000); // clock moved back an hour

        expect(jumped > before).toBe(true);
    });

    it('carries its mint time', () => {
        const at = 1_758_000_000_000;

        expect(ulidTime(ulid(at))).toBe(at);
    });
});

describe('clock', () => {
    it('reports healthy when it agrees with the server', () => {
        const c = new Clock();
        c.syncWithServer(new Date().toISOString(), 40);

        expect(c.health().state).toBe('ok');
        expect(c.mayCapture()).toBe(true);
    });

    it('warns on a few minutes of drift', () => {
        const c = new Clock();
        c.syncWithServer(new Date(Date.now() + 10 * 60_000).toISOString());

        expect(c.health().state).toBe('warn');
        expect(c.mayCapture()).toBe(true);
    });

    it('blocks capture when it is out by more than an hour', () => {
        const c = new Clock();
        c.syncWithServer(new Date(Date.now() + SKEW_BLOCK_MS + 60_000).toISOString());

        expect(c.health().state).toBe('blocked');
        expect(c.mayCapture()).toBe(false);
        expect(c.health().message).toMatch(/clock is out/);
    });

    it('will not vouch for a clock it has never checked', () => {
        expect(new Clock().health().state).toBe('warn');
    });
});

describe('schema', () => {
    it('opens with every store the code expects', async () => {
        const db = await freshDb();
        const present = db.tables.map((t) => t.name).sort();

        for (const store of [...ENTITY_STORES, ...REFERENCE_STORES, ...SYSTEM_STORES]) {
            expect(present).toContain(store);
        }
    });

    it('keys every entity store on the device-minted uuid', async () => {
        const db = await freshDb();

        for (const store of ENTITY_STORES) {
            expect(db.table(store).schema.primKey.name).toBe('uuid');
        }
    });

    it('refuses two records with the same uuid', async () => {
        const db = await freshDb();
        const id = uuid();

        await db.patients.add({ uuid: id, first_name: 'A' });

        await expect(db.patients.add({ uuid: id, first_name: 'B' })).rejects.toThrow();
        expect(await db.patients.count()).toBe(1);
    });
});

describe('repository writes', () => {
    it('writes the record and its operation in one go', async () => {
        const db = await freshDb();
        const repo = repoFor(db);

        const { row, operation } = await repo.create('patients', {
            first_name: 'Amina', last_name: 'Nakato', search_key: 'amina nakato',
        });

        expect(row.uuid).toBeTruthy();
        expect(row._sync).toBe('pending');
        expect(row.version).toBe(0);
        expect(row.server_id).toBeNull();

        const queued = await db.outbox.get(operation.operation_id);
        expect(queued.entity).toBe('patients');
        expect(queued.entity_uuid).toBe(row.uuid);
        expect(queued.operation).toBe('create');
        expect(queued.status).toBe(OP_PENDING);

        // One record, one operation, one audit row — no more, no less.
        expect(await db.patients.count()).toBe(1);
        expect(await db.outbox.count()).toBe(1);
        expect(await db.audit.count()).toBe(1);
    });

    it('leaves nothing behind when the transaction fails', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const id = uuid();

        await repo.create('patients', { uuid: id, first_name: 'First' });

        // Re-using the uuid makes the entity write fail. The operation must
        // not survive on its own — that is invariant I-5.
        await expect(repo.create('patients', { uuid: id, first_name: 'Second' })).rejects.toThrow();

        expect(await db.patients.count()).toBe(1);
        expect(await db.outbox.count()).toBe(1);
    });

    it('strips local bookkeeping from what goes on the wire', async () => {
        const db = await freshDb();
        const { operation } = await repoFor(db).create('patients', { first_name: 'Amina' });

        for (const key of Object.keys(operation.payload)) {
            expect(key.startsWith('_')).toBe(false);
        }
        expect(operation.payload.uuid).toBeTruthy();
        expect(operation.payload.first_name).toBe('Amina');
    });

    it('sends the whole record, not a diff', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const { row } = await repo.create('patients', { first_name: 'Amina', last_name: 'Nakato', phone_1: '0700' });

        const { operation } = await repo.update('patients', row.uuid, { phone_1: '0711' });

        // A diff computed against a base the server has moved past cannot be
        // applied hours later; the whole row plus base_version can.
        expect(operation.payload.first_name).toBe('Amina');
        expect(operation.payload.last_name).toBe('Nakato');
        expect(operation.payload.phone_1).toBe('0711');
    });

    it('carries the version the edit was made against', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const { row } = await repo.create('patients', { first_name: 'Amina' });

        // Pretend a sync happened and the server said "this is version 3".
        await db.patients.update(row.uuid, { _server_version: 3, version: 3, _sync: 'synced' });

        const { operation } = await repo.update('patients', row.uuid, { phone_1: '0711' });

        expect(operation.base_version).toBe(3);
    });

    it('refuses to update an append-only record', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const { row } = await repo.create('vitals', { visit_uuid: uuid(), pulse: 80 });

        await expect(repo.update('vitals', row.uuid, { pulse: 90 })).rejects.toThrow(/append-only/);
    });

    it('tombstones rather than deleting', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const { row } = await repo.create('patients', { first_name: 'Amina' });

        await repo.softDelete('patients', row.uuid);

        // The row is still there — a device that forgot it could not tell the
        // server it was deleted, and a pull would resurrect it.
        expect(await db.patients.count()).toBe(1);
        expect((await db.patients.get(row.uuid)).deleted_at).toBeTruthy();
        expect(await repo.all('patients')).toHaveLength(0);
    });

    it('marks an intent as requested, not done', async () => {
        const db = await freshDb();
        const { row } = await repoFor(db).intent('dispense_intents', {
            visit_uuid: uuid(), stock_item_id: 7, quantity: 2,
        });

        expect(row._intent).toBe(true);
        expect(row._intent_state).toBe('requested');
    });

    it('refuses clinical capture on a badly wrong clock', async () => {
        const db = await freshDb();
        clock.syncWithServer(new Date(Date.now() + SKEW_BLOCK_MS + 60_000).toISOString());

        await expect(repoFor(db).create('vitals', { visit_uuid: uuid(), pulse: 80 }))
            .rejects.toThrow(/clock is out/);

        // …but demographics, which carry no clinical time, still work.
        await expect(repoFor(db).create('patients', { first_name: 'Amina' })).resolves.toBeTruthy();
    });
});

describe('durability', () => {
    it('keeps records and pending operations across a browser restart', async () => {
        let db = await freshDb();
        const repo = repoFor(db);

        for (let i = 0; i < 25; i++) {
            await repo.create('patients', { first_name: `Patient ${i}`, search_key: `patient ${i}` });
        }

        db = await reopen(db); // ← the browser closed and opened again

        expect(await db.patients.count()).toBe(25);
        expect(await db.outbox.where('status').equals(OP_PENDING).count()).toBe(25);
    });

    it('keeps work written moments before a crash', async () => {
        let db = await freshDb();
        const repo = repoFor(db);

        await repo.create('patients', { first_name: 'Amina' });
        // No graceful close: this is what a crash looks like from storage's
        // point of view — the last committed transaction is all there is.
        db.close();
        db = await reopen(db);

        expect(await db.patients.count()).toBe(1);
        expect(await db.outbox.count()).toBe(1);
    });
});

describe('outbox', () => {
    it('hands out work oldest first', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const outbox = outboxFor(db);

        const made = [];
        for (let i = 0; i < 5; i++) {
            made.push((await repo.create('patients', { first_name: `P${i}` })).operation);
        }

        const due = await outbox.due(10);

        expect(due.map((o) => o.operation_id)).toEqual(made.map((o) => o.operation_id));
    });

    it('holds back an operation whose dependency has not gone yet', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const outbox = outboxFor(db);

        const { row: visit, operation: visitOp } = await repo.create('visits', { patient_uuid: uuid() });
        const { operation: vitalsOp } = await repo.create(
            'vitals',
            { visit_uuid: visit.uuid, pulse: 80 },
            { dependsOn: [visitOp.operation_id] },
        );

        // Both are ready, and the visit is ahead of the vitals in the batch.
        const both = await outbox.due(10);
        expect(both.map((o) => o.operation_id)).toEqual([visitOp.operation_id, vitalsOp.operation_id]);

        // With only one slot, the dependant must wait rather than go alone.
        const one = await outbox.due(1);
        expect(one).toHaveLength(1);
        expect(one[0].operation_id).toBe(visitOp.operation_id);
    });

    it('holds a dependant back while its dependency is in flight', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const outbox = outboxFor(db);

        const { row: visit, operation: visitOp } = await repo.create('visits', { patient_uuid: uuid() });
        await repo.create('vitals', { visit_uuid: visit.uuid, pulse: 80 }, { dependsOn: [visitOp.operation_id] });

        // The visit is on the wire. Its result is unknown, so the vitals must
        // not be sent behind it in a second batch.
        await outbox.markProcessing([visitOp.operation_id]);

        expect(await outbox.due(10)).toHaveLength(0);
    });

    it('holds a dependant back when its dependency was refused', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const outbox = outboxFor(db);

        const { row: visit, operation: visitOp } = await repo.create('visits', { patient_uuid: uuid() });
        await repo.create('vitals', { visit_uuid: visit.uuid, pulse: 80 }, { dependsOn: [visitOp.operation_id] });

        await db.outbox.update(visitOp.operation_id, { status: 'rejected' });

        // Before the fix this returned the vitals: a rejected dependency was
        // no longer `pending`, so it looked satisfied.
        expect(await outbox.due(10)).toHaveLength(0);
    });

    it('lets a dependant go once its dependency is synced', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const outbox = outboxFor(db);

        const { row: visit, operation: visitOp } = await repo.create('visits', { patient_uuid: uuid() });
        const { operation: vitalsOp } = await repo.create(
            'vitals', { visit_uuid: visit.uuid, pulse: 80 }, { dependsOn: [visitOp.operation_id] },
        );

        await db.outbox.update(visitOp.operation_id, { status: OP_SYNCED });

        const due = await outbox.due(10);
        expect(due.map((o) => o.operation_id)).toEqual([vitalsOp.operation_id]);
    });

    it('cascades a refusal down the whole chain rather than queueing for ever', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const outbox = outboxFor(db);

        const { row: patient, operation: patientOp } = await repo.create('patients', { first_name: 'Amina' });
        const { row: visit, operation: visitOp } = await repo.create(
            'visits', { patient_uuid: patient.uuid }, { dependsOn: [patientOp.operation_id] },
        );
        const { operation: vitalsOp } = await repo.create(
            'vitals', { visit_uuid: visit.uuid, pulse: 80 }, { dependsOn: [visitOp.operation_id] },
        );

        await db.outbox.update(patientOp.operation_id, { status: 'rejected' });
        expect(await outbox.rejectDependantsOf([patientOp.operation_id])).toBe(2);

        // Grandchildren too — otherwise the pending count never reaches zero
        // and the user is told they have unsaved work they cannot act on.
        expect((await db.outbox.get(visitOp.operation_id)).status).toBe('rejected');
        expect((await db.outbox.get(vitalsOp.operation_id)).status).toBe('rejected');
        expect(await outbox.pendingCount()).toBe(0);
    });

    it('does not offer an operation that is waiting out a backoff', async () => {
        const db = await freshDb();
        const outbox = outboxFor(db);
        const { operation } = await repoFor(db).create('patients', { first_name: 'Amina' });

        await outbox.markProcessing([operation.operation_id]);
        await outbox.releaseProcessing([operation.operation_id], 'network');

        expect(await outbox.due(10)).toHaveLength(0);

        const later = Date.now() + 120_000;
        expect(await outbox.due(10, later)).toHaveLength(1);
    });

    it('backs off further each time, with jitter', () => {
        const flat = Array.from({ length: 8 }, (_, i) => backoffFor(i, () => 0.5));

        expect(flat[0]).toBe(5_000);
        expect(flat[1]).toBe(15_000);
        expect(flat[5]).toBe(900_000);
        expect(flat[6]).toBe(3_600_000);
        expect(flat[7]).toBe(3_600_000); // capped

        const jittered = new Set(Array.from({ length: 30 }, () => backoffFor(2)));
        expect(jittered.size).toBeGreaterThan(1);
    });

    it('marks an exhausted operation failed but keeps it', async () => {
        const db = await freshDb();
        const outbox = outboxFor(db);
        const { operation } = await repoFor(db).create('patients', { first_name: 'Amina' });

        for (let i = 0; i < MAX_ATTEMPTS; i++) {
            await outbox.releaseProcessing([operation.operation_id], 'boom');
        }

        const op = await db.outbox.get(operation.operation_id);

        // Still in the outbox with its payload. A human can retry it; nothing
        // dropped it on the floor (invariant I-3).
        expect(op.status).toBe(OP_FAILED);
        expect(op.payload.first_name).toBe('Amina');
        expect(await db.outbox.count()).toBe(1);
    });

    it('winds a failed operation back for another try', async () => {
        const db = await freshDb();
        const outbox = outboxFor(db);
        const { operation } = await repoFor(db).create('patients', { first_name: 'Amina' });

        await db.outbox.update(operation.operation_id, { status: OP_FAILED, retry_count: 24 });
        expect(await outbox.retryAllFailed()).toBe(1);

        const op = await db.outbox.get(operation.operation_id);
        expect(op.status).toBe(OP_PENDING);
        expect(op.retry_count).toBe(0);
    });

    it('only lets an operation out by being cancelled on purpose, and says who', async () => {
        const db = await freshDb();
        const outbox = outboxFor(db);
        const { operation } = await repoFor(db).create('patients', { first_name: 'Amina' });

        await outbox.cancel(operation.operation_id, 'Entered twice by mistake', 'nurse@hospital');

        const op = await db.outbox.get(operation.operation_id);
        expect(op.status).toBe(OP_CANCELLED);

        const audit = await db.audit.where('operation_id').equals(operation.operation_id).toArray();
        expect(audit.some((a) => a.event === 'operation_cancelled')).toBe(true);
    });

    it('will not cancel something already synced', async () => {
        const db = await freshDb();
        const outbox = outboxFor(db);
        const { operation } = await repoFor(db).create('patients', { first_name: 'Amina' });

        await db.outbox.update(operation.operation_id, { status: OP_SYNCED });
        await outbox.cancel(operation.operation_id, 'too late');

        expect((await db.outbox.get(operation.operation_id)).status).toBe(OP_SYNCED);
    });

    it('stops sending operations too old to be recognised as replays', async () => {
        const db = await freshDb();
        const outbox = outboxFor(db);
        const { operation } = await repoFor(db).create('patients', { first_name: 'Amina' });

        const ancient = Date.now() + 100 * 24 * 3600 * 1000;
        expect(await outbox.expireStale(ancient)).toBe(1);

        const op = await db.outbox.get(operation.operation_id);
        expect(op.status).toBe(OP_FAILED);
        expect(op.last_error).toMatch(/90 days/);
        // The payload is still the only copy of that work.
        expect(op.payload.first_name).toBe('Amina');
    });

    it('counts what is pending and what needs a person', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const outbox = outboxFor(db);

        const a = await repo.create('patients', { first_name: 'A' });
        const b = await repo.create('patients', { first_name: 'B' });
        await repo.create('patients', { first_name: 'C' });

        await db.outbox.update(a.operation.operation_id, { status: OP_FAILED });
        await db.outbox.update(b.operation.operation_id, { status: 'conflict' });

        expect(await outbox.pendingCount()).toBe(1);
        expect(await outbox.needingAttention()).toHaveLength(2);
    });
});

describe('server changes coming in', () => {
    it('applies a pulled row', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const id = uuid();

        await repo.applyServerChange('patients', {
            uuid: id, server_id: 900, version: 4, first_name: 'Amina', patient_no: 'PAT-2026-00043',
        });

        const row = await db.patients.get(id);
        expect(row._sync).toBe('synced');
        expect(row._origin).toBe('server');
        expect(row._server_version).toBe(4);
    });

    it('does not clobber an edit that has not synced yet', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const { row } = await repo.create('patients', { first_name: 'Amina', phone_1: '0700' });

        await repo.applyServerChange('patients', {
            uuid: row.uuid, server_id: 900, version: 7, first_name: 'Amina', phone_1: '0799',
        });

        const after = await db.patients.get(row.uuid);

        // The user's unsynced edit survives; only the bookkeeping the push
        // will need is taken from the server.
        expect(after.phone_1).toBe('0700');
        expect(after._sync).toBe('pending');
        expect(after._server_version).toBe(7);
        expect(after.server_id).toBe(900);
    });
});

describe('opening the database the way the application actually does', () => {
    /**
     * With no options at all.
     *
     * This is the single path no test took for the entire life of the
     * feature, and it was the one that was broken: `openOfflineDb` passed
     * `{indexedDB: undefined, IDBKeyRange: undefined}`, Dexie merged that
     * over its own defaults with `Object.assign` — which copies `undefined`
     * values — and threw `MissingAPIError: IndexedDB API missing` against a
     * browser whose IndexedDB was working perfectly.
     *
     * Every other test in this suite injects fake-indexeddb explicitly, so
     * the key always had a value and the defaults were never consulted.
     * Offline mode had never opened its database in a real browser once.
     */
    it('falls back to the browser global when nothing is injected', async () => {
        const previous = { indexedDB: globalThis.indexedDB, IDBKeyRange: globalThis.IDBKeyRange };

        globalThis.indexedDB = indexedDB;
        globalThis.IDBKeyRange = IDBKeyRange;

        try {
            const db = openOfflineDb(1, 9_001);

            // The assertion that would have saved a week: Dexie must have
            // been left holding the global, not an explicit `undefined`.
            expect(db._deps.indexedDB).toBeTruthy();

            await db.open();

            expect(db.isOpen()).toBe(true);
            await db.patients.add({ uuid: 'p1', first_name: 'A', last_name: 'B', deleted_at: null });
            expect(await db.patients.count()).toBe(1);

            db.close();
        } finally {
            globalThis.indexedDB = previous.indexedDB;
            globalThis.IDBKeyRange = previous.IDBKeyRange;
        }
    });

    it('still honours an injected implementation', async () => {
        const db = openOfflineDb(1, 9_002, { indexedDB, IDBKeyRange });

        expect(db._deps.indexedDB).toBe(indexedDB);

        await db.open();
        expect(db.isOpen()).toBe(true);
        db.close();
    });
});

describe('naming a machine so nobody has to', () => {
    /**
     * The label exists so an administrator can tell apart the machines
     * holding patient records and revoke the right one. It does NOT need to
     * be typed before somebody can work — requiring it turned "let me work
     * offline" into a form.
     */
    it('describes the browser and the person, which is most of what the label was for', () => {
        const ua = globalThis.navigator?.userAgent;

        Object.defineProperty(globalThis.navigator ?? (globalThis.navigator = {}), 'userAgent', {
            value: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
            configurable: true,
        });

        try {
            expect(describeThisMachine('Chongomweru Haleem')).toBe('Chongomweru Haleem · Chrome on Mac');
            expect(describeThisMachine()).toBe('Chrome on Mac');
            expect(describeThisMachine('   ')).toBe('Chrome on Mac');
        } finally {
            if (ua !== undefined) {
                Object.defineProperty(globalThis.navigator, 'userAgent', { value: ua, configurable: true });
            }
        }
    });

    it('never exceeds what the server will accept', () => {
        // `label` is `max:120` on the server, and a rejected registration for
        // a name nobody chose would be an absurd way to fail.
        expect(describeThisMachine('x'.repeat(400)).length).toBeLessThanOrEqual(120);
    });

    it('still answers when the browser says nothing useful', () => {
        expect(describeThisMachine()).toBeTruthy();
    });
});
