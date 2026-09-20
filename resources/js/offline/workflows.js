import { uuid } from './ids.js';
import { validate, splitList, PATIENT_RULES, VITALS_RULES, LAB_RESULT_RULES, CLINICAL_RULES } from './validation.js';

/**
 * The capture workflows — what a clinician actually does in Field Mode.
 *
 * Each is a thin wrapper over the repository, and each exists to hold one
 * piece of knowledge the UI should not have to carry: which store a record
 * goes in, what the server calls the entity, what the parent is, and which
 * fields are required before it is worth queueing anything.
 *
 * Validation here is NOT a security boundary — the server re-validates every
 * operation against the same FormRequest rules the online forms use. It is
 * here so a nurse finds out about a missing field at the bedside rather than
 * six hours later in a rejected-operations list.
 */

export class Workflows {
    /** @param {import('./index.js').OfflineClient} client */
    constructor(client) {
        this.client = client;
    }

    get repo() {
        return this.client.repo;
    }

    // ── Patients ─────────────────────────────────────────────────────────

    /**
     * Register somebody who is standing in front of you.
     *
     * No patient number is minted here. The record shows as provisional until
     * the server issues one, because a number that later changes is worse than
     * no number at all — somebody will have written it on a specimen bottle
     * (invariant I-11).
     */
    async registerPatient(fields) {
        // The SAME rules the server runs, transcribed in validation.js so the
        // two can be compared by eye. A date of birth in the future used to
        // pass here and be refused on sync, hours later.
        const { errors, values } = validate(fields, PATIENT_RULES);

        if (Object.keys(errors).length) {
            throw new ValidationError(errors);
        }

        const { row } = await this.repo.create('patients', {
            ...values,
            allergies: splitList(values.allergies),
            chronic_conditions: splitList(values.chronic_conditions),
            status: 'active',
            patient_no: null,
            search_key: `${values.first_name} ${values.last_name}`.trim().toLowerCase(),
        });

        await this.client.publish();

        return row;
    }

    /**
     * Correct somebody's details.
     *
     * Only the fields that actually CHANGED are sent. A payload is the whole
     * record, so submitting every field would have the device claiming to have
     * edited values it merely displayed — and every one of those becomes a
     * candidate for a conflict.
     */
    async updatePatient(patientUuid, fields) {
        const existing = await this.repo.get('patients', patientUuid);

        if (!existing) {
            throw new ValidationError(['That patient is not on this device.']);
        }

        const changes = {};

        for (const [key, value] of Object.entries(fields)) {
            const next = typeof value === 'string' ? (value.trim() || null) : value;

            if (!same(existing[key], next)) {
                changes[key] = next;
            }
        }

        if (Object.keys(changes).length === 0) {
            return { row: existing, changed: false };
        }

        if (changes.first_name || changes.last_name) {
            changes.search_key = `${changes.first_name ?? existing.first_name} ${changes.last_name ?? existing.last_name}`
                .trim().toLowerCase();
        }

        const { row } = await this.repo.update('patients', patientUuid, changes);
        await this.client.publish();

        return { row, changed: true };
    }

    // ── Bedside records ──────────────────────────────────────────────────

    /**
     * A vitals round.
     *
     * Against an ADMISSION, not a visit: `vital_rounds.admission_id` is NOT
     * NULL and a round belongs to a stay. Outpatient triage vitals are columns
     * on the visit itself and are a different thing entirely.
     */
    async recordVitals(admissionUuid, readings) {
        await this.assertAdmission(admissionUuid);

        const { errors, values } = validate(readings, VITALS_RULES);

        if (Object.keys(errors).length) {
            throw new ValidationError(errors);
        }

        // A round with nothing in it is not a round. Queueing one would put an
        // empty record on a chart and a rejection in somebody's list. This one
        // belongs to the form rather than to any field, so it is a `problem`.
        if (Object.values(values).every((v) => v === null)) {
            throw new ValidationError(['Record at least one reading.']);
        }

        const { row } = await this.repo.create('vitals', {
            admission_uuid: admissionUuid,
            temperature: number(values.temperature),
            pulse: number(values.pulse),
            respiratory_rate: number(values.respiratory_rate),
            spo2: number(values.spo2),
            blood_pressure: values.blood_pressure,
            note: values.note,
        });

        await this.client.publish();

        return row;
    }

    async recordNursingNote(admissionUuid, note) {
        await this.assertAdmission(admissionUuid);

        if (!note?.trim()) {
            throw new ValidationError({ note: 'Write the note before saving it.' });
        }

        const { row } = await this.repo.create('nursing_notes', {
            admission_uuid: admissionUuid,
            note: note.trim(),
        });

        await this.client.publish();

        return row;
    }

    /**
     * A dose given, or deliberately not given.
     *
     * "Not given, patient refused" is as important a record as "given", and
     * far more important than a blank — so the status is explicit and there is
     * no default that could be accepted by accident.
     */
    async recordMedication(admissionUuid, fields) {
        await this.assertAdmission(admissionUuid);

        const { errors, values } = validate(fields, {
            drug_name: ['required', 'max:150'],
            dose: ['max:60'],
            route: ['max:40'],
            status: ['required', 'in:given,refused,held,missed'],
            note: ['max:2000'],
        });

        if (Object.keys(errors).length) {
            throw new ValidationError(errors);
        }

        const { row } = await this.repo.create('med_administrations', {
            admission_uuid: admissionUuid,
            ...values,
        });

        await this.client.publish();

        return row;
    }

    /**
     * The stay must be on this device, and must still be open.
     *
     * Checked locally so a nurse is told at the bedside rather than six hours
     * later. The server checks it again, because the device's copy may be
     * hours out of date and the patient may have gone home.
     */
    async assertAdmission(admissionUuid) {
        const admission = await this.repo.get('admissions', admissionUuid);

        if (!admission) {
            throw new ValidationError(['That admission is not on this device. Sync and try again.']);
        }

        if (admission.status && admission.status !== 'admitted') {
            throw new ValidationError(['That patient has been discharged. Nothing more can be recorded on this stay.']);
        }

        return admission;
    }

    // ── The bench ────────────────────────────────────────────────────────

    /**
     * Report a result.
     *
     * An UPDATE, never a create: the line exists because a doctor ordered the
     * test, and only the value in it comes from the bench.
     *
     * The device refuses to overwrite a result it can already see. The server
     * refuses too — and far more strictly, because its copy is current — but a
     * technician should find out before they type a number, not after.
     */
    async reportResult(itemUuid, fields) {
        const item = await this.repo.get('lab_items', itemUuid);

        if (!item) {
            throw new ValidationError(['That test is not on this device. Sync and try again.']);
        }

        if (!String(fields?.result_value ?? '').trim()) {
            throw new ValidationError(['Enter the result before saving it.']);
        }

        if (item.result_value !== null && item.result_value !== undefined && item.result_value !== '') {
            throw new ValidationError([
                'A result is already recorded for this test. Changing one has to be done on the bench '
                + 'worklist, where both can be seen.',
            ]);
        }

        const { row } = await this.repo.update('lab_items', itemUuid, {
            result_value: String(fields.result_value).trim(),
            result_flag: fields.result_flag || null,
            result_notes: fields.result_notes?.trim() || null,
        });

        await this.client.publish();

        return row;
    }

    /** Tests waiting for a number, oldest first — a bench works down a queue. */
    async benchWorklist() {
        const items = await this.repo.all('lab_items');

        return items
            .filter((i) => i.result_value === null || i.result_value === undefined || i.result_value === '')
            .sort((a, b) => (a.created_at ?? '').localeCompare(b.created_at ?? ''));
    }

    /** Reported on this device but not yet confirmed by the server. */
    async reportedNotYetSent() {
        const items = await this.repo.all('lab_items');

        return items.filter((i) => i._sync === 'pending' && i.result_value);
    }

    // ── The clinical narrative ───────────────────────────────────────────

    /**
     * What the doctor found, and what they think it is.
     *
     * An UPDATE of the visit, not a new record — the narrative lives on the
     * visit row. Only the fields that actually CHANGED are sent, because a
     * payload the server treats as the whole record would have this device
     * claiming to have written every sentence it merely displayed, and every
     * one of those becomes a candidate for a conflict.
     *
     * A visit is never opened here. It gets its number and its consultation
     * charge at reception; a device fills one in.
     */
    async writeClinicalNote(visitUuid, fields) {
        const visit = await this.repo.get('visits', visitUuid);

        if (!visit) {
            throw new ValidationError(['That visit is not on this device. Sync and try again.']);
        }

        // The same gate the server applies, checked here so a doctor finds out
        // at the bedside rather than six hours later. The server checks again,
        // because this copy may be hours old and the visit may have been
        // billed since.
        if (visit.stage && visit.stage !== 'ongoing') {
            throw new ValidationError([
                'That visit has moved on to billing. Its notes are amended in the panel, where the bill can be seen.',
            ]);
        }

        const changes = {};

        for (const key of ['complaints', 'diagnosis', 'doctor_remarks']) {
            if (!(key in fields)) {
                continue;
            }

            const next = typeof fields[key] === 'string' ? (fields[key].trim() || null) : fields[key];

            if (String(visit[key] ?? '') !== String(next ?? '')) {
                changes[key] = next;
            }
        }

        if (Object.keys(changes).length === 0) {
            return { row: visit, changed: false };
        }

        const { row } = await this.repo.update('visits', visitUuid, changes);
        await this.client.publish();

        return { row, changed: true };
    }

    /** Visits open on this device — the ones a note can still be written on. */
    async openVisits() {
        const visits = await this.repo.all('visits');
        const patients = new Map((await this.repo.all('patients')).map((p) => [p.uuid, p]));

        return visits
            .filter((v) => !v.deleted_at && (!v.stage || v.stage === 'ongoing'))
            .map((v) => ({ ...v, patient: patients.get(v.patient_uuid) ?? null }))
            .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''));
    }

    // ── Reading ──────────────────────────────────────────────────────────

    async currentInpatients() {
        const admissions = await this.repo.where('admissions', 'status', 'admitted');
        const patients = new Map((await this.repo.all('patients')).map((p) => [p.uuid, p]));

        return admissions
            .map((a) => ({ ...a, patient: patients.get(a.patient_uuid) ?? null }))
            .sort((a, b) => (a.bed_name ?? '').localeCompare(b.bed_name ?? ''));
    }

    async chartFor(admissionUuid) {
        const [vitals, notes, meds] = await Promise.all([
            this.repo.where('vitals', 'admission_uuid', admissionUuid),
            this.repo.where('nursing_notes', 'admission_uuid', admissionUuid),
            this.repo.where('med_administrations', 'admission_uuid', admissionUuid),
        ]);

        const byNewest = (a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '');

        return {
            vitals: vitals.sort(byNewest),
            notes: notes.sort(byNewest),
            medications: meds.sort(byNewest),
        };
    }

    /** Records this device has captured that the server has not confirmed. */
    async unsent() {
        const rows = await this.client.db.outbox
            .where('status')
            .anyOf(['pending', 'processing', 'failed', 'rejected', 'conflict'])
            .toArray();

        return rows.map((op) => ({
            operation_id: op.operation_id,
            entity: op.entity,
            entity_uuid: op.entity_uuid,
            status: op.status,
            retry_count: op.retry_count,
            last_error: op.last_error,
            at: op.created_at,
        }));
    }
}

/**
 * Something the person in front of the screen can fix.
 *
 * Carries BOTH shapes: `errors` keyed by field, which is how the panel shows
 * them and how the server returns them, and `problems` as a flat list for the
 * handful of complaints that belong to the form as a whole rather than to any
 * one control.
 */
export class ValidationError extends Error {
    constructor(problems, errors = {}) {
        const list = Array.isArray(problems) ? problems : [];
        const fields = Array.isArray(problems) ? errors : problems;
        const all = Array.isArray(problems) ? list : Object.values(fields);

        super(all[0] ?? 'That could not be saved as recorded.');

        this.name = 'ValidationError';
        this.errors = fields ?? {};
        this.problems = Array.isArray(problems) ? list : [];
    }
}

function same(a, b) {
    if (Array.isArray(a) || Array.isArray(b)) {
        return JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
    }

    return String(a ?? '') === String(b ?? '');
}

/** A validated numeric field as a number, or null. */
function number(value) {
    return value === null || value === undefined || value === '' ? null : Number(value);
}

export { uuid };
