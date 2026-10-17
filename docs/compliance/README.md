# Data protection (Uganda Data Protection and Privacy Act 2019)

Roadmap 07 §4 (the Act's pack, patients' rights) and §5 (a hospital owns its data).

## Roles

- **Online service (SaaS):** the clinic is the controller; SOLAVIA GROUP LIMITED (True-Doctor) is the processor. The clinic accepts the data processing agreement in Settings, Data protection; every acceptance (version, date, who) is kept in `hospitals.settings.data_protection.dpa`, newest last. A new `compliance.dpa_version` asks every clinic to accept again.
- **Standalone:** the clinic is both. The agreement is not offered; every other document is.

## The documents

The markdown files in this folder are the single source. Each is filled in with the clinic's details and the figures in `config/compliance.php`, then printed as a plain PDF (`resources/views/pdf/compliance/document.blade.php`, black on white). Editing a file here edits the PDF; there is no second copy.

| File | PDF title | Edition |
|---|---|---|
| `data-processing-agreement.md` | Data processing agreement | Online only |
| `privacy-notice.md` | Privacy notice for patients | Both |
| `data-subject-rights.md` | Patients' rights procedure | Both |
| `retention-schedule.md` | Retention schedule (table from `compliance.retention`) | Both |
| `breach-runbook.md` | Data breach procedure | Both |
| `dpia.md` | Data protection impact assessment | Both |
| `pdpo-registration.md` | PDPO registration checklist | Both |

Placeholders: `{clinic}`, `{clinic_address}`, `{clinic_contact}`, `{processor}`, `{product}`, `{processor_email}`, `{pdpo_registration}`, `{dpa_version}`, `{response_days}`, `{export_hours}`, `{date}`, `{retention_table}` (`App\Support\Compliance\ComplianceDocuments::values`). Clinic-typed values are escaped so they cannot become markdown; raw HTML is stripped.

These are templates written against the Act and common practice. The section numbers cited (3, 7, 18, 23, 24, 29 of the Act; 32 of the Tax Procedures Code Act) and the 72-hour breach deadline were written without the gazetted text at hand: check them, and have a lawyer read the pack, before a client relies on it. The retention periods name their source.

Who may open them: `manage-settings` (hospital administrators). Route `admin.compliance.document` (`/admin/settings/data-protection/{document}.pdf`), inline.

## Patients' requests (Data requests)

`/admin/data-requests` (`App\Livewire\DataRequests\Index`, `manage-settings`). Rules in `App\Services\Compliance\DataRequestService`:

- **Logged** with who asked, the patient (optional: they may not be on the register), the kind (`access`, `correction`, `objection`, `deletion`), the date received and the date due (`received_on` + `compliance.response_days`, 30).
- **Closed once**, as answered or refused, with what was done (required). Locked row; a closed request cannot be closed again or reopened. Each step is in the activity log.
- Open requests sort first, soonest due at the top; past their date they show **Overdue**.
- An access request links to the patient's data: `admin.patients.personal-data` (`/admin/patients/{uuid}/personal-data.pdf|json`). `App\Services\Compliance\PersonalDataExport` finds every table with a `patient_id`, then every table hanging off one of those by `<table>_id` (results, invoice lines, nursing notes), drops internal ids and secrets, and names the staff who recorded each row. Every export is in the activity log.

## A hospital's full export

Settings, Data protection, **Prepare an export** (`manage-settings`). `App\Services\Compliance\HospitalExport`:

1. `request()` refuses while another export of the same hospital is queued or building, creates a `data_exports` row and dispatches `App\Jobs\BuildHospitalExport` on connection `data-exports`, queue `exports` (lease 30 minutes).
2. The scheduler runs its own short-lived worker every minute (`routes/console.php`, `data-export-worker`): no daemon, no `exec` of our own, works on a standalone copy with only a cron.
3. `build()` writes, for every table with a `hospital_id` (found from the catalogue, minus the sync machinery, gateway payloads, counters and the exports themselves), one CSV of this hospital's rows, streamed 500 at a time; `hospital.csv` with the settings minus anything that looks like a key; `_columns.csv` (every column, type, nullable, notes); `_files.csv` and `files/<disk>/<path>` for every uploaded file (patient documents, order attachments, treatment photos, patient photos, staff avatars, the logo); and `README.txt`. Passwords, two-step secrets, card lookup hashes and link nonces are left out; card numbers and bank details are decrypted.
   The build claims its row with one conditional update (`queued` to `building`), so a second worker builds nothing; the ZIP is written out every 500 files so a shared host's open-file limit is not reached; a table without an `id` is paged ordered by every column. Only a user of the hospital itself may ask (a platform account switched into a hospital is refused, as the link would never open for it).
4. The ZIP lives on the private disk (`storage/app/private/exports/<hospital>/<uuid>.zip`) for `compliance.export_hours` (48), then the hourly prune deletes it and marks the row expired. A build stuck for 3 hours is marked failed, and files no ready export points at (a worker killed mid-build, a purged hospital's exports) are deleted once 3 hours old.
5. The download link is an encrypted token (export uuid + expiry) at `admin.data-exports.download`. It works only for a signed-in `manage-settings` user of the same hospital, before expiry; anything else is a 404. Every download increments `download_count` and is in the activity log.

## Tests

`tests/Feature/DataProtectionTest.php`; the PDFs are also in `tests/Feature/GeneratedDocumentTest.php`. Demo data: `database/seeders/DemoScenarios/Compliance.php`.

## Not done here

The monthly anonymising job (07 §4 Retention), consent records per purpose (05 §2, built with messaging), FHIR bundles (04 §9), the processing agreement at sign-up, linking the privacy notice from every patient message, and the purge's final notice (07 §5 Leaving).
