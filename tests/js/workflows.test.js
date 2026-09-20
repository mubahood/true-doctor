import { describe, it, expect, beforeEach } from 'vitest';
import { freshDb, repoFor, outboxFor, healthyClock } from './helpers.js';
import { Workflows, ValidationError } from '../../resources/js/offline/workflows.js';
import { uuid, resetUlidState } from '../../resources/js/offline/ids.js';

/**
 * The capture workflows — what a clinician actually does at the bedside.
 *
 * Validation here is not a security boundary; the server re-checks everything.
 * It exists so a nurse finds out about a missing field at the bedside rather
 * than six hours later in a list of rejected operations, and these tests are
 * about that: does the device refuse the things a person would want refusing,
 * and does it queue exactly one operation when it accepts.
 */

function clientFor(db) {
    return {
        db,
        repo: repoFor(db),
        publish: async () => {},
    };
}

beforeEach(() => {
    healthyClock();
    resetUlidState();
});

async function withAdmission(db, overrides = {}) {
    const patientUuid = uuid();
    const admissionUuid = uuid();

    await db.patients.add({ uuid: patientUuid, first_name: 'Grace', last_name: 'Auma', deleted_at: null });
    await db.admissions.add({
        uuid: admissionUuid,
        patient_uuid: patientUuid,
        status: 'admitted',
        bed_name: 'M-04',
        deleted_at: null,
        ...overrides,
    });

    return { patientUuid, admissionUuid };
}

describe('registering a patient', () => {
    it('queues one operation and shows the record as having no number yet', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        const row = await flows.registerPatient({
            first_name: 'Amina', last_name: 'Nakato', phone_1: '0700111222',
            allergies: 'Penicillin, Aspirin',
        });

        expect(row.uuid).toBeTruthy();
        // No number is minted on a device: one that later changes is worse
        // than none, because somebody writes it on a specimen bottle (I-11).
        expect(row.patient_no).toBeNull();
        expect(row.allergies).toEqual(['Penicillin', 'Aspirin']);
        expect(row.search_key).toBe('amina nakato');

        expect(await db.patients.count()).toBe(1);
        expect(await outboxFor(db).pendingCount()).toBe(1);
    });

    it('refuses a patient with no name rather than queueing a rejection', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        await expect(flows.registerPatient({ first_name: 'Amina' })).rejects.toBeInstanceOf(ValidationError);

        // Nothing written and nothing queued — the nurse fixes it now, not in
        // a rejected-operations list six hours later.
        expect(await db.patients.count()).toBe(0);
        expect(await db.outbox.count()).toBe(0);
    });

    it('leaves optional fields empty rather than sending empty strings', async () => {
        const db = await freshDb();
        const row = await new Workflows(clientFor(db)).registerPatient({
            first_name: 'Amina', last_name: 'Nakato', phone_1: '   ', dob: '',
        });

        expect(row.phone_1).toBeNull();
        expect(row.dob).toBeNull();
        expect(row.allergies).toBeNull();
    });
});

describe('correcting a patient', () => {
    it('sends only the fields that actually changed', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        const row = await flows.registerPatient({ first_name: 'Amina', last_name: 'Nakato', phone_1: '0700' });
        await db.outbox.clear();

        await flows.updatePatient(row.uuid, {
            first_name: 'Amina',      // unchanged
            last_name: 'Nakato',      // unchanged
            phone_1: '0711',          // changed
        });

        const op = (await db.outbox.toArray())[0];

        // Every field the device claims to have edited is a candidate for a
        // conflict, so claiming to have edited a value it merely displayed is
        // how a patient ends up with a contested surname nobody touched.
        expect(op.payload.phone_1).toBe('0711');
        expect(op.base_version).toBe(0);

        // No base yet: the server has never confirmed anything about this
        // record, so there is nothing for it to have moved past. The handler
        // treats base_version 0 as "this device is the only writer".
        expect(op.base_fields).toEqual({});
    });

    it('sends the confirmed base once the server has had its say', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        const row = await flows.registerPatient({ first_name: 'Amina', last_name: 'Nakato', phone_1: '0700' });

        // The server accepted it and this is now the confirmed state.
        await db.patients.update(row.uuid, {
            _sync: 'synced',
            _server_version: 3,
            version: 3,
            _server_snapshot: { first_name: 'Amina', last_name: 'Nakato', phone_1: '0700' },
        });
        await db.outbox.clear();

        await flows.updatePatient(row.uuid, { phone_1: '0711' });

        const op = (await db.outbox.toArray())[0];

        expect(op.base_version).toBe(3);
        // The third side of the three-way merge: what the server last said.
        expect(op.base_fields).toEqual({ phone_1: '0700' });
    });

    it('does nothing at all when nothing changed', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        const row = await flows.registerPatient({ first_name: 'Amina', last_name: 'Nakato' });
        await db.outbox.clear();

        const result = await flows.updatePatient(row.uuid, { first_name: 'Amina', last_name: 'Nakato' });

        expect(result.changed).toBe(false);
        expect(await db.outbox.count()).toBe(0);
    });

    it('keeps the search key in step with the name', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        const row = await flows.registerPatient({ first_name: 'Amina', last_name: 'Nakato' });
        await flows.updatePatient(row.uuid, { last_name: 'Okello' });

        // A patient nobody can find is a patient who gets registered twice.
        expect((await db.patients.get(row.uuid)).search_key).toBe('amina okello');
    });

    it('refuses to edit somebody who is not on this device', async () => {
        const db = await freshDb();

        await expect(new Workflows(clientFor(db)).updatePatient(uuid(), { phone_1: '0700' }))
            .rejects.toThrow(/not on this device/);
    });
});

describe('vitals', () => {
    it('records a round against the stay', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);
        const flows = new Workflows(clientFor(db));

        const row = await flows.recordVitals(admissionUuid, {
            temperature: '37.2', pulse: '78', blood_pressure: '120/80', spo2: '97',
        });

        expect(row.admission_uuid).toBe(admissionUuid);
        expect(row.temperature).toBe(37.2);
        expect(row.pulse).toBe(78);
        expect(await outboxFor(db).pendingCount()).toBe(1);
    });

    it('refuses a reading no human body produces', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);
        const flows = new Workflows(clientFor(db));

        // A typo caught at the bedside beats a rejected operation later.
        await expect(flows.recordVitals(admissionUuid, { pulse: '9000' })).rejects.toThrow(/between 20 and 250/);
        expect(await db.vitals.count()).toBe(0);
    });

    it('refuses an empty round', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);

        await expect(new Workflows(clientFor(db)).recordVitals(admissionUuid, {}))
            .rejects.toThrow(/at least one reading/);
    });

    it('accepts a round that is only a blood pressure', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);

        const row = await new Workflows(clientFor(db)).recordVitals(admissionUuid, { blood_pressure: '135/85' });

        expect(row.blood_pressure).toBe('135/85');
        expect(row.pulse).toBeNull();
    });

    it('refuses to record on a discharged stay', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db, { status: 'discharged' });

        await expect(new Workflows(clientFor(db)).recordVitals(admissionUuid, { pulse: '70' }))
            .rejects.toThrow(/discharged/);
    });

    it('refuses to record against a stay this device has never seen', async () => {
        const db = await freshDb();

        await expect(new Workflows(clientFor(db)).recordVitals(uuid(), { pulse: '70' }))
            .rejects.toThrow(/Sync and try again/);
    });

    it('two rounds are two rounds', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);
        const flows = new Workflows(clientFor(db));

        await flows.recordVitals(admissionUuid, { pulse: '70' });
        await flows.recordVitals(admissionUuid, { pulse: '88' });

        // Append-only: nothing to merge, nothing overwritten, both true.
        expect(await db.vitals.count()).toBe(2);
    });
});

describe('nursing notes and medication', () => {
    it('records a note', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);

        const row = await new Workflows(clientFor(db)).recordNursingNote(admissionUuid, '  Comfortable overnight.  ');

        expect(row.note).toBe('Comfortable overnight.');
        expect(await outboxFor(db).pendingCount()).toBe(1);
    });

    it('refuses an empty note', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);

        await expect(new Workflows(clientFor(db)).recordNursingNote(admissionUuid, '   '))
            .rejects.toThrow(/before saving/);
    });

    it('records a dose that was given', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);

        const row = await new Workflows(clientFor(db)).recordMedication(admissionUuid, {
            drug_name: 'Paracetamol', dose: '1g', route: 'oral', status: 'given',
        });

        expect(row.drug_name).toBe('Paracetamol');
        expect(row.status).toBe('given');
    });

    it('records a dose that was deliberately NOT given', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);

        const row = await new Workflows(clientFor(db)).recordMedication(admissionUuid, {
            drug_name: 'Morphine', status: 'refused', note: 'Patient declined.',
        });

        // As important a record as "given", and far more important than a gap.
        expect(row.status).toBe('refused');
        expect(row.note).toBe('Patient declined.');
    });

    it('will not let a dose be recorded without saying whether it was given', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);

        await expect(new Workflows(clientFor(db)).recordMedication(admissionUuid, { drug_name: 'Paracetamol' }))
            .rejects.toThrow(/Whether it was given/);
    });
});

describe('the bench', () => {
    async function withPendingTest(db, overrides = {}) {
        const itemUuid = uuid();

        await db.lab_items.add({
            uuid: itemUuid,
            server_id: 41,
            version: 1,
            lab_order_uuid: uuid(),
            name: 'Haemoglobin',
            unit: 'g/dL',
            reference_range: '12-16',
            result_value: null,
            created_at: new Date().toISOString(),
            deleted_at: null,
            _sync: 'synced',
            _server_version: 1,
            _server_snapshot: { result_value: null },
            ...overrides,
        });

        return itemUuid;
    }

    it('reports a result as an update against the ordered test', async () => {
        const db = await freshDb();
        const itemUuid = await withPendingTest(db);
        const flows = new Workflows(clientFor(db));

        const row = await flows.reportResult(itemUuid, { result_value: ' 13.4 ', result_flag: 'normal' });

        expect(row.result_value).toBe('13.4');

        const op = (await db.outbox.toArray())[0];
        // Never a create: the line is the server's, only the value is ours.
        expect(op.operation).toBe('update');
        expect(op.entity).toBe('lab_items');
        expect(op.base_version).toBe(1);
    });

    it('refuses to overwrite a result it can already see', async () => {
        const db = await freshDb();
        const itemUuid = await withPendingTest(db, { result_value: '9.1' });

        await expect(new Workflows(clientFor(db)).reportResult(itemUuid, { result_value: '13.4' }))
            .rejects.toThrow(/already recorded/);

        // The server would refuse this too, and far more strictly — but a
        // technician should find out before they type a number, not after.
        expect(await db.outbox.count()).toBe(0);
    });

    it('refuses an empty result', async () => {
        const db = await freshDb();
        const itemUuid = await withPendingTest(db);

        await expect(new Workflows(clientFor(db)).reportResult(itemUuid, { result_value: '  ' }))
            .rejects.toThrow(/Enter the result/);
    });

    it('refuses a test this device has never seen', async () => {
        const db = await freshDb();

        await expect(new Workflows(clientFor(db)).reportResult(uuid(), { result_value: '13.4' }))
            .rejects.toThrow(/Sync and try again/);
    });

    it('lists what is waiting, oldest first, and drops what is done', async () => {
        const db = await freshDb();
        const older = await withPendingTest(db, { name: 'Older', created_at: '2026-09-01T08:00:00Z' });
        await withPendingTest(db, { name: 'Newer', created_at: '2026-09-02T08:00:00Z' });
        await withPendingTest(db, { name: 'Done', result_value: '7.7' });

        const worklist = await new Workflows(clientFor(db)).benchWorklist();

        expect(worklist.map((i) => i.name)).toEqual(['Older', 'Newer']);
        expect(worklist[0].uuid).toBe(older);
    });

    it('can say which results have not reached the server', async () => {
        const db = await freshDb();
        const itemUuid = await withPendingTest(db);
        const flows = new Workflows(clientFor(db));

        expect(await flows.reportedNotYetSent()).toHaveLength(0);

        await flows.reportResult(itemUuid, { result_value: '13.4' });

        expect(await flows.reportedNotYetSent()).toHaveLength(1);
    });
});

describe('reading the ward', () => {
    it('lists current inpatients with their patients attached, by bed', async () => {
        const db = await freshDb();
        await withAdmission(db, { bed_name: 'M-09' });
        await withAdmission(db, { bed_name: 'M-01' });
        await withAdmission(db, { bed_name: 'M-05', status: 'discharged' });

        const ward = await new Workflows(clientFor(db)).currentInpatients();

        expect(ward).toHaveLength(2);
        expect(ward.map((a) => a.bed_name)).toEqual(['M-01', 'M-09']);
        expect(ward[0].patient.first_name).toBe('Grace');
    });

    it('builds a chart, newest first', async () => {
        const db = await freshDb();
        const { admissionUuid } = await withAdmission(db);
        const flows = new Workflows(clientFor(db));

        await flows.recordVitals(admissionUuid, { pulse: '70' });
        await new Promise((r) => setTimeout(r, 5));
        await flows.recordVitals(admissionUuid, { pulse: '88' });
        await flows.recordNursingNote(admissionUuid, 'Settled.');

        const chart = await flows.chartFor(admissionUuid);

        expect(chart.vitals).toHaveLength(2);
        expect(chart.vitals[0].pulse).toBe(88);
        expect(chart.notes).toHaveLength(1);
    });

    it('can say exactly what has not reached the server', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        await flows.registerPatient({ first_name: 'Amina', last_name: 'Nakato' });
        const { admissionUuid } = await withAdmission(db);
        await flows.recordVitals(admissionUuid, { pulse: '70' });

        const unsent = await flows.unsent();

        expect(unsent).toHaveLength(2);
        expect(unsent.map((u) => u.entity).sort()).toEqual(['patients', 'vitals']);
        // What it was, not what was in it.
        expect(JSON.stringify(unsent)).not.toContain('Amina');
    });
});

describe('writing a clinical note', () => {
    async function withVisit(db, overrides = {}) {
        const patientUuid = uuid();
        const visitUuid = uuid();

        await db.patients.add({ uuid: patientUuid, first_name: 'Grace', last_name: 'Auma', deleted_at: null });
        await db.visits.add({
            uuid: visitUuid,
            patient_uuid: patientUuid,
            stage: 'ongoing',
            version: 3,
            _server_version: 3,
            _server_snapshot: { complaints: 'Headache for three days.', diagnosis: null, doctor_remarks: null },
            complaints: 'Headache for three days.',
            diagnosis: null,
            doctor_remarks: null,
            deleted_at: null,
            ...overrides,
        });

        return { patientUuid, visitUuid };
    }

    it('queues an update carrying only what changed', async () => {
        const db = await freshDb();
        const { visitUuid } = await withVisit(db);
        const flows = new Workflows(clientFor(db));

        const { changed } = await flows.writeClinicalNote(visitUuid, {
            // Unchanged — it must NOT be sent, or the device claims to have
            // written a sentence it merely displayed, and that becomes a
            // candidate for a conflict with whoever actually wrote it.
            complaints: 'Headache for three days.',
            diagnosis: 'Tension headache.',
        });

        expect(changed).toBe(true);

        const [op] = await db.outbox.toArray();
        expect(op.entity).toBe('visits');
        expect(op.operation).toBe('update');
        expect(op.payload.diagnosis).toBe('Tension headache.');

        // The payload is the whole record — that is the protocol, so that a
        // delta computed against a base the server has moved past is never
        // unapplicable hours later. What says which fields this device CLAIMS
        // to have written is `base_fields`, and it is that set the server
        // checks for contest. An echoed sentence must not be in it.
        expect(Object.keys(op.base_fields)).toEqual(['diagnosis']);

        // And the version it was written against, so the server has a third
        // side to merge with rather than having to assume the worst.
        expect(op.base_version).toBe(3);
    });

    it('queues nothing at all when nothing moved', async () => {
        const db = await freshDb();
        const { visitUuid } = await withVisit(db);
        const flows = new Workflows(clientFor(db));

        const { changed } = await flows.writeClinicalNote(visitUuid, {
            complaints: 'Headache for three days.',
        });

        expect(changed).toBe(false);
        expect(await db.outbox.count()).toBe(0);
    });

    it('refuses a visit that has moved on to billing', async () => {
        // The same gate the server applies, checked here so a doctor is told
        // at the bedside instead of six hours later.
        const db = await freshDb();
        const { visitUuid } = await withVisit(db, { stage: 'billing' });
        const flows = new Workflows(clientFor(db));

        await expect(flows.writeClinicalNote(visitUuid, { diagnosis: 'Malaria.' }))
            .rejects.toBeInstanceOf(ValidationError);
        expect(await db.outbox.count()).toBe(0);
    });

    it('refuses a visit this device has never seen', async () => {
        const db = await freshDb();
        const flows = new Workflows(clientFor(db));

        await expect(flows.writeClinicalNote(uuid(), { diagnosis: 'Malaria.' }))
            .rejects.toBeInstanceOf(ValidationError);
    });

    it('treats blanking a field as a change, not as nothing', async () => {
        // Deleting a wrong diagnosis is a real edit and must reach the server.
        const db = await freshDb();
        const { visitUuid } = await withVisit(db, { diagnosis: 'Malaria.' });
        const flows = new Workflows(clientFor(db));

        const { changed } = await flows.writeClinicalNote(visitUuid, { diagnosis: '  ' });

        expect(changed).toBe(true);
        expect((await db.outbox.toArray())[0].payload.diagnosis).toBeNull();
    });

    it('lists only the visits a note can still be written on', async () => {
        const db = await freshDb();
        await withVisit(db);
        await withVisit(db, { stage: 'completed' });
        await withVisit(db, { deleted_at: '2026-09-18T10:00:00Z' });

        const open = await new Workflows(clientFor(db)).openVisits();

        expect(open).toHaveLength(1);
        expect(open[0].stage).toBe('ongoing');
        expect(open[0].patient.first_name).toBe('Grace');
    });
});
