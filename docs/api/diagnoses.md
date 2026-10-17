# Coded diagnoses and the Ministry reports

Roadmap 04 §1 and §2. The written diagnosis on a visit stays as the clinician wrote it; a **coded** diagnosis (ICD-10 now, ICD-11 ready through `system`) is what the HMIS forms count. Rules live in `DiagnosisService`; the web panel, the API, the records officer's worklist and the offline outbox all call it.

## The record

- Append-only (`visit_diagnoses`). A change (primary, certainty) is a new row that `replaces_id` the old one; a removal is a new row with `removed`. What is in force is every row nothing later replaces and not removed.
- One primary per visit. The first diagnosis is the primary; choosing another primary makes the old one secondary; removing the primary promotes the next.
- Certainty: `confirmed` or `suspected`.

## API (all under `/api/v1`, authenticated)

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `diagnoses/search` | `?q=` plain words in English, Luganda or Swahili, or a code | up to 8: `code`, `title`, `system`. This practice's most used of the last six months first. Empty `q` gives the most used |
| GET | `visits/{uuid}/diagnoses` | | the diagnoses in force, primary first: `id`, `uuid`, `code`, `title`, `system`, `primary`, `certainty`, `note`, `recorded_at` |
| POST | `visits/{uuid}/diagnoses` | `code`, `primary?`, `certainty?`, `note?`, `uuid?` | 201: the row. 422 `code` for a code not in the list or already on the visit |
| PATCH | `visits/{uuid}/diagnoses/{id}` | `primary: true?`, `certainty?` | the replacing row |
| DELETE | `visits/{uuid}/diagnoses/{id}` | | 200 |

Reading needs `visits.view`; coding needs the visit policy's `diagnose` (doctors, clinical officers, the hospital admin).

The catalogue is reference data: section `diagnoses` in `GET bootstrap` (`id`, `code`, `title`, `synonyms`), global like `districts`, so a device can search it with no signal.

Offline: entity `visit_diagnoses` in the sync outbox, create only, with `uuid`, `visit_uuid`, `code`, `primary?`, `certainty?`, `note?`. The device's `uuid` becomes the row's, so a replay codes once; a code already on the visit is accepted as done. Changes and removals need a connection (`append_only`).

## The catalogue

`database/data/icd10_common.php`: about 330 codes seen in East African outpatients, each with plain-word synonyms in English, Luganda and Swahili. Loaded by the migration; refreshed by `php artisan diagnoses:catalogue`. A full WHO list: `php artisan diagnoses:catalogue --import=/path/to/icd10.csv` (`code,title[,synonym;synonym]`), which adds and updates and never deletes.

## The Ministry reports (web: Reports → Ministry reports)

`HmisReports` counts each visit by its primary coded diagnosis, mapped to the form's lines by `config/hmis.php` (prefix match, in order). Bands: 0-28 days, 29 days-4 years, 5-9, 10-19, 20 and over; by sex.

- **HMIS 105** (monthly, outpatients): new and re-attendances, the diagnosis lines, all other diagnoses.
- **HMIS 108** (monthly, inpatients): admissions, discharges, deaths, absconded, bed days, the diagnoses of those discharged.
- **HMIS 033b** (weekly, Monday to Sunday): cases and deaths of each notifiable condition.

Nothing is guessed: a visit with no coded diagnosis, no date of birth or no sex is listed under **Missing data** with a link to fix it. Each form exports as PDF and CSV (`/admin/reports/hmis/{105|108|033b}.{pdf|csv}?month=YYYY-MM&week=YYYY-MM-DD`).

A notifiable condition coded in the last seven days raises a red insight on the dashboard ("N notifiable cases this week") that opens the 033b form.

When the Ministry revises a form, edit `config/hmis.php` and its `version`; nothing else changes.
