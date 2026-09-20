# Patients (Phase 1, Step 7)

Patients are a **first-class tenant entity** (HMS_PLAN.md §4, constraint B6) — never a
flag on the staff/users table. Each patient belongs to exactly one hospital and is
identified by a generated, checksummed `patient_no` that is unique **per hospital**.

## Files

| Concern | File |
|---|---|
| Table | `database/migrations/2026_07_19_100000_create_patients_table.php` |
| Model | `app/Models/Patient.php` (`BelongsToHospital`, soft deletes, activity log) |
| Enums | `app/Enums/PatientSex.php`, `app/Enums/PatientStatus.php` |
| Registration & numbering | `app/Services/PatientService.php` |
| Write validation (DTO) | `app/Http/Requests/PatientRequest.php` |
| Authorization | `app/Policies/PatientPolicy.php` (RBAC `patients.*`) |
| Controller (thin) | `app/Http/Controllers/Admin/PatientController.php` |
| Views | `resources/views/admin/patients/{index,create,edit,show,_form}.blade.php` |
| Routes | `Route::resource('patients', …)` + nested cards/documents/dependents/id-card under `/admin` |
| **Prepaid cards** | `app/Models/PatientCard.php`, `app/Models/CardRecord.php` (ledger), `app/Services/CardService.php`, `app/Http/Controllers/Admin/PatientCardController.php` |
| **Card enums** | `app/Enums/CardStatus.php`, `app/Enums/CardEntryType.php` |
| **Documents** | `app/Models/PatientDocument.php`, `app/Services/DocumentService.php`, `app/Http/Controllers/Admin/PatientDocumentController.php` |
| **Dependents** | `app/Models/PatientDependent.php`, `app/Http/Controllers/Admin/PatientDependentController.php` |
| **QR / ID card** | `app/Services/QrService.php`, `PatientController@idCard`, `resources/views/pdf/patient-card.blade.php` |

## Patient number

Format `PT-<year>-<seq6><check>` (e.g. `PT-2026-000123K`). The sequence is per hospital
per year; the check character comes from `App\Support\VerificationCode` (unambiguous
alphabet, catches typos). Generation runs inside a DB transaction with a **locking read**
so concurrent registrations can't collide, and the composite unique index
`(hospital_id, patient_no)` is the final guard (constraint F). `patient_no` is immutable
after creation — `PatientService::update()` drops it from the payload.

## Tenancy

`Patient` uses the `BelongsToHospital` trait: a global scope filters every read to the
resolved hospital, and `hospital_id` is auto-filled on create from the request context.
Callers never set or filter `hospital_id` by hand (constraint B8). Proven by
`tests/Feature/PatientTenancyIsolationTest.php` — hospital A cannot list, view, or edit
hospital B's patients; a super-admin (no hospital) sees all.

## RBAC

Permissions `patients.view|create|update|delete` are seeded in `RbacSeeder` and assigned:
receptionists and records officers register; doctors and nurses view + update; pharmacy,
lab, radiology and accounts view; hospital admin and records officer can delete (archive).
Enforced by `PatientPolicy` in the controller (and, later, identically in the API — C13).

## Data & privacy

- `bank_details` is stored with an `encrypted` cast (C12) and hidden from array output.
- `allergies` / `chronic_conditions` are JSON arrays (entered comma-separated in the form,
  normalized to arrays at the request boundary — B10).
- Deletes are soft (records retained); every change is written to the activity log.

## Prepaid cards & ledger (money)

A patient may hold one or more `PatientCard`s — see [cards.md](cards.md) for the whole
subject: family holders, insurer-backed cards, and the hospital-wide Cards section. **All** balance movement goes
through `CardService`, never a direct model write:

- Each `credit`/`debit` runs inside a DB transaction with a `lockForUpdate()` read on the
  card row (constraint F — no lost updates on concurrent top-ups/charges).
- Money math is **bcmath at scale 2** — amounts stay strings end to end (FormRequest →
  service → column), never cast through a float.
- Every movement writes an immutable `card_records` row (append-only ledger:
  `UPDATED_AT = null`) carrying a `balance_after` snapshot — the ledger is the source of
  truth, the card's `balance` is a cache of the last snapshot.
- A debit that would take the balance below zero is rejected (`InsufficientFundsException`)
  unless the card has `accepts_credit` and the overdraft stays within `max_credit`.
- Debits are blocked on non-active (`status.canTransact()`) or expired cards.
- `card_number` is stored with an `encrypted` cast and never displayed (only `masked()`,
  e.g. `•••• 4821`). Lookups/uniqueness use `card_hash` = `hash_hmac('sha256', number,
  app.key)`, unique per hospital — so the plaintext number is never needed to find a card.

Math is proven by `tests/Feature/CardTransactionTest.php`; the HTTP flow and cross-tenant
rejection by `tests/Feature/PatientCardFlowTest.php`.

## Documents

Uploaded via `DocumentService` to the **private** `local` disk under
`patients/<hospital>/<patient>/…` — never a public path. Downloads stream through
`PatientDocumentController@download` behind the `view` Policy check, so tenancy and
capability gate every read (C12: PHI at rest). Soft-deleted; the file is removed on delete.
Isolation proven by `tests/Feature/PatientDocumentTest.php`.

## Dependents & guardians

`PatientDependent` links two patients of the **same** hospital (guardian → dependent). The
`exists` validation rule is manually scoped to the current hospital (Laravel's raw `exists`
query ignores the global scope), preventing a cross-tenant link and the existence leak it
would otherwise create. Self-links and duplicates are rejected. Unique index
`(hospital_id, patient_id, dependent_patient_id)`.

## Patient ID card (PDF)

`PatientController@idCard` renders an A7 landscape card via DomPDF
(`resources/views/pdf/patient-card.blade.php`) with a QR of the `patient_no` embedded as an
inline data-URI (`QrService`, endroid GD backend) — the PDF is fully self-contained.
