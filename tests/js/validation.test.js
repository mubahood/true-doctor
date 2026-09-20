import { describe, it, expect } from 'vitest';
import { validate, splitList, PATIENT_RULES, VITALS_RULES } from '../../resources/js/offline/validation.js';

/**
 * The offline rules against the server's.
 *
 * Two complaints drove this. A date of birth of 20/09/2026 — today, in the
 * future for a birth — was accepted here and would have been refused on sync
 * hours later. And the messages looked nothing like the panel's, so the same
 * mistake read differently depending on which screen you were on.
 *
 * `validation.js` is a transcription of `PatientRequest`, so the tests below
 * are mostly "does it say the same thing the server would".
 */

/**
 * A LOCAL date string, the way a `<input type="date">` produces one.
 *
 * `new Date(Date.now() + 86400000).toISOString()` is not tomorrow: the ISO
 * string is UTC, so on a machine ahead of UTC — this one is +3 — adding a day
 * at ten to one in the morning still lands on today's local date. The rule
 * being tested compares against the end of the LOCAL day, so the fixture has
 * to speak the same calendar.
 */
function localDate(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

describe('a patient, against PatientRequest', () => {
    const ok = { first_name: 'Muhindo', last_name: 'Mubaraka' };

    it('accepts the two fields the server requires and nothing else', () => {
        const { errors } = validate(ok, PATIENT_RULES);

        expect(errors).toEqual({});
    });

    it('names the missing field the way the form labels it', () => {
        const { errors } = validate({}, PATIENT_RULES);

        expect(errors.first_name).toBe('First name is required.');
        expect(errors.last_name).toBe('Last name is required.');
        // And nothing else: every other rule passes on an absent value, the
        // way Laravel's do. Reporting "Phone may not be longer than 32" for
        // an empty box is how a form becomes untrustworthy.
        expect(Object.keys(errors).sort()).toEqual(['first_name', 'last_name']);
    });

    it('refuses a date of birth in the future', () => {
        // The one from the screenshot.
        const tomorrow = localDate(1);
        const { errors } = validate({ ...ok, dob: tomorrow }, PATIENT_RULES);

        expect(errors.dob).toBe('Date of birth cannot be in the future.');
    });

    it('accepts a birthday recorded today', () => {
        // `before_or_equal:today` means the whole day, not the instant. A
        // baby born this morning is registered this afternoon.
        const today = localDate(0);

        expect(validate({ ...ok, dob: today }, PATIENT_RULES).errors.dob).toBeUndefined();
    });

    it('accepts a date of birth in the past', () => {
        expect(validate({ ...ok, dob: '1991-05-05' }, PATIENT_RULES).errors.dob).toBeUndefined();
    });

    it('refuses something that is not a date at all', () => {
        expect(validate({ ...ok, dob: 'yesterday-ish' }, PATIENT_RULES).errors.dob).toContain('not a date');
    });

    it('enforces the same lengths the server does', () => {
        const { errors } = validate({ ...ok, first_name: 'x'.repeat(101) }, PATIENT_RULES);

        expect(errors.first_name).toBe('First name may not be longer than 100 characters.');
    });

    it('enforces the same enumerations the server does', () => {
        expect(validate({ ...ok, sex: 'male' }, PATIENT_RULES).errors.sex).toBeUndefined();
        expect(validate({ ...ok, sex: 'other' }, PATIENT_RULES).errors.sex).toBeUndefined();
        expect(validate({ ...ok, sex: 'Male' }, PATIENT_RULES).errors.sex).toBeTruthy();

        expect(validate({ ...ok, blood_type: 'O+' }, PATIENT_RULES).errors.blood_type).toBeUndefined();
        expect(validate({ ...ok, blood_type: 'Z+' }, PATIENT_RULES).errors.blood_type).toBeTruthy();
    });

    it('checks an email the way the server does', () => {
        expect(validate({ ...ok, email: 'a@b.co' }, PATIENT_RULES).errors.email).toBeUndefined();
        expect(validate({ ...ok, email: 'not-an-email' }, PATIENT_RULES).errors.email).toContain('valid email');
    });

    it('trims, and treats whitespace as absent', () => {
        const { errors, values } = validate({ first_name: '  Amina  ', last_name: '   ' }, PATIENT_RULES);

        expect(values.first_name).toBe('Amina');
        expect(errors.last_name).toBe('Last name is required.');
    });

    it('returns null rather than an empty string for a blank optional field', () => {
        // `''` in a column that means "unknown" is a different fact from
        // `null`, and it is the one nobody intended.
        const { values } = validate({ ...ok, phone_1: '', address: '  ' }, PATIENT_RULES);

        expect(values.phone_1).toBeNull();
        expect(values.address).toBeNull();
    });

    it('covers every field the server validates that a device can send', () => {
        // A guard against the two drifting. If a rule is added to
        // `PatientRequest` for a field Field Mode offers, it belongs here too.
        for (const field of ['first_name', 'last_name', 'dob', 'sex', 'phone_1', 'phone_2',
            'email', 'address', 'home_address', 'blood_type', 'allergies', 'chronic_conditions',
            'spouse_name', 'father_name', 'mother_name', 'emergency_contact_name',
            'emergency_contact_phone', 'notes']) {
            expect(PATIENT_RULES, `PATIENT_RULES is missing ${field}`).toHaveProperty(field);
        }
    });
});

describe('vitals', () => {
    it('refuses a reading no human body produces', () => {
        expect(validate({ pulse: '9000' }, VITALS_RULES).errors.pulse)
            .toBe('Pulse should be between 20 and 250.');
    });

    it('accepts a reading at the edge of the range', () => {
        expect(validate({ pulse: '20' }, VITALS_RULES).errors.pulse).toBeUndefined();
        expect(validate({ pulse: '250' }, VITALS_RULES).errors.pulse).toBeUndefined();
    });

    it('says a number is not a number before complaining about its range', () => {
        expect(validate({ temperature: 'warm' }, VITALS_RULES).errors.temperature)
            .toBe('Temperature must be a number.');
    });

    it('lets every reading be left blank — a round is not a form', () => {
        expect(validate({}, VITALS_RULES).errors).toEqual({});
    });
});

describe('a comma-separated list, as the server splits it', () => {
    it('splits and trims', () => {
        expect(splitList('Penicillin, Aspirin')).toEqual(['Penicillin', 'Aspirin']);
    });

    it('is null when there is nothing in it', () => {
        expect(splitList('')).toBeNull();
        expect(splitList('  ,  ,')).toBeNull();
        expect(splitList(null)).toBeNull();
    });

    it('leaves an array alone', () => {
        expect(splitList(['Penicillin'])).toEqual(['Penicillin']);
    });
});
