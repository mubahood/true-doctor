import Dexie from 'dexie';

/**
 * The device's local database.
 *
 * One database per (hospital, user) — `td-offline-<hospital>-<user>` — rather
 * than one database with a discriminator column. Two reasons, both about
 * shared workstations: logging out is a `delete()` instead of a careful sweep
 * that has to get every store right, and one clinician's cached patients can
 * never be read by the next person to sit down (plan §7, §17).
 *
 * The stores here are NOT a mirror of the server's 58 tables. They are the
 * working set for the ten workflows the plan classified as offline-capable
 * (§4.9), plus the machinery that makes syncing them safe. Every store added
 * here is a store that has to be migrated, conflict-resolved and wiped, so the
 * bar for adding one is the same as the bar for taking a workflow offline.
 *
 * ── Schema versions ──────────────────────────────────────────────────────
 * Dexie versions are append-only. A new version NEVER redefines an old one:
 * an installed device upgrades by replaying every step from where it is, and
 * rewriting a past step means a device two versions behind migrates through a
 * schema that never existed. Add a `.version(n+1)` block; leave the rest alone.
 */

/** Bumped when the stores below change. Mirrored into `sync_state`. */
export const SCHEMA_VERSION = 1;

/** The wire format this client speaks. The server refuses mismatches. */
export const SYNC_PROTOCOL_VERSION = 1;

/**
 * Stores whose rows are hospital records, as opposed to machinery.
 *
 * Used by the wipe, the quota report and the integrity check, so a store added
 * to the schema but forgotten here would be silently left behind on logout —
 * which is why the list is asserted against the live schema in the tests.
 */
export const ENTITY_STORES = [
    'patients',
    'visits',
    'clinical_notes',
    'vitals',
    'nursing_notes',
    'med_administrations',
    'orders',
    'lab_items',
    'appointments',
    'admissions',
    'dispense_intents',
    'attachments',
];

/** Catalogue data the device reads but never edits. */
export const REFERENCE_STORES = [
    'ref_services',
    'ref_lab_tests',
    'ref_medications',
    'ref_staff',
    'ref_wards',
    'ref_beds',
    'ref_departments',
];

/** Sync machinery. Wiped with everything else, but never pulled or pushed. */
export const SYSTEM_STORES = ['outbox', 'sync_state', 'conflicts', 'audit', 'session', 'blobs'];

/**
 * `&uuid` is a unique primary key on every entity store: the device's own id
 * for the record, which the server adopts rather than replaces (invariant
 * I-8). `server_id` is indexed so a pulled change can find the local row, but
 * it is never a key — a record created offline has no server id for as long as
 * it takes to sync, and a key that is null half the time is not a key.
 */
export function defineSchema(db) {
    db.version(1).stores({
        // ── Working data ─────────────────────────────────────────────────
        patients: '&uuid, server_id, patient_no, search_key, updated_at, _sync, deleted_at',
        visits: '&uuid, server_id, patient_uuid, visit_no, status, created_at, _sync',
        clinical_notes: '&uuid, server_id, visit_uuid, created_at, _sync, supersedes_uuid',
        vitals: '&uuid, server_id, visit_uuid, admission_uuid, created_at, _sync',
        nursing_notes: '&uuid, server_id, admission_uuid, created_at, _sync',
        med_administrations: '&uuid, server_id, admission_uuid, created_at, _sync',
        orders: '&uuid, server_id, visit_uuid, type, status, created_at, _sync',
        lab_items: '&uuid, server_id, lab_order_uuid, status, _sync',
        appointments: '&uuid, server_id, scheduled_on, doctor_id, patient_uuid, status, _sync',
        admissions: '&uuid, server_id, bed_id, patient_uuid, status, _sync',
        dispense_intents: '&uuid, server_id, visit_uuid, stock_item_id, _sync',
        attachments: '&uuid, server_id, owner_type, owner_uuid, _sync',

        // Blob bytes live apart from their metadata so a list of attachments
        // can be read without dragging megabytes through memory.
        blobs: '&uuid, size, created_at',

        // ── Reference data ───────────────────────────────────────────────
        ref_services: '&id, name, is_active',
        ref_lab_tests: '&id, name, is_active',
        ref_medications: '&id, name, is_active',
        ref_staff: '&id, name, role',
        ref_wards: '&id, name',
        ref_beds: '&id, ward_id, status',
        ref_departments: '&id, name',

        // ── Machinery ────────────────────────────────────────────────────
        // `[status+next_retry_at]` is the queue's hot path: "what is due to be
        // sent". A compound index keeps it one range scan instead of a filter
        // over every operation ever enqueued.
        outbox: '&operation_id, status, entity, entity_uuid, created_at, [status+next_retry_at], atomic_group_id',
        sync_state: '&key',
        conflicts: '&id, entity, entity_uuid, resolved_at, created_at',
        audit: '++id, operation_id, entity_uuid, at',
        session: '&key',
    });

    return db;
}

/**
 * Open the local database for one user in one hospital.
 *
 * `indexedDB` is injectable so the tests can hand in fake-indexeddb without a
 * browser, and so a diagnostic tool could open a copy read-only.
 */
export function openOfflineDb(hospitalId, userId, options = {}) {
    const name = databaseName(hospitalId, userId);

    // Only the overrides that were actually GIVEN.
    //
    // Dexie merges its defaults with this object using `Object.assign`, and
    // `Object.assign` copies a key whose value is `undefined` just as
    // happily as one with a value. So passing `indexedDB: undefined` — which
    // is what `options.indexedDB` is whenever a caller supplies no
    // overrides, i.e. every call in the real application — overwrote
    // `globalThis.indexedDB` with nothing, and Dexie threw
    // `MissingAPIError: IndexedDB API missing`.
    //
    // **Offline mode had never once opened its database in a browser.** No
    // test could see it: every test hands in fake-indexeddb explicitly, so
    // the key always had a value and the defaults were never needed. The
    // production path — no options at all — was the one path never taken.
    const overrides = {};

    if (options.indexedDB) {
        overrides.indexedDB = options.indexedDB;
    }

    if (options.IDBKeyRange) {
        overrides.IDBKeyRange = options.IDBKeyRange;
    }

    const db = new Dexie(name, overrides);

    defineSchema(db);

    db.hospitalId = hospitalId;
    db.userId = userId;

    return db;
}

export function databaseName(hospitalId, userId) {
    return `td-offline-${hospitalId}-${userId}`;
}

/**
 * Every database this browser holds for us.
 *
 * Used on logout and by the diagnostics screen. `Dexie.getDatabaseNames` is
 * not available everywhere, so a missing implementation returns nothing rather
 * than throwing — the caller's job is to delete what it can find, and finding
 * none is a valid answer.
 */
export async function listOfflineDatabases(indexedDb = globalThis.indexedDB) {
    if (!indexedDb || typeof indexedDb.databases !== 'function') {
        return [];
    }

    try {
        const all = await indexedDb.databases();

        return all.map((d) => d.name).filter((n) => typeof n === 'string' && n.startsWith('td-offline-'));
    } catch {
        return [];
    }
}

/**
 * Whether this browser will let us keep anything at all.
 *
 * `indexedDB` is not always there. A browser with storage blocked for the
 * site — Brave's shields set to block all cookies, Firefox in permanent
 * private mode, an enterprise policy — simply does not expose it, and Dexie
 * throws `MissingAPIError` the moment it is asked to open a database.
 *
 * That error used to surface as an unhandled Alpine expression error, leaving
 * Field Mode on "Opening your offline copy…" for ever with a blank page below
 * it. A blank page is the worst answer available: it looks like a broken app
 * rather than a setting somebody can change in ten seconds.
 *
 * Checking for the property is not quite enough — some browsers expose it and
 * then throw on use — so the caller still has to handle a failure from
 * `open()`. This catches the common case early enough to explain it.
 */
export function storageAvailable(indexedDb = globalThis.indexedDB) {
    return Boolean(indexedDb) && typeof indexedDb.open === 'function';
}

/** Thrown when this browser will not let the app store anything. */
export class StorageBlockedError extends Error {
    /**
     * Two different failures, and they are worth telling apart.
     *
     * `missing` — the browser does not expose `indexedDB` at all. Site data
     * is switched off for this origin, so nothing can be stored and nothing
     * can be asked. Chrome and Brave both do this when cookies/site data are
     * blocked for the site.
     *
     * `refused` — the API is there and `open()` failed anyway. The underlying
     * error is carried through verbatim, because `QuotaExceededError`,
     * `SecurityError` and `VersionError` want three different answers and
     * guessing between them helps nobody.
     *
     * Neither is about HTTPS. IndexedDB has no secure-context requirement,
     * and `http://localhost` counts as secure for the things that do — which
     * is the first thing everybody reasonably suspects, so the message says
     * it rather than leaving somebody to chase a red herring.
     */
    constructor(reason = 'missing', cause = null) {
        const detail = reason === 'missing'
            ? 'This browser has switched off local storage for this site, so there is nowhere '
              + 'to keep your work. It is a privacy setting — blocked cookies or site data — '
              + 'not a fault in the app, and not about HTTPS.'
            : `This browser would not open local storage${cause?.name ? ` (${cause.name})` : ''}. `
              + 'A private window, a full disk, or blocked site data will all do this.';

        super(`${detail} Allow cookies and site data for this site, then reload.`);

        this.name = 'StorageBlockedError';
        this.reason = reason;
        this.cause = cause;
    }
}
