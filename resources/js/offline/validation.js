/**
 * The same rules the server enforces, written the same way.
 *
 * Field Mode used to carry its own hand-rolled checks — "a first name is
 * needed" and little else — which meant two things went wrong at once. A date
 * of birth in the future was accepted here and rejected on sync, hours later,
 * by which point nobody remembers typing it. And the messages were nothing
 * like the panel's, so the same mistake read differently depending on which
 * screen you happened to be on.
 *
 * So the rule sets below are transcriptions of the `FormRequest` classes, rule
 * for rule and name for name, so the two can be compared by eye. This is NOT
 * a security boundary and never was — the server re-validates every operation
 * with the real thing. It is here so somebody finds out at the bedside instead
 * of six hours later in a list of rejections.
 *
 * Mirrors: `app/Http/Requests/PatientRequest.php`,
 *          `app/Http/Requests/VisitClinicalRequest.php`.
 */

/** @see app/Http/Requests/PatientRequest.php */
export const PATIENT_RULES = {
    first_name: ['required', 'max:100'],
    last_name: ['required', 'max:100'],
    dob: ['date', 'before_or_equal:today'],
    sex: ['in:male,female,other'],
    phone_1: ['max:32'],
    phone_2: ['max:32'],
    email: ['email', 'max:150'],
    address: ['max:191'],
    home_address: ['max:191'],
    blood_type: ['in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
    allergies: ['max:500'],
    chronic_conditions: ['max:500'],
    spouse_name: ['max:150'],
    father_name: ['max:150'],
    mother_name: ['max:150'],
    emergency_contact_name: ['max:150'],
    emergency_contact_phone: ['max:32'],
    notes: ['max:2000'],
};

/** @see app/Http/Requests/VisitClinicalRequest.php */
export const CLINICAL_RULES = {
    complaints: ['max:2000'],
    diagnosis: ['max:2000'],
    doctor_remarks: ['max:2000'],
};

/** @see App\Services\Sync\Handlers\LabResultHandler */
export const LAB_RESULT_RULES = {
    result_value: ['required', 'max:191'],
    result_flag: ['max:12'],
    result_notes: ['max:191'],
};

/**
 * A vitals round, with the ranges a human body occupies.
 *
 * Not in the server's FormRequest — it is the sort of thing that only ever
 * gets typed at a bedside, and a pulse of 9,000 caught here saves a rejected
 * operation nobody will understand tomorrow.
 */
export const VITALS_RULES = {
    temperature: ['numeric', 'between:25,45'],
    pulse: ['numeric', 'between:20,250'],
    respiratory_rate: ['numeric', 'between:5,80'],
    spo2: ['numeric', 'between:30,100'],
    blood_pressure: ['max:20'],
    note: ['max:2000'],
};

/**
 * Check `values` against `rules`.
 *
 * Returns Laravel's shape — one message per field, keyed by field name — so
 * the offline forms can put an error under the control it belongs to exactly
 * as the online ones do, instead of stacking red banners at the top of the
 * page where they are read as a list of unrelated complaints.
 *
 * @returns {{errors: Record<string,string>, values: Record<string,*>}}
 */
export function validate(values, rules) {
    const errors = {};
    const clean = {};

    for (const [field, checks] of Object.entries(rules)) {
        const raw = values[field];
        const value = typeof raw === 'string' ? raw.trim() : raw;
        const empty = value === undefined || value === null || value === '';

        clean[field] = empty ? null : value;

        if (empty) {
            if (checks.includes('required')) {
                errors[field] = `${label(field)} is required.`;
            }

            // Every other rule in Laravel passes on an absent value. Checking
            // `max` against nothing is how a blank optional field comes to be
            // reported as too long.
            continue;
        }

        const failure = checks.map((check) => apply(check, field, value)).find(Boolean);

        if (failure) {
            errors[field] = failure;
        }
    }

    return { errors, values: clean };
}

function apply(check, field, value) {
    const [rule, argument] = check.split(':');

    switch (rule) {
        case 'required':
            return null;

        case 'max':
            return String(value).length > Number(argument)
                ? `${label(field)} may not be longer than ${argument} characters.`
                : null;

        case 'in':
            return argument.split(',').includes(String(value))
                ? null
                : `${label(field)} is not one of the options.`;

        case 'email':
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value))
                ? null
                : `${label(field)} must be a valid email address.`;

        case 'numeric':
            return Number.isNaN(Number(value)) ? `${label(field)} must be a number.` : null;

        case 'between': {
            const [min, max] = argument.split(',').map(Number);
            const number = Number(value);

            if (Number.isNaN(number)) {
                return null; // `numeric` has already said so.
            }

            return number < min || number > max
                ? `${label(field)} should be between ${min} and ${max}.`
                : null;
        }

        case 'date':
            return Number.isNaN(Date.parse(String(value))) ? `${label(field)} is not a date.` : null;

        case 'before_or_equal': {
            if (argument !== 'today') {
                return null;
            }

            // The end of today in local time: a date-only input means the
            // whole day, and comparing against `now` would reject a birthday
            // recorded this afternoon.
            const endOfToday = new Date();
            endOfToday.setHours(23, 59, 59, 999);

            return Date.parse(String(value)) > endOfToday.getTime()
                ? `${label(field)} cannot be in the future.`
                : null;
        }

        default:
            return null;
    }
}

/**
 * The words the form uses, so a message names the field somebody is looking
 * at rather than its column.
 */
const LABELS = {
    first_name: 'First name',
    last_name: 'Last name',
    dob: 'Date of birth',
    sex: 'Sex',
    phone_1: 'Phone',
    phone_2: 'Second phone',
    email: 'Email',
    address: 'Current address',
    home_address: 'Home address',
    blood_type: 'Blood type',
    allergies: 'Allergies',
    chronic_conditions: 'Chronic conditions',
    spouse_name: 'Spouse name',
    father_name: "Father's name",
    mother_name: "Mother's name",
    emergency_contact_name: 'Emergency contact',
    emergency_contact_phone: 'Emergency phone',
    notes: 'Notes',
    complaints: 'Complaints',
    diagnosis: 'Diagnosis',
    doctor_remarks: 'Remarks',
    result_value: 'Result',
    result_flag: 'Flag',
    result_notes: 'Notes',
    temperature: 'Temperature',
    pulse: 'Pulse',
    respiratory_rate: 'Breathing rate',
    spo2: 'Oxygen',
    blood_pressure: 'Blood pressure',
    note: 'Note',
    drug_name: 'Drug',
    dose: 'Dose',
    route: 'Route',
    status: 'Whether it was given',
};

export function label(field) {
    return LABELS[field] ?? field.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

/** A comma-separated field as the server's `prepareForValidation` splits it. */
export function splitList(value) {
    if (Array.isArray(value)) {
        return value.length ? value : null;
    }

    if (typeof value !== 'string' || !value.trim()) {
        return null;
    }

    const items = value.split(',').map((s) => s.trim()).filter(Boolean);

    return items.length ? items : null;
}
