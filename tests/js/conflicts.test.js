import { describe, it, expect, beforeEach } from 'vitest';
import { freshDb, repoFor, healthyClock } from './helpers.js';
import { ConflictResolver, KEPT_SERVER, KEPT_MINE } from '../../resources/js/offline/conflicts.js';
import { uuid, resetUlidState } from '../../resources/js/offline/ids.js';
import { OP_CANCELLED, OP_PENDING } from '../../resources/js/offline/outbox.js';

/**
 * Deciding a conflict.
 *
 * Detecting one is half a feature. Without a way to decide, the operation sits
 * as `conflict` for ever, the "needs you" count never clears, and the user is
 * told they have unfinished work they cannot finish.
 */

const context = { deviceId: 'device-1', userId: 7 };

beforeEach(() => {
    healthyClock();
    resetUlidState();
});

/** A patient the server and this device disagree about. */
async function contested(db, overrides = {}) {
    const repo = repoFor(db);
    const { row } = await repo.create('patients', {
        first_name: 'Amina', last_name: 'Nakato', dob: '1992-09-09', phone_1: '0711',
    });

    const operationId = (await db.outbox.toArray())[0].operation_id;

    await db.outbox.update(operationId, { status: 'conflict' });

    await db.conflicts.put({
        id: operationId,
        entity: 'patients',
        entity_uuid: row.uuid,
        strategy: 'manual',
        server_version: 5,
        contested: ['dob'],
        merged: ['phone_1'],
        server_state: { dob: '1991-05-05' },
        mine: { uuid: row.uuid, first_name: 'Amina', last_name: 'Nakato', dob: '1992-09-09' },
        created_at: new Date().toISOString(),
        resolved_at: null,
        ...overrides,
    });

    return { row, operationId, resolver: new ConflictResolver(db, context) };
}

describe('seeing what is in dispute', () => {
    it('lists only the open ones', async () => {
        const db = await freshDb();
        const { resolver } = await contested(db);

        await db.conflicts.put({
            id: 'already-done', entity: 'patients', entity_uuid: uuid(),
            contested: ['phone_1'], created_at: new Date().toISOString(),
            resolved_at: new Date().toISOString(),
        });

        expect(await resolver.count()).toBe(1);
        expect(await resolver.open()).toHaveLength(1);
    });

    it('shows the two sides of each contested field, and nothing else', async () => {
        const db = await freshDb();
        const { operationId, resolver } = await contested(db);

        const described = await resolver.describe(operationId);

        expect(described.fields).toEqual([
            { field: 'dob', mine: '1992-09-09', theirs: '1991-05-05' },
        ]);
        // Showing forty unchanged values around the one that matters is how
        // somebody clicks the wrong button.
        expect(described.fields).toHaveLength(1);
        expect(described.merged).toEqual(['phone_1']);
    });
});

describe('keeping the server’s version', () => {
    it('takes their values and lets mine go', async () => {
        const db = await freshDb();
        const { row, operationId, resolver } = await contested(db);

        const result = await resolver.keepServer(operationId);

        expect(result).toEqual({ resolved: true, resolution: KEPT_SERVER });

        const patient = await db.patients.get(row.uuid);
        // The device must stop showing a value the server has rejected, or the
        // next edit produces the same conflict again.
        expect(patient.dob).toBe('1991-05-05');
        expect(patient._sync).toBe('synced');
        expect(patient._server_version).toBe(5);
        expect(patient._server_snapshot.dob).toBe('1991-05-05');

        expect((await db.outbox.get(operationId)).status).toBe(OP_CANCELLED);
        expect((await db.conflicts.get(operationId)).resolution).toBe(KEPT_SERVER);
    });

    it('clears the count so the queue can finish draining', async () => {
        const db = await freshDb();
        const { operationId, resolver } = await contested(db);

        expect(await resolver.count()).toBe(1);
        await resolver.keepServer(operationId);
        expect(await resolver.count()).toBe(0);
    });

    it('writes an audit row naming the fields but not their values', async () => {
        const db = await freshDb();
        const { operationId, resolver } = await contested(db);

        await resolver.keepServer(operationId, 'Reception had the birth certificate.');

        const audit = (await db.audit.toArray()).filter((a) => a.event === 'conflict_resolved');

        expect(audit).toHaveLength(1);
        expect(audit[0].detail.fields).toEqual(['dob']);
        expect(audit[0].detail.user_id).toBe(7);
        // A clinical value in an audit detail is a second copy of the record.
        expect(JSON.stringify(audit[0])).not.toContain('1991-05-05');
    });

    it('does nothing twice', async () => {
        const db = await freshDb();
        const { operationId, resolver } = await contested(db);

        await resolver.keepServer(operationId);
        const second = await resolver.keepServer(operationId);

        expect(second.resolved).toBe(false);
        expect((await db.audit.toArray()).filter((a) => a.event === 'conflict_resolved')).toHaveLength(1);
    });
});

describe('insisting on mine', () => {
    it('queues a NEW operation against the version the server actually holds', async () => {
        const db = await freshDb();
        const { row, operationId, resolver } = await contested(db);

        const result = await resolver.keepMine(operationId);

        expect(result.resolution).toBe(KEPT_MINE);

        const queued = (await db.outbox.toArray()).find((o) => o.status === OP_PENDING);

        // NOT a retry of the old one: that was made against a version that no
        // longer exists and would be contested again for the same reason.
        expect(queued.operation_id).not.toBe(operationId);
        expect(queued.operation).toBe('update');
        expect(queued.base_version).toBe(5);
        // Base == what the server holds, so its merge sees "nobody moved these".
        expect(queued.base_fields).toEqual({ dob: '1991-05-05' });
        expect(queued.payload).toEqual({ uuid: row.uuid, dob: '1992-09-09' });
    });

    it('sends only the contested fields', async () => {
        const db = await freshDb();
        const { operationId, resolver } = await contested(db);

        await resolver.keepMine(operationId);

        const queued = (await db.outbox.toArray()).find((o) => o.status === OP_PENDING);

        // Re-sending the whole record would drag along every value the server
        // merged a moment ago and put them all back in dispute.
        expect(Object.keys(queued.payload).sort()).toEqual(['dob', 'uuid']);
        expect(queued.payload.phone_1).toBeUndefined();
    });

    it('retires the conflicted operation and points at what replaced it', async () => {
        const db = await freshDb();
        const { operationId, resolver } = await contested(db);

        const { operation } = await resolver.keepMine(operationId);

        expect((await db.outbox.get(operationId)).status).toBe(OP_CANCELLED);

        const conflict = await db.conflicts.get(operationId);
        expect(conflict.resolution).toBe(KEPT_MINE);
        expect(conflict.replaced_by).toBe(operation.operation_id);
    });

    it('keeps showing my value locally until the server says otherwise', async () => {
        const db = await freshDb();
        const { row, operationId, resolver } = await contested(db);

        await resolver.keepMine(operationId);

        const patient = await db.patients.get(row.uuid);
        expect(patient.dob).toBe('1992-09-09');
        expect(patient._sync).toBe('pending');
    });

    it('falls back to keeping theirs when there is nothing of mine left to send', async () => {
        const db = await freshDb();
        // An older conflict record whose `mine` never carried the field.
        const { operationId, resolver } = await contested(db, { mine: { uuid: 'x' } });

        const result = await resolver.keepMine(operationId);

        // Better than queueing an empty operation the server will refuse.
        expect(result.resolution).toBe(KEPT_SERVER);
        expect((await db.outbox.toArray()).filter((o) => o.status === OP_PENDING)).toHaveLength(0);
    });
});

describe('a conflict somebody else ended', () => {
    it('closes when the record has moved past the version in dispute', async () => {
        const db = await freshDb();
        const { row, operationId, resolver } = await contested(db);

        // A later pull brings version 9. The argument is over.
        const closed = await resolver.closeSettled('patients', row.uuid, 9);

        expect(closed).toBe(1);
        expect((await db.conflicts.get(operationId)).resolution).toBe('settled_elsewhere');
        expect((await db.outbox.get(operationId)).status).toBe(OP_CANCELLED);
        expect(await resolver.count()).toBe(0);
    });

    it('leaves one alone when the version has not moved', async () => {
        const db = await freshDb();
        const { row, resolver } = await contested(db);

        expect(await resolver.closeSettled('patients', row.uuid, 5)).toBe(0);
        expect(await resolver.count()).toBe(1);
    });

    it('does not touch a different record', async () => {
        const db = await freshDb();
        const { resolver } = await contested(db);

        expect(await resolver.closeSettled('patients', uuid(), 99)).toBe(0);
        expect(await resolver.count()).toBe(1);
    });
});
