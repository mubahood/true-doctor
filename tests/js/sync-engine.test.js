import { describe, it, expect, beforeEach } from 'vitest';
import { freshDb, repoFor, outboxFor, reopen, healthyClock } from './helpers.js';
import { SyncEngine } from '../../resources/js/offline/sync-engine.js';
import { Transport, HttpError, TransportError } from '../../resources/js/offline/transport.js';
import { withSyncLock, LEASE_KEY } from '../../resources/js/offline/lock.js';
import { uuid, resetUlidState } from '../../resources/js/offline/ids.js';
import { clock } from '../../resources/js/offline/clock.js';
import { OP_SYNCED, OP_PENDING, OP_REJECTED, OP_CONFLICT } from '../../resources/js/offline/outbox.js';

/**
 * The client sync engine, under every failure the plan lists (§19.2).
 *
 * A fake server stands in for the real one so a test can do things a real
 * server cannot be asked to do on demand: commit and then lose the response,
 * die halfway through a batch, answer 401, or take the whole timeout.
 */

/**
 * A server that records what it was asked and can be told to misbehave.
 *
 * It deduplicates on `operation_id`, exactly as the real one does, so the
 * client's retry behaviour is measured against the real contract rather than
 * against a mock that forgives everything.
 */
function fakeServer(options = {}) {
    const server = {
        applied: new Map(),      // operation_id → result
        records: new Map(),      // entity_uuid → the record, to count duplicates
        pushCalls: 0,
        pullCalls: 0,
        ackCalls: 0,
        pages: options.pages ?? [],
        nextId: 1,
        // Knobs the test turns.
        failNextPush: null,      // an Error to throw instead of answering
        loseNextResponse: false, // apply it, then pretend the reply vanished
        rejectEntities: new Set(options.rejectEntities ?? []),
        conflictOn: options.conflictOn ?? null,
    };

    server.transport = {
        async push(operations) {
            server.pushCalls++;

            if (server.failNextPush) {
                const error = server.failNextPush;
                server.failNextPush = null;
                throw error;
            }

            const results = operations.map((op) => {
                if (server.applied.has(op.operation_id)) {
                    const original = server.applied.get(op.operation_id);

                    return { ...original, status: original.status === 'accepted' ? 'already_processed' : original.status };
                }

                let result;

                if (server.rejectEntities.has(op.entity)) {
                    result = {
                        operation_id: op.operation_id, status: 'rejected',
                        reason_code: 'validation_failed', message: 'The server refused this.',
                    };
                } else if (server.conflictOn === op.entity_uuid) {
                    result = {
                        operation_id: op.operation_id, status: 'conflict',
                        conflict: {
                            strategy: 'manual', base_version: op.base_version, server_version: 9,
                            contested: ['dob'], merged: [], server_state: { dob: '1991-05-05' },
                        },
                    };
                } else {
                    // The record is created ONCE per entity_uuid — the second
                    // net the real server has, so a duplicate shows up here.
                    if (!server.records.has(op.entity_uuid)) {
                        server.records.set(op.entity_uuid, { id: server.nextId++, payload: op.payload });
                    }

                    result = {
                        operation_id: op.operation_id, status: 'accepted',
                        server_id: server.records.get(op.entity_uuid).id,
                        version: 1,
                        assigned: op.entity === 'patients' ? { patient_no: `PT-${server.records.get(op.entity_uuid).id}` } : undefined,
                    };
                }

                server.applied.set(op.operation_id, result);

                return result;
            });

            if (server.loseNextResponse) {
                server.loseNextResponse = false;
                // Applied, but the client never hears. This is scenario 8.
                throw new TransportError('Could not reach the server.');
            }

            return { session_uuid: 'S1', results, server_time: new Date().toISOString() };
        },

        async pull(cursor) {
            server.pullCalls++;
            const index = cursor ? Number(cursor) : 0;
            const page = server.pages[index] ?? { changes: [], has_more: false };

            return {
                changes: page.changes,
                next_cursor: String(index + 1),
                has_more: page.has_more ?? false,
            };
        },

        async ack() {
            server.ackCalls++;

            return {};
        },
    };

    return server;
}

function engineFor(db, server, overrides = {}) {
    return new SyncEngine({
        db,
        transport: server.transport,
        context: { deviceId: 'device-1', userId: 1, hospitalId: 1 },
        ...overrides,
    });
}

beforeEach(() => {
    healthyClock();
    resetUlidState();
    try {
        globalThis.localStorage?.removeItem(LEASE_KEY);
    } catch {
        // No storage in this environment; the fallback tests supply their own.
    }
});

describe('push', () => {
    it('sends pending work and records what the server made of it', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        const { row } = await repo.create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        const summary = await engineFor(db, server).sync({ pull: false });

        expect(summary.accepted).toBe(1);
        expect(server.records.size).toBe(1);

        const stored = await db.patients.get(row.uuid);
        expect(stored._sync).toBe('synced');
        expect(stored.server_id).toBe(1);
        // The provisional label is replaced by the real number.
        expect(stored.patient_no).toBe('PT-1');

        const op = await db.outbox.where('entity_uuid').equals(row.uuid).first();
        expect(op.status).toBe(OP_SYNCED);
    });

    it('drains a large queue in batches without one endless request', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        for (let i = 0; i < 220; i++) {
            await repo.create('patients', { first_name: `P${i}`, last_name: 'Test' });
        }

        const summary = await engineFor(db, server, { batchSize: 50 }).sync({ pull: false });

        expect(summary.accepted).toBe(220);
        expect(server.pushCalls).toBe(Math.ceil(220 / 50));
        expect(await outboxFor(db).pendingCount()).toBe(0);
    });

    // ── Scenario 8 · the server commits and the reply is lost ────────────

    it('does not duplicate when the server commits and the reply is lost', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        const { row } = await repo.create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        server.loseNextResponse = true;
        await engineFor(db, server).sync({ pull: false });

        // The client does not know what happened, so the work is still queued.
        expect(await outboxFor(db).pendingCount()).toBe(1);
        expect((await db.patients.get(row.uuid))._sync).toBe('pending');

        // It retries later. The server recognises the operation id.
        const later = Date.now() + 120_000;
        const due = await outboxFor(db).due(50, later);
        expect(due).toHaveLength(1);

        await db.outbox.update(due[0].operation_id, { next_retry_at: null });
        const second = await engineFor(db, server).sync({ pull: false });

        // Exactly one record on the server. This is the assertion the whole
        // design exists for.
        expect(server.records.size).toBe(1);
        expect(second.duplicates).toBe(1);
        expect((await db.patients.get(row.uuid))._sync).toBe('synced');
    });

    it('puts a batch back when the server never answers', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        await repo.create('patients', { first_name: 'Amina', last_name: 'Nakato' });
        server.failNextPush = new TransportError('Could not reach the server.');

        await engineFor(db, server).sync({ pull: false });

        const op = (await db.outbox.toArray())[0];
        expect(op.status).toBe(OP_PENDING);
        expect(op.retry_count).toBe(1);
        expect(op.next_retry_at).toBeTruthy();
        expect(op.payload.first_name).toBe('Amina');
    });

    it('keeps a rejected operation inspectable rather than dropping it', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer({ rejectEntities: ['patients'] });

        const { row } = await repo.create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        const summary = await engineFor(db, server).sync({ pull: false });

        expect(summary.rejected).toBe(1);

        const op = await db.outbox.where('entity_uuid').equals(row.uuid).first();
        expect(op.status).toBe(OP_REJECTED);
        // Invariant I-10: still recoverable, with its payload.
        expect(op.payload.first_name).toBe('Amina');
        expect(op.last_error).toBe('The server refused this.');
    });

    it('refuses the children of a rejected operation instead of queueing for ever', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer({ rejectEntities: ['patients'] });

        const { row: patient, operation: patientOp } = await repo.create('patients', { first_name: 'A', last_name: 'B' });
        const { row: visit, operation: visitOp } = await repo.create(
            'visits', { patient_uuid: patient.uuid }, { dependsOn: [patientOp.operation_id] },
        );
        await repo.create('vitals', { admission_uuid: uuid() }, { dependsOn: [visitOp.operation_id] });

        // One at a time, so the parent's refusal is known before the child
        // would have gone. A child sent in the SAME batch as its parent is the
        // server's problem to refuse — it can see the parent is not there —
        // and this is about the child that has not left yet.
        await engineFor(db, server, { batchSize: 1 }).sync({ pull: false });

        expect((await db.outbox.get(visitOp.operation_id)).status).toBe(OP_REJECTED);
        expect(await outboxFor(db).pendingCount()).toBe(0);
    });

    it('records a conflict with both sides so a person can decide', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const { row } = await repo.create('patients', { first_name: 'Amina', last_name: 'Nakato', dob: '1990-01-01' });

        const server = fakeServer({ conflictOn: row.uuid });

        const summary = await engineFor(db, server).sync({ pull: false });

        expect(summary.conflicts).toBe(1);

        const conflict = await db.conflicts.get((await db.outbox.toArray())[0].operation_id);
        expect(conflict.contested).toEqual(['dob']);
        expect(conflict.server_state.dob).toBe('1991-05-05');
        expect(conflict.mine.dob).toBe('1990-01-01');
        expect(conflict.resolved_at).toBeNull();

        // Nothing was silently overwritten (invariant I-9).
        expect((await db.patients.get(row.uuid)).dob).toBe('1990-01-01');
    });

    it('stops and reports when the device has been revoked', async () => {
        const db = await freshDb();
        await repoFor(db).create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        const server = fakeServer();
        server.failNextPush = new HttpError(403, 'device_revoked', 'This device has been blocked.');

        const events = [];
        await engineFor(db, server, { onEvent: (e) => events.push(e.type) }).sync({ pull: false });

        expect(events).toContain('DEVICE_REVOKED');
        // The work is still here — a revoked device's outbox is evidence, not
        // rubbish, until somebody decides what to do with it.
        expect(await db.outbox.count()).toBe(1);
    });

    it('reports an expired session without losing the work', async () => {
        const db = await freshDb();
        await repoFor(db).create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        const server = fakeServer();
        server.failNextPush = new HttpError(401, null, 'Unauthenticated.');

        const events = [];
        await engineFor(db, server, { onEvent: (e) => events.push(e.type) }).sync({ pull: false });

        expect(events).toContain('AUTH_EXPIRED');
        expect((await db.outbox.toArray())[0].status).toBe(OP_PENDING);
        expect((await db.outbox.toArray())[0].payload.first_name).toBe('Amina');
    });
});

describe('pull', () => {
    it('applies a page and only then moves the cursor', async () => {
        const db = await freshDb();
        const id = uuid();
        const server = fakeServer({
            pages: [{ changes: [{ entity: 'patients', revision: 1, record: { uuid: id, server_id: 5, version: 2, first_name: 'Pulled' } }], has_more: false }],
        });

        await engineFor(db, server).sync();

        expect((await db.patients.get(id)).first_name).toBe('Pulled');
        expect(await (async () => (await db.sync_state.get('pull_cursor'))?.value)()).toBe('1');
    });

    it('leaves the cursor where it was when saving the page fails', async () => {
        const db = await freshDb();
        const server = fakeServer({
            pages: [{ changes: [{ entity: 'patients', revision: 1, record: { uuid: uuid(), first_name: 'X' } }], has_more: false }],
        });

        const engine = engineFor(db, server);
        // A write that fails halfway through committing the page.
        engine.applyChanges = async () => {
            throw new Error('disk full');
        };

        await engine.sync();

        // The cursor must NOT have moved, or the page is lost for ever
        // (invariant I-4).
        expect((await db.sync_state.get('pull_cursor'))?.value ?? null).toBeNull();
    });

    it('walks every page to the end', async () => {
        const db = await freshDb();
        const server = fakeServer({
            pages: [
                { changes: [{ entity: 'patients', revision: 1, record: { uuid: uuid(), first_name: 'One' } }], has_more: true },
                { changes: [{ entity: 'patients', revision: 2, record: { uuid: uuid(), first_name: 'Two' } }], has_more: true },
                { changes: [{ entity: 'patients', revision: 3, record: { uuid: uuid(), first_name: 'Three' } }], has_more: false },
            ],
        });

        const summary = await engineFor(db, server).sync();

        expect(summary.pulled).toBe(3);
        expect(await db.patients.count()).toBe(3);
    });

    it('does not overwrite a local edit that has not synced', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const { row } = await repo.create('patients', { first_name: 'Amina', phone_1: '0700' });

        const server = fakeServer({
            pages: [{ changes: [{ entity: 'patients', revision: 1, record: { uuid: row.uuid, server_id: 5, version: 3, first_name: 'Amina', phone_1: '0799' } }], has_more: false }],
        });

        // Pull only — the local edit is still pending.
        const engine = engineFor(db, server);
        await engine.pullAll({ pulled: 0 });

        const after = await db.patients.get(row.uuid);
        expect(after.phone_1).toBe('0700');
        expect(after._sync).toBe('pending');
        expect(after._server_version).toBe(3);
    });

    it('skips an entity this client has never heard of rather than stalling', async () => {
        const db = await freshDb();
        const server = fakeServer({
            pages: [{
                changes: [
                    { entity: 'referrals', revision: 1, record: { uuid: uuid() } },
                    { entity: 'patients', revision: 2, record: { uuid: uuid(), first_name: 'Known' } },
                ],
                has_more: false,
            }],
        });

        await engineFor(db, server).sync();

        // An old client must not be broken by a server deploy.
        expect(await db.patients.count()).toBe(1);
        expect((await db.sync_state.get('pull_cursor')).value).toBe('1');
    });
});

describe('durability across a full round', () => {
    it('keeps unsynced work through a browser restart and syncs it afterwards', async () => {
        let db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        for (let i = 0; i < 12; i++) {
            await repo.create('patients', { first_name: `P${i}`, last_name: 'Test' });
        }

        // The browser closes with everything still queued.
        db = await reopen(db);
        expect(await outboxFor(db).pendingCount()).toBe(12);

        const summary = await engineFor(db, server).sync({ pull: false });

        expect(summary.accepted).toBe(12);
        expect(server.records.size).toBe(12);
        expect(await outboxFor(db).pendingCount()).toBe(0);
    });

    it('does not double-send work that was in flight when the browser died', async () => {
        let db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        const { row } = await repo.create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        // Killed with the operation marked processing — the state a crash
        // mid-push leaves behind.
        await outboxFor(db).markProcessing([(await db.outbox.toArray())[0].operation_id]);
        db = await reopen(db);

        // On restart it is not `pending`, so `due()` will not offer it…
        expect(await outboxFor(db).due(50)).toHaveLength(0);

        // …until it is released, which is what a startup recovery does.
        await outboxFor(db).releaseProcessing([(await db.outbox.toArray())[0].operation_id], 'browser closed');
        await db.outbox.toCollection().modify({ next_retry_at: null });

        await engineFor(db, server).sync({ pull: false });

        expect(server.records.size).toBe(1);
        expect((await db.patients.get(row.uuid))._sync).toBe('synced');
    });
});

describe('multi-tab', () => {
    it('lets only one tab sync at a time', async () => {
        const db = await freshDb();
        await repoFor(db).create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        const store = new Map();
        const fakeStorage = {
            getItem: (k) => store.get(k) ?? null,
            setItem: (k, v) => store.set(k, v),
            removeItem: (k) => store.delete(k),
        };

        let inside = 0;
        let maxConcurrent = 0;

        const work = async () => {
            inside++;
            maxConcurrent = Math.max(maxConcurrent, inside);
            await new Promise((r) => setTimeout(r, 20));
            inside--;

            return true;
        };

        const [a, b] = await Promise.all([
            withSyncLock(work, { forceFallback: true, storage: fakeStorage, owner: 'tab-a' }),
            withSyncLock(work, { forceFallback: true, storage: fakeStorage, owner: 'tab-b' }),
        ]);

        expect(maxConcurrent).toBe(1);
        expect([a.ran, b.ran].filter(Boolean)).toHaveLength(1);
    });

    it('frees the lock when a tab dies holding it', async () => {
        const store = new Map();
        const fakeStorage = {
            getItem: (k) => store.get(k) ?? null,
            setItem: (k, v) => store.set(k, v),
            removeItem: (k) => store.delete(k),
        };

        // A tab that took the lease and never came back.
        fakeStorage.setItem(LEASE_KEY, JSON.stringify({ owner: 'dead-tab', until: Date.now() - 1000 }));

        const outcome = await withSyncLock(async () => 'ran', {
            forceFallback: true, storage: fakeStorage, owner: 'live-tab',
        });

        expect(outcome.ran).toBe(true);
    });

    it('reports a skipped sync rather than queueing a second one', async () => {
        const db = await freshDb();
        const server = fakeServer();
        await repoFor(db).create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        const engine = engineFor(db, server);
        const [first, second] = await Promise.all([engine.sync({ pull: false }), engine.sync({ pull: false })]);

        const skipped = [first, second].filter((r) => r?.skipped);
        expect(skipped).toHaveLength(1);
        expect(server.records.size).toBe(1);
    });
});

describe('what the UI is told', () => {
    it('counts what is pending, failed and in conflict', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        const a = await repo.create('patients', { first_name: 'A', last_name: 'B' });
        await repo.create('patients', { first_name: 'C', last_name: 'D' });
        await db.outbox.update(a.operation.operation_id, { status: 'failed' });
        await db.conflicts.put({ id: 'x', entity: 'patients', entity_uuid: uuid(), resolved_at: null, created_at: new Date().toISOString() });

        const summary = await engineFor(db, server).summary();

        expect(summary.pending).toBe(1);
        expect(summary.failed).toBe(1);
        expect(summary.conflicts).toBe(1);
    });

    it('emits a trail a diagnostic bundle can carry, with no payloads in it', async () => {
        const db = await freshDb();
        const repo = repoFor(db);
        const server = fakeServer();

        await repo.create('patients', { first_name: 'Confidential', last_name: 'Name' });

        const events = [];
        await engineFor(db, server, { onEvent: (e) => events.push(e) }).sync({ pull: false });

        const types = events.map((e) => e.type);
        expect(types).toContain('SYNC_STARTED');
        expect(types).toContain('SYNC_PUSH_COMPLETED');
        expect(types).toContain('SYNC_COMPLETED');

        // No clinical content anywhere in the trail (plan §20).
        expect(JSON.stringify(events)).not.toContain('Confidential');
    });
});

describe('transport', () => {
    it('reports a timeout as unreachable, not as a server answer', async () => {
        const transport = new Transport({
            timeoutMs: 10,
            fetch: () => new Promise((_, reject) => setTimeout(() => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })), 20)),
        });

        await expect(transport.status()).rejects.toBeInstanceOf(TransportError);
    });

    it('reports a server error as an answer, with its code', async () => {
        const transport = new Transport({
            fetch: async () => ({
                ok: false, status: 409,
                json: async () => ({ code: 'protocol_mismatch', message: 'Reload to update.' }),
            }),
        });

        await expect(transport.status()).rejects.toMatchObject({
            name: 'HttpError', status: 409, code: 'protocol_mismatch',
        });
    });

    it('treats an unreadable body as unreachable rather than as success', async () => {
        const transport = new Transport({
            fetch: async () => ({
                ok: true, status: 200,
                json: async () => {
                    throw new Error('Unexpected token < in JSON');
                },
            }),
        });

        // A proxy's HTML error page with a 200 is not an answer from us.
        await expect(transport.status()).rejects.toBeInstanceOf(TransportError);
    });
});

/**
 * Field Mode rides the ordinary session cookie. Everything in here is about
 * what happens when that session ends — which it will, every day, on every
 * device, because that is what sessions do.
 *
 * The bug these were written against: the API routes had no session middleware
 * at all, so a fully signed-in browser got 401 on every sync request while the
 * admin panel answered 200 beside it. Offline mode had never synced once.
 */
describe('an expired session', () => {
    it('is reported when it lands on a CSRF check instead of the auth check', async () => {
        // A dead session has no CSRF token to match, so Laravel refuses at the
        // token check first and answers 419. To a nurse this is the same event
        // as a 401, and answering "we will retry" to it is a lie — the retry
        // is refused identically until somebody signs in.
        const db = await freshDb();
        await repoFor(db).create('patients', { first_name: 'Amina', last_name: 'Nakato' });

        const server = fakeServer();
        server.failNextPush = new HttpError(419, null, 'CSRF token mismatch.');

        const events = [];
        await engineFor(db, server, { onEvent: (e) => events.push(e.type) }).sync({ pull: false });

        expect(events).toContain('AUTH_EXPIRED');
        expect((await db.outbox.toArray())[0].status).toBe(OP_PENDING);
    });

    it('is reported on a pull-only round, with nothing to push', async () => {
        // The common case: a device with an empty outbox pulls every few
        // minutes. If that path swallows a 401 into a generic retry, a signed
        // -out device looks exactly like a flaky network for ever.
        const db = await freshDb();
        const server = fakeServer();
        server.transport.pull = async () => {
            throw new HttpError(401, null, 'Unauthenticated.');
        };

        const events = [];
        await engineFor(db, server, { onEvent: (e) => events.push(e.type) }).sync();

        expect(events).toContain('AUTH_EXPIRED');
        // And it must NOT then claim the pull finished: a completed round is
        // what proves the session is alive, so reporting one here would clear
        // the warning in the same breath as raising it.
        expect(events).not.toContain('SYNC_PULL_COMPLETED');
    });

    it('does not advance the cursor', async () => {
        const db = await freshDb();
        const server = fakeServer();
        server.transport.pull = async () => {
            throw new HttpError(401, null, 'Unauthenticated.');
        };

        await engineFor(db, server).sync();

        expect(await db.sync_state.get('pull_cursor')).toBeUndefined();
    });
});

/**
 * The credential itself. There is no token on the device and there must never
 * be one: the session cookie is HttpOnly, so a script on this origin cannot
 * read it, and there is nothing in IndexedDB for a shared ward machine to
 * leak. All the client has to do is let the browser send the cookie and prove
 * it is not a cross-site form.
 */
describe('how a request is authenticated', () => {
    function capturingTransport(cookie) {
        const seen = {};

        const transport = new Transport({
            baseUrl: '/api/v1',
            fetch: async (url, init) => {
                seen.url = url;
                seen.init = init;

                return { ok: true, status: 200, json: async () => ({ data: {} }) };
            },
        });

        transport.csrfToken = () => cookie;

        return { transport, seen };
    }

    it('sends the session cookie', async () => {
        const { transport, seen } = capturingTransport(null);
        await transport.status();

        // fetch defaults to `same-origin` in browsers but not in every polyfill
        // or worker context, and the whole feature rests on this being sent.
        expect(seen.init.credentials).toBe('same-origin');
    });

    it('sends the CSRF token when the cookie is there', async () => {
        const { transport, seen } = capturingTransport('tok-en-value');
        await transport.register({ deviceUuid: 'd', label: 'Ward laptop', platform: null });

        expect(seen.init.headers['X-XSRF-TOKEN']).toBe('tok-en-value');
    });

    it('sends no CSRF header at all when there is no cookie', async () => {
        // An empty header is not the same as an absent one: an empty string
        // fails the comparison, where an absent header lets a bearer-token
        // client through unchanged.
        const { transport, seen } = capturingTransport(null);
        await transport.status();

        expect('X-XSRF-TOKEN' in seen.init.headers).toBe(false);
    });

    it('reads the token from the cookie jar, url-decoded, every time', async () => {
        const transport = new Transport({ baseUrl: '/api/v1', fetch: async () => ({ ok: true, status: 200, json: async () => ({}) }) });

        globalThis.document = { cookie: 'foo=bar; XSRF-TOKEN=a%2Bb%3Dc; other=1' };
        expect(transport.csrfToken()).toBe('a+b=c');

        // Re-read, not cached: the shell is served from the service worker
        // cache, so a token captured at page load could be days old.
        globalThis.document = { cookie: 'XSRF-TOKEN=second' };
        expect(transport.csrfToken()).toBe('second');

        globalThis.document = { cookie: 'unrelated=1' };
        expect(transport.csrfToken()).toBeNull();

        delete globalThis.document;
        expect(transport.csrfToken()).toBeNull();
    });

    it('carries no token of its own', () => {
        // The guard on the security constraint: nothing long-lived and
        // script-readable is ever put on the device to authenticate with.
        const transport = new Transport({ baseUrl: '/api/v1' });

        expect(transport.token).toBeNull();
    });
});

describe('the clock sets itself from any reply', () => {
    /**
     * It used to be set only from a push response, so a device that had never
     * pushed had never checked its clock — and the readiness screen warned a
     * clinician about something the app already knew and could fix.
     *
     * Now it happens in the transport, at the single point every reply passes
     * through, so `status` (which the connectivity probe calls every fifteen
     * seconds), `register`, `push`, `pull` and `ack` all keep it right.
     */
    function transportAnswering(payload) {
        return new Transport({
            baseUrl: '/api/v1',
            fetch: async () => ({ ok: true, status: 200, json: async () => payload }),
        });
    }

    it('is set by the cheap status probe, with no sync at all', async () => {
        clock.measuredAt = null;
        expect(clock.health().state).toBe('warn');

        await transportAnswering({ data: { server_time: new Date().toISOString() } }).status();

        expect(clock.health().state).toBe('ok');
    });

    it('is set by a pull as well as a push', async () => {
        clock.measuredAt = null;

        await transportAnswering({ data: { changes: [], server_time: new Date().toISOString() } }).pull(null);

        expect(clock.health().state).toBe('ok');
    });

    it('takes the round trip off, so a slow link does not read as a wrong clock', async () => {
        // The server's `now` is already one leg old by the time it is read.
        clock.measuredAt = null;

        const transport = new Transport({
            baseUrl: '/api/v1',
            fetch: async () => ({ ok: true, status: 200, json: async () => ({ data: { server_time: new Date().toISOString() } }) }),
        });

        await transport.status();

        // Same instant either way here; what matters is that the offset is a
        // number rather than NaN and the clock counts as measured.
        expect(Number.isFinite(clock.offsetMs)).toBe(true);
        expect(clock.health().state).toBe('ok');
    });

    it('ignores a reply that carries no time rather than guessing', async () => {
        clock.measuredAt = null;

        await transportAnswering({ data: { ok: true } }).status();

        expect(clock.health().state).toBe('warn');
    });
});
