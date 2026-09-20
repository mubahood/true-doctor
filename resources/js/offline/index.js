import { openOfflineDb, listOfflineDatabases, storageAvailable, StorageBlockedError, SYNC_PROTOCOL_VERSION, ENTITY_STORES, REFERENCE_STORES } from './db.js';
import { Repository } from './repository.js';
import { Outbox, OP_PENDING, ATTENTION_STATES } from './outbox.js';
import { SyncEngine } from './sync-engine.js';
import { Transport, HttpError } from './transport.js';
import { Connectivity, OFFLINE } from './connectivity.js';
import { TabChannel } from './lock.js';
import { ConflictResolver } from './conflicts.js';
import { clock } from './clock.js';
import { uuid } from './ids.js';
import { appBase, appPath } from './base.js';

/**
 * Field Mode, assembled.
 *
 * One object the UI talks to, so a screen never has to know whether it is
 * reading from IndexedDB or waiting on a network. `boot()` opens the local
 * database, works out whether this browser is registered, starts watching the
 * connection, and from then on every write goes to the repository and every
 * upload happens when it can.
 */

const DEVICE_KEY = 'td-offline-device';

export class OfflineClient {
    constructor({ hospitalId, userId, baseUrl = null, fetch = undefined } = {}) {
        this.hospitalId = hospitalId;
        this.userId = userId;
        // `null`, not a literal: Transport works the base out from where the
        // app is actually served. Passing `/api/v1` here overrode that and
        // pointed every request at a path that does not exist.
        this.transport = new Transport({ baseUrl: baseUrl ?? undefined, fetch });
        this.channel = null;
        this.db = null;
        this.repo = null;
        this.outbox = null;
        this.engine = null;
        this.connectivity = null;
        this.conflicts = null;
        this.deviceId = null;
        this.listeners = new Set();
        this.log = [];
        /**
         * The session behind this browser has ended.
         *
         * Field Mode rides the ordinary session cookie, and a session expires
         * — that is what sessions are for. It is not a failure of the device
         * and nothing is lost by it: every unsent record stays exactly where
         * it is and goes up the moment somebody signs in again. But it has to
         * be SAID, because otherwise a queue that never drains looks like a
         * broken app rather than a solvable one.
         */
        this.authExpired = false;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    async boot() {
        this.deviceId = this.readDeviceId();
        this.transport.setDeviceId(this.deviceId);

        // Asked before Dexie is, so the answer is a sentence somebody can act
        // on rather than `MissingAPIError` surfacing as an unhandled Alpine
        // expression error with a blank page underneath it.
        if (!storageAvailable()) {
            throw new StorageBlockedError('missing');
        }

        this.db = openOfflineDb(this.hospitalId, this.userId);

        try {
            await this.db.open();
        } catch (error) {
            // Some browsers expose `indexedDB` and then refuse to open one —
            // a private window, a quota of zero, a corrupt profile. Same
            // message, because to the person in front of it the answer is the
            // same: this browser will not keep anything for you.
            throw new StorageBlockedError('refused', error);
        }

        const context = { deviceId: this.deviceId ?? 'unregistered', userId: this.userId, hospitalId: this.hospitalId };

        this.repo = new Repository(this.db, context);
        this.outbox = new Outbox(this.db);
        this.conflicts = new ConflictResolver(this.db, context);
        this.channel = new TabChannel(`td-offline-${this.hospitalId}-${this.userId}`);

        this.engine = new SyncEngine({
            db: this.db,
            transport: this.transport,
            context,
            protocolVersion: SYNC_PROTOCOL_VERSION,
            channel: this.channel,
            onEvent: (event) => this.record(event),
        });

        this.connectivity = new Connectivity({
            transport: this.transport,
            onChange: () => this.publish(),
        });

        // Anything left `processing` was in flight when the browser died. It
        // goes back on the queue: the server deduplicates, so re-sending is
        // safe, and leaving it stuck would strand the work for ever.
        await this.recoverInFlight();

        // Another tab synced, so this one's numbers are stale.
        this.channel.on((message) => {
            if (message?.type === 'synced') {
                this.publish();
            }
        });

        this.connectivity.start();
        await this.publish();

        return this;
    }

    async recoverInFlight() {
        const stranded = await this.db.outbox.where('status').equals('processing').toArray();

        if (stranded.length === 0) {
            return 0;
        }

        await this.db.outbox.where('status').equals('processing').modify({
            status: OP_PENDING,
            next_retry_at: null,
            last_error: 'The app closed while this was being sent. It will be sent again.',
        });

        this.record({ type: 'RECOVERED_IN_FLIGHT', detail: { count: stranded.length } });

        return stranded.length;
    }

    // ── Device registration ──────────────────────────────────────────────

    readDeviceId() {
        try {
            return globalThis.localStorage?.getItem(DEVICE_KEY) ?? null;
        } catch {
            return null;
        }
    }

    isRegistered() {
        return this.deviceId !== null;
    }

    /**
     * Ask the server to trust this browser with patient data.
     *
     * The id is minted here and kept, so re-registering the same machine does
     * not produce a second entry on the hospital's device list.
     */
    async registerDevice(label = null, platform = null) {
        const deviceUuid = this.deviceId ?? uuid();
        // Never blocks on a name. See `describeThisMachine`.
        const named = label?.trim() || describeThisMachine(signedInName());

        await this.transport.register({ deviceUuid, label: named, platform: platform ?? guessPlatform() });

        try {
            globalThis.localStorage?.setItem(DEVICE_KEY, deviceUuid);
        } catch {
            // A locked-down profile. Offline still works for this session; the
            // device simply re-registers next time.
        }

        this.deviceId = deviceUuid;
        this.transport.setDeviceId(deviceUuid);
        this.engine.context.deviceId = deviceUuid;
        this.repo.context.deviceId = deviceUuid;

        await this.publish();

        return deviceUuid;
    }

    // ── Syncing ──────────────────────────────────────────────────────────

    async sync(options = {}) {
        if (!this.isRegistered()) {
            return { skipped: true, reason: 'This device is not set up for offline use yet.' };
        }

        this.connectivity.syncStarted();

        let summary;

        try {
            summary = await this.engine.sync(options);
            await this.engine.recordSyncTime();
            this.connectivity.syncFinished(summary);
        } catch (error) {
            this.connectivity.observeFailure(error);

            if (error instanceof HttpError && error.code === 'device_revoked') {
                await this.wipeAfterRevocation(error.message);
            }

            summary = { errors: [String(error?.message ?? error)] };
        }

        await this.publish();

        return summary;
    }

    /** Sync now if there is anything to send and the server is there. */
    async syncIfUseful() {
        if (!this.connectivity.isReachable()) {
            return null;
        }

        const pending = await this.outbox.pendingCount();

        return pending > 0 ? this.sync() : this.sync({ pull: true });
    }

    // ── Reading and writing ──────────────────────────────────────────────

    async search(term) {
        const needle = (term ?? '').trim().toLowerCase();

        if (needle === '') {
            return this.db.patients.orderBy('updated_at').reverse().limit(25).toArray();
        }

        return this.db.patients
            .filter((p) => !p.deleted_at && (p.search_key ?? '').includes(needle))
            .limit(50)
            .toArray();
    }

    // ── What the UI shows ────────────────────────────────────────────────

    on(listener) {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    async status() {
        const counts = await this.outbox.countByStatus();
        const health = clock.health();

        return {
            connection: this.connectivity?.describe() ?? { state: OFFLINE, label: 'Working offline' },
            registered: this.isRegistered(),
            authExpired: this.authExpired,
            pending: (counts.pending ?? 0) + (counts.processing ?? 0),
            failed: counts.failed ?? 0,
            rejected: counts.rejected ?? 0,
            conflicts: await this.db.conflicts.filter((c) => !c.resolved_at).count(),
            lastSync: (await this.db.sync_state.get('last_sync'))?.value ?? null,
            clock: health,
            storage: await this.storage(),
        };
    }

    async publish() {
        const status = await this.status();

        for (const listener of this.listeners) {
            try {
                listener(status);
            } catch {
                // One bad listener must not stop the indicator updating.
            }
        }

        return status;
    }

    /**
     * How much room is left.
     *
     * Reported rather than acted on: nothing unsynced is ever deleted to make
     * space (plan §36). The user is warned and told what to do — sync, or free
     * space — because the alternative is silently dropping somebody's work.
     */
    async storage() {
        if (!navigator?.storage?.estimate) {
            return { supported: false };
        }

        try {
            const { usage = 0, quota = 0 } = await navigator.storage.estimate();
            const used = quota > 0 ? usage / quota : 0;

            return {
                supported: true,
                usage,
                quota,
                percent: Math.round(used * 100),
                // Warn early: a device that hits the wall mid-shift cannot
                // record anything, and that is not a message to deliver at 95%.
                warn: used > 0.8,
                critical: used > 0.92,
            };
        } catch {
            return { supported: false };
        }
    }

    // ── Diagnostics ──────────────────────────────────────────────────────

    /**
     * A ring buffer of what the engine did, with no clinical content in it —
     * entity type and uuid only (plan §20).
     */
    record(event) {
        if (event?.type === 'AUTH_EXPIRED') {
            this.authExpired = true;
        }

        // Anything the server actually answered proves the session is alive
        // again — including a rejection, which only a signed-in request can
        // earn. Clearing it on success alone would leave the banner up after
        // a pull-only round.
        if (event?.type === 'SYNC_PUSH_COMPLETED' || event?.type === 'SYNC_PULL_COMPLETED') {
            this.authExpired = false;
        }

        this.log.push({ ...event, at: new Date().toISOString() });

        if (this.log.length > 300) {
            this.log.splice(0, this.log.length - 300);
        }
    }

    async diagnostics() {
        return {
            device_id: this.deviceId,
            hospital_id: this.hospitalId,
            protocol_version: SYNC_PROTOCOL_VERSION,
            schema_version: this.db?.verno ?? null,
            clock: clock.health(),
            counts: await this.outbox.countByStatus(),
            attention: (await this.outbox.needingAttention()).map((op) => ({
                operation_id: op.operation_id,
                entity: op.entity,
                entity_uuid: op.entity_uuid,
                status: op.status,
                retry_count: op.retry_count,
                last_error: op.last_error,
            })),
            events: this.log,
        };
    }

    // ── Leaving ──────────────────────────────────────────────────────────

    /**
     * Sign out and clear this device's copy.
     *
     * Refuses while anything is unsent, unless the caller has explicitly said
     * to discard it. Wiping a shift's notes because somebody clicked Sign out
     * is the worst failure in this whole system (plan §12, invariant I-13).
     */
    async signOut({ discardUnsynced = false } = {}) {
        const pending = await this.outbox.pendingCount();
        const attention = await this.db.outbox.where('status').anyOf(ATTENTION_STATES).count();
        const outstanding = pending + attention;

        if (outstanding > 0 && !discardUnsynced) {
            return { wiped: false, outstanding };
        }

        await this.wipe();

        return { wiped: true, outstanding };
    }

    async wipe() {
        this.connectivity?.stop();
        this.channel?.close();

        const name = this.db?.name;
        this.db?.close();

        if (name && globalThis.indexedDB) {
            await new Promise((resolve) => {
                const request = globalThis.indexedDB.deleteDatabase(name);
                request.onsuccess = resolve;
                request.onerror = resolve;
                request.onblocked = resolve;
            });
        }

        try {
            globalThis.localStorage?.removeItem(DEVICE_KEY);
        } catch {
            // Nothing more to do.
        }
    }

    /**
     * The server says this device is no longer trusted.
     *
     * Wiped without asking, including unsent work — that is what revocation
     * MEANS, and a lost laptop is exactly the case it exists for. The message
     * is kept so the next screen can say why.
     */
    async wipeAfterRevocation(message) {
        this.record({ type: 'DEVICE_REVOKED', detail: { message } });

        try {
            globalThis.sessionStorage?.setItem('td-offline-revoked', message ?? 'This device was blocked.');
        } catch {
            // The message is a courtesy; the wipe is the point.
        }

        await this.wipe();
    }
}

function guessPlatform() {
    return globalThis.navigator?.userAgent?.slice(0, 80) ?? null;
}

/**
 * A name for this machine, without asking anybody for one.
 *
 * The label exists so an administrator looking at a list of machines holding
 * patient records can tell them apart and revoke the right one. It does NOT
 * need to be typed by a clinician before they can work — making it a required
 * field turned "let me work offline" into a form, and a form is how somebody
 * decides the feature is not worth the trouble.
 *
 * So one is derived here and the field is offered pre-filled. "Chongomweru
 * Haleem · Chrome on Mac" tells an administrator whose machine it is and
 * which one, which is most of what the label was for. Somebody who wants
 * "Maternity desk laptop" can still type it, here or on the devices page.
 */
export function describeThisMachine(userName = null) {
    const ua = globalThis.navigator?.userAgent ?? '';

    const browser = [
        [/Edg\//, 'Edge'], [/OPR\/|Opera/, 'Opera'], [/Firefox\//, 'Firefox'],
        [/Chrome\//, 'Chrome'], [/Safari\//, 'Safari'],
    ].find(([pattern]) => pattern.test(ua))?.[1] ?? 'Browser';

    const os = [
        [/Windows/, 'Windows'], [/iPhone|iPad|iPod/, 'iPad or iPhone'], [/Android/, 'Android'],
        [/Mac OS X|Macintosh/, 'Mac'], [/Linux/, 'Linux'],
    ].find(([pattern]) => pattern.test(ua))?.[1] ?? 'this device';

    const machine = `${browser} on ${os}`;
    const named = userName?.trim() ? `${userName.trim()} · ${machine}` : machine;

    // The server caps it at 120.
    return named.slice(0, 120);
}

/** The signed-in person's name, if the page says who they are. */
export function signedInName() {
    try {
        return globalThis.document?.querySelector('meta[name="td-user-name"]')?.content?.trim() || null;
    } catch {
        return null;
    }
}

export {
    openOfflineDb, listOfflineDatabases, storageAvailable, StorageBlockedError,
    Repository, Outbox, SyncEngine,
    Transport, Connectivity, TabChannel, ConflictResolver, clock, uuid, appBase, appPath,
    ENTITY_STORES, REFERENCE_STORES, SYNC_PROTOCOL_VERSION,
};
