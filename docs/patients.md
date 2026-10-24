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

## Age or date of birth

The registration form (web and API) and the new-patient part of the visit form
ask for the **age** by default: `age_years`, and `age_months` for a baby under
two (`App\Support\BirthDate`). `PatientService::birth()` turns it into a date:
1 July of the year that makes the age right today, or exactly that many years
and months back when months are given, and sets `patients.dob_estimated`. "Enter
the date of birth instead" switches the form to the date, which is exact. An
edit that sends the same age keeps the stored date, so saving a phone number
never moves an estimated birthday. Everything that shows an age reads `dob` as
before. The import's age column also sets the flag. The app asks for the age the
same way and works the date out on the device by the same rule (it registers
offline), sending it through sync with `dob_estimated: true`; a record sent back
with its date unchanged and nothing said of the estimate keeps its flag, so an
older app correcting a phone number never makes an estimated birthday known.

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

## The patient record

`admin/patients/{patient}` is the one page that shows EVERYTHING about a person.
Code: `app/Livewire/Patients/Show.php` (the shell), `app/Livewire/Patients/Tabs/*`
(one lazy component per tab), `app/Services/PatientSummaryService.php` (the facts),
`resources/views/livewire/patients/{show,tabs/*}.blade.php`.

### One service owns the facts

`PatientSummaryService` is read by the page AND by `GET /api/v1/patients/{uuid}/summary`
([api/patients.md](api/patients.md)), so the web and the app cannot disagree.
Aggregate queries only (no N+1), tenant scoping from the global scope, money as
scale-2 decimal strings formatted in the views by `HospitalSettings`.

- `figures()` - outstanding, billed, paid, card balance, visits and last visit,
  open visit, next appointment, admitted now.
- `alerts()` - allergies, chronic conditions, owes (with the age of the oldest
  unpaid invoice), no consent, inactive/deceased, admitted now, insurance and
  claims awaiting. Only what applies.
- `recent()` / `activity()` - bounded recent lists, and the timeline merging them
  newest first. `visitsPage()`, `invoicesPage()`, `paymentsPage()`,
  `appointmentsPage()` feed the paginated tabs.
- `aging()` - unpaid invoices in 0-30 / 31-60 / 61-90 / 90+ day bands.
  "Outstanding" is `Invoice::outstanding()` (issued or part-paid with a balance
  left), the same scope `ReportService::outstanding()` uses, and age is counted
  from when the invoice was raised, as the reports do. Only the band edges differ
  (the report's first band is 7 days, which suits a hospital; 30 suits one account).
  A void invoice is neither billed nor owed.
- `statement()` - what the printed statement carries.

### Sections: who sees what

`PatientSummaryService::sectionsFor($user)` turns permissions and the practice's
modules into sections; everything else keys off it.

| Section | Needs | Gives |
|---|---|---|
| visits | `visits.view` | Visits tab, visit count, open visit |
| clinical | `visits.view` | Clinical tab: diagnoses, medication, prescriptions, vitals |
| lab / imaging | `visits.view` + module laboratory / radiology | lab results with flags, imaging reports |
| money | `billing.view` | Billing tab, outstanding/billed/paid, the "owes" alert, Statement |
| appointments | `appointments.view` | Appointments tab, next appointment |
| admissions | `ipd.view` + module inpatient | admissions, "currently admitted" |
| cards | `patients.card.manage` + module cards | card balance, card ledger |
| insurance | `insurance.view` + module insurance | claims awaiting |

A section a person may not see is OMITTED (never empty - an empty money block
reads as "owes nothing"). Every tab also re-checks its own section, because a
Livewire component can be addressed without its button.

### The page

Header (name, number, age/sex, status; New visit or Open visit, Book appointment,
Statement, ID card, Edit, Archive), alert strip, figures row, then tabs. The tab is
in the URL (`?tab=billing`); a tab is mounted the first time it is opened and kept
(hidden) afterwards; a tab you may not have lands on Overview.

| Tab | Holds |
|---|---|
| Overview | activity timeline + the latest few of each kind |
| Visits | every visit: serial, visit no, date, stage, doctor, diagnosis, billed, balance (paginated) |
| Billing | invoices (PDF), payments (receipt PDF), debts: aged bands, unpaid list, claims awaiting, card ledger |
| Clinical | diagnoses, current medication, prescriptions, vitals, lab results with abnormal flags, imaging reports, admissions |
| Appointments | upcoming and past |
| Records | cards, documents, dependents, insurances, treatments - the working panels |
| Details | identity, contact, medical, family and emergency, insurance on file, consent, notes |

"New visit" and "Book appointment" link to the visit / appointment lists with
`?for={patient uuid}`, which opens the usual dialog with the patient chosen.

### Statement of account

`GET admin/patients/{patient}/statement` (`admin.patients.statement`, needs
`billing.view`) prints invoices, payments, totals, the balance due and the aged
bands on the letterhead (`resources/views/pdf/patient-statement.blade.php`). It is
held to the every-document rules in `tests/Feature/GeneratedDocumentTest.php`.

Tests: `PatientSummaryServiceTest`, `Livewire/PatientRecordTabsTest`,
`PatientStatementTest`, `Api/PatientSummaryApiTest`.
