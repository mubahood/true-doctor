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
| GET | `diagnoses/worklist` | `?q=` a patient (name, number or phone, every word somewhere) or a visit number; `?page=`, `?per_page=` (20, at most 50) | Diagnoses to code, newest first. Each row: `uuid` (the visit), `visit_no`, `created_at`, `patient: {uuid, name, patient_no}`, `doctor`, `diagnosis` (the written words), `suggestions: [{code, title, system}]` (up to 3, `DiagnosisService::suggest`). `meta` is the usual page: `total`, `last_page`, `current_page`, `per_page` |
| POST | `diagnoses/worklist/{uuid}` | `code`, `certainty?` (`confirmed` by default), `uuid?` | 201 `Coded.`: the row, as the visit's. The first code, so the primary. 422 `code` for a code not in the list, a visit coded meanwhile ("This visit was coded meanwhile: I10 …", or "You have already coded this visit: …" when the code in force is the caller's own earlier press), or one with no written diagnosis; 422 `uuid` for a uuid already used on another visit |

Reading needs `visits.view`; coding needs the visit policy's `diagnose` (doctors, clinical officers, the hospital admin). The catalogue search is also open to whoever may code from the worklist.

### Diagnoses to code (the worklist)

The web's **Diagnoses to code** (`Diagnoses\Worklist`) and the app's are one list (`DiagnosisService::uncoded`) and one press (`DiagnosisService::codeFromWorklist`). Both need the gate `diagnoses.code`: the clinical record (`visits.clinical`) and either `visits.diagnose` or the records officer's role. It is not a permission string, so `meta.capabilities.permissions` does not carry it; the menu entry `admin.diagnoses.worklist` is sent only to those who pass it.

- A records officer codes here and nowhere else: `POST visits/{uuid}/diagnoses` and the offline outbox need `visits.diagnose`, which the role does not hold. So the worklist has its own write, gated like the web's button.
- Only a visit still on the list is coded from it. Two people working down the same list (or a phone showing a list read a while ago) would otherwise stack a second primary on a visit the other has just coded; the second press is refused with what is now in force, and the row leaves the list.
- A repeat of the same press is one code: the `Idempotency-Key` replays the answer, and a device `uuid` sent again with the same code (under a new key) returns the row already written; with another code it is refused like any visit coded meanwhile.
- Online only. The list itself can be read from the device's copy; coding from it needs a connection.

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
