# Audit log and encrypted fields

Roadmap 07 §3, "Records that cannot be changed quietly". Three parts: a write audit on every clinical and money record, a read audit of who opened a patient's record, and encryption at rest for the fields that would hurt a patient if they leaked.

## Write audit

- **What is audited:** the models listed in `App\Services\Audit\AuditTrail::MODELS`: patients, visits, coded diagnoses, orders and their items, lab orders and results, radiology orders and reports, prescriptions and their items, dispensations, doses given, admissions, bed transfers, beds, nursing notes, vitals rounds, treatment records, the dental chart and plan, invoices and their lines, payments, cards and card transactions, insurance claims, transactions and patient cover, stock movements and items, the price list, lab tests, radiology studies, and user accounts (a role is a column on the user, so a role change is a user change).
- **How:** `App\Observers\AuditObserver` is registered for each of them in `AppServiceProvider`, so a model cannot forget to be audited. Each create, change and delete (a restore is the change of `deleted_at` back to empty) writes one row in `activity_log` (spatie/laravel-activitylog's table) through `App\Models\AuditEntry`.
- **What a row holds:** the hospital (a platform account with no hospital is never filed under one), who (the signed-in user, on web or API; "System" for console, queue and seeders), when, the record (type and id), the event, and each changed field's value before and after (`properties.old`, `properties.attributes`), plus `via` (web, api, system) and the IP address.
- **Masked:** passwords, tokens, two-step secrets, and every column with an `encrypted` cast are stored as `[hidden]`. The trail shows that a confidential note changed, never what it says.
- **Left out:** timestamps, the offline sync counters (`version`, `sync_revision`, `origin_*`), `last_active_at`. A save that changed only those writes nothing.
- **Values** are the stored ones, read straight off the model (no extra query); text is cut at 500 characters.
- **Append-only:** `AuditEntry` and `RecordAccess` throw on Eloquent update or delete. `activitylog:clean` is not scheduled. Purging a whole hospital (`hospitals:purge`) still removes its rows with the query builder.
- **Tenancy:** `AuditEntry` uses `BelongsToHospital`, and `config/activitylog.php` makes it the package's model, so the package's own `activity()` calls (device registered, revoked) are scoped too.

Before this change only five fields of Patient and Visit were logged (with `LogsActivity`); those two models now go through the observer like the rest, under the same log names (`patient`, `visit`). The migration gives old patient rows their hospital.

## Read audit

- `record_accesses`: hospital, user, patient, action (`opened`, `printed`), channel (`web`, `api`), what was printed, IP, device (the app's version header, or the browser and system from the user agent), time.
- Written by `App\Services\Audit\RecordAccessLog` from: the patient page (`Patients\Show::mount`), `GET /api/v1/patients/{uuid}`, `GET /api/v1/patients/{uuid}/summary`, the statement of account (web and API), the visit report PDF, and a clinician reading a visit's confidential note (context "Confidential note V-…").
- **Deduplicated:** the same person doing the same thing (same document) to the same record within 10 minutes is one entry.
- Refused requests (another hospital's patient, no permission) write nothing: the entry is written after authorisation.

The demo reset (`demo:reset`) clears the demo hospital's `activity_log` and `record_accesses` with the rest of its records.

## Screens

- **Administration → Hospital → Audit log** (`/admin/audit-log`, `manage-settings`): two tabs. *Changes* filters by record type, event, person, dates and record number; a row opens the change field by field. *Records opened* filters by patient, action, person and dates.
- **Patient record → Who opened it** (`manage-settings` only): the opens and prints of that patient, with a link to the full log.
- API: `docs/api/audit.md`.

## Encrypted fields

- `visits.sensitive_notes` is new: the visit's **confidential note** (HIV status, mental health, sexual and reproductive health). Written in the clinical notes dialog by anyone who may diagnose; shown only to them. Others see "A confidential note is on this visit." Each read is in the read audit. The API never returns it (the app keeps visit replies in an unencrypted local cache); `has_sensitive_notes` says there is one, and `POST visits/{uuid}/clinical` can still write it. It is hidden from `toArray()`, never offered as a phrase, not in the visit report PDF and not sent to offline devices.
- `patients.notes` is now encrypted: the free text where a standing fact like HIV status gets written. It is never searched. Unlike the visit note it is still shown to everyone who may see the patient and sent to offline devices, as before: it is protected at rest on the server, not from the clinic's own staff.
- Already encrypted before: patients' bank details, card numbers (with a hash for lookup), two-step secrets.
- **Not encrypted, on purpose:** names, phone numbers, the written diagnosis, complaints and doctor's remarks. They are searched, grouped (the phrase suggestions read the most-written remarks) or counted (Ministry reports). The roadmap's "blind index where search is needed" is therefore not needed yet: nothing encrypted is searched.
- The migration encrypts existing `patients.notes` in chunks of 500 and skips a value that already decrypts, so it is safe to run twice. Encryption uses `APP_KEY`: a server that loses its key loses these fields (as it already would bank details). `APP_PREVIOUS_KEYS` handles a key rotation.

## Not done here

Masking clinical text in the audit log for a custom role given `manage-settings` without clinical permissions (today only the administrator has it, and it has every clinical permission). Tamper evidence (each row carrying the previous row's hash, roadmap P2), break-glass access, and logging opens of a result link (there is no public result link yet).
