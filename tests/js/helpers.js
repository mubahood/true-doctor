import Dexie from 'dexie';
import { indexedDB, IDBKeyRange } from 'fake-indexeddb';
import { openOfflineDb, databaseName } from '../../resources/js/offline/db.js';
import { Repository } from '../../resources/js/offline/repository.js';
import { Outbox } from '../../resources/js/offline/outbox.js';
import { clock } from '../../resources/js/offline/clock.js';

let counter = 0;

/** A fresh database per test — no shared state, no ordering dependencies. */
export async function freshDb() {
    const hospitalId = 1;
    const userId = ++counter;

    const db = openOfflineDb(hospitalId, userId, { indexedDB, IDBKeyRange });
    await db.open();

    return db;
}

export function repoFor(db, overrides = {}) {
    return new Repository(db, {
        deviceId: 'device-test',
        userId: db.userId,
        hospitalId: db.hospitalId,
        ...overrides,
    });
}

export function outboxFor(db) {
    return new Outbox(db);
}

/**
 * Close the database and open it again from scratch — which is what a browser
 * restart does. Anything that does not survive this was never durable.
 */
export async function reopen(db) {
    const { hospitalId, userId } = db;
    db.close();

    const again = openOfflineDb(hospitalId, userId, { indexedDB, IDBKeyRange });
    await again.open();

    return again;
}

export function nameFor(db) {
    return databaseName(db.hospitalId, db.userId);
}

/** Pretend the device has just checked its clock and found it correct. */
export function healthyClock() {
    clock.syncWithServer(new Date().toISOString(), 0);
}

export { Dexie, indexedDB, IDBKeyRange };
