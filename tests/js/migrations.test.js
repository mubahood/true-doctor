import { describe, it, expect } from 'vitest';
import Dexie from 'dexie';
import { indexedDB, IDBKeyRange } from 'fake-indexeddb';
import { defineSchema, SCHEMA_VERSION } from '../../resources/js/offline/db.js';
import { uuid, ulid } from '../../resources/js/offline/ids.js';

/**
 * Local schema migrations.
 *
 * A device that has been in a drawer for two releases opens a v1 database
 * against v3 code and has to walk every step in between. The property that
 * matters is not that the stores end up right — it is that **the outbox
 * survives every step** (invariant I-14). A migration that drops pending
 * operations destroys work the user believes is saved, silently, on upgrade.
 *
 * The v2 and v3 schemas here are written for the test rather than shipped, so
 * the harness is proven now and the first real migration has a pattern to
 * follow instead of being invented under pressure.
 */

let n = 0;
const nextName = () => `td-migrate-${++n}`;

function open(name, upTo) {
    const db = new Dexie(name, { indexedDB, IDBKeyRange });

    // v1 — what ships today.
    defineSchema(db);

    if (upTo >= 2) {
        // A later release adds a store and an index. Additive only: the v1
        // block above is untouched, because a device sitting on v1 replays it.
        db.version(2).stores({
            referrals: '&uuid, visit_uuid, created_at, _sync',
            patients: '&uuid, server_id, patient_no, search_key, updated_at, _sync, deleted_at, nhis_no',
        });
    }

    if (upTo >= 3) {
        // A release that backfills. The upgrade function must be able to read
        // and write rows, and must not touch the outbox.
        db.version(3)
            .stores({ patients: '&uuid, server_id, patient_no, search_key, updated_at, _sync, deleted_at, nhis_no, sex' })
            .upgrade(async (tx) => {
                await tx.table('patients').toCollection().modify((p) => {
                    p.sex = p.sex ?? 'unknown';
                });
            });
    }

    return db;
}

async function seedWork(db, count = 20) {
    const ops = [];
    const patients = [];

    for (let i = 0; i < count; i++) {
        const id = uuid();
        patients.push({
            uuid: id, server_id: null, version: 0, first_name: `Patient ${i}`,
            search_key: `patient ${i}`, created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(), deleted_at: null, _sync: 'pending',
        });
        ops.push({
            operation_id: ulid(), entity: 'patients', entity_uuid: id, operation: 'create',
            payload: { uuid: id, first_name: `Patient ${i}` }, base_version: null,
            created_at: new Date().toISOString(), device_id: 'd1', user_id: 1,
            status: 'pending', retry_count: 0, next_retry_at: null, last_error: null,
            depends_on: [], atomic_group_id: null, result: null,
        });
    }

    await db.patients.bulkAdd(patients);
    await db.outbox.bulkAdd(ops);
}

describe('local schema migrations', () => {
    it('ships at the version the code says it does', async () => {
        const name = nextName();
        const db = open(name, 1);
        await db.open();

        expect(db.verno).toBe(SCHEMA_VERSION);
        db.close();
    });

    it('carries fifty pending operations from v1 to v3 untouched', async () => {
        const name = nextName();

        // A device on v1 with a shift's work in it.
        let db = open(name, 1);
        await db.open();
        await seedWork(db, 50);
        const before = (await db.outbox.toArray()).map((o) => o.operation_id).sort();
        db.close();

        // The app updates twice while it was closed.
        db = open(name, 3);
        await db.open();

        expect(db.verno).toBe(3);

        const after = (await db.outbox.toArray()).map((o) => o.operation_id).sort();

        // Every operation, by id, still pending, with its payload intact.
        expect(after).toEqual(before);
        expect(await db.outbox.where('status').equals('pending').count()).toBe(50);
        expect((await db.outbox.toArray())[0].payload.first_name).toMatch(/^Patient /);

        db.close();
    });

    it('runs the intermediate step for a device that skipped a release', async () => {
        const name = nextName();

        let db = open(name, 1);
        await db.open();
        await seedWork(db, 5);
        db.close();

        // v1 → v3 directly. The v2 store must still be created, because the
        // upgrade path is replayed step by step, not jumped.
        db = open(name, 3);
        await db.open();

        expect(db.tables.map((t) => t.name)).toContain('referrals');
        // …and v3's backfill ran over rows written under v1.
        expect((await db.patients.toArray()).every((p) => p.sex === 'unknown')).toBe(true);

        db.close();
    });

    it('leaves existing records readable after new indexes appear', async () => {
        const name = nextName();

        let db = open(name, 1);
        await db.open();
        await seedWork(db, 10);
        db.close();

        db = open(name, 2);
        await db.open();

        expect(await db.patients.count()).toBe(10);
        expect(await db.patients.where('search_key').equals('patient 3').count()).toBe(1);

        db.close();
    });

    it('does not lose work when an upgrade throws', async () => {
        const name = nextName();

        let db = open(name, 1);
        await db.open();
        await seedWork(db, 12);
        db.close();

        // A migration with a bug in it.
        const broken = new Dexie(name, { indexedDB, IDBKeyRange });
        defineSchema(broken);
        broken.version(2)
            .stores({ referrals: '&uuid' })
            .upgrade(async () => {
                throw new Error('bad migration');
            });

        await expect(broken.open()).rejects.toThrow();
        broken.close();

        // The device is still on v1 and every operation is still there. An
        // upgrade that fails must leave the user where they were, not halfway.
        const recovered = open(name, 1);
        await recovered.open();

        expect(await recovered.outbox.count()).toBe(12);
        expect(await recovered.patients.count()).toBe(12);

        recovered.close();
    });
});
