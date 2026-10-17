# Dental API

The same rules as the web (`docs/dental.md`). All under `/api/v1`, authenticated.

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `patients/{uuid}/dental` | `?as_of=YYYY-MM-DD` optional | `enabled`, `chart` (per tooth: `tooth`, `finding`, `label`, `tone`, `mobility`, `surfaces` {letter: finding}, `notes`, `updated_at`), `plan` (items), `history` (newest 50), `next_recall_on`, `findings` (catalogue) |
| POST | `patients/{uuid}/dental/findings` | `findings[]`: `uuid?`, `tooth`, `finding`, `surfaces?`, `mobility?`, `notes?`; `visit_uuid?` | 201: `recorded` (uuids), `chart` |
| POST | `patients/{uuid}/dental/plan` | `service_id`, `tooth?`, `surfaces?`, `price?` (inside the range), `notes?`, `visit_uuid?` | 201: the item |
| POST | `dental/plan/{item_uuid}/status` | `status` (`accepted`, `in_progress`, `done`, `cancelled`), `visit_uuid?` | the item; `billed: true` once done. **Idempotency-Key** like every money route |
| PUT | `patients/{uuid}/recall` | `next_recall_on`: a date, `"default"` (six months) or null | `next_recall_on` |
| GET | `patients/{uuid}/dental/quote` | | the quote as a PDF (planned and accepted procedures with the total) |
| GET | `dental/report` | `?from=&to=` | by dentist, by procedure, by month, planned/accepted/done, revenue |

An item: `uuid`, `service_id`, `procedure`, `tooth`, `surfaces`, `price`, `price_label`, `status`, `status_label`, `tone`, `next_statuses`, `notes`, `done_at`, `done_by`, `billed`.

Offline: findings go through the sync outbox as entity `dental_chart_entries` (create only) with `uuid`, `patient_uuid`, `visit_uuid?`, `tooth`, `finding`, `surfaces?`, `mobility?`, `notes?`.

Errors: 422 with the field (`findings.0.tooth`, `price`, `visit` when no open visit to bill on, `status` for a move that is not allowed); 403 when the role cannot.

The patient summary (`GET patients/{uuid}/summary`) lists `dental` in `sections` when the record shows a Dental tab: a dental clinic, a hospital with an active dental department, or a patient already charted, for someone who may read the chart (`DentalService::onPatient`, the web uses the same rule).

## The case sheet

The dental case sheet of a visit (`docs/dental.md`, "The case sheet"). Reading needs `treatments.view` or `visits.diagnose`; writing needs `visits.diagnose` (the financial section also `billing.manage`); booking the review needs `appointments.manage`. Every write answers with the whole sheet, as `GET` does, so the app redraws from one shape. Writes take an `Idempotency-Key` like every API write.

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `visits/{uuid}/dental-case-sheet` | | the sheet (below) |
| PUT | `visits/{uuid}/dental-case-sheet/sections/{section}` | `data` (the section's fields), `base_version?`, `uuid?`, `seen?` (each list field as the form showed it: then only what was added and removed is applied) | the sheet. A visit section (`complaint`, `dental_history`, `extra_oral`, `intra_oral`, `treatment_notes`, `instructions`) or the patient's own (`medical`, `surgical`, `financial`). A `base_version` that is no longer current: 422 on `section` |
| POST | `visits/{uuid}/dental-case-sheet/sections/{section}/normal` | `base_version?` | the sheet; only `extra_oral`, `intra_oral` |
| POST | `visits/{uuid}/dental-case-sheet/history/{section}/confirm` | | the sheet; "Still correct" for `medical`, `surgical`, `financial` (once per visit). 422 on `section` while the medical history says pregnant or breastfeeding: that is asked again |
| POST | `visits/{uuid}/dental-case-sheet/diagnoses` | `condition` (a key of `diagnosis_list`) or `code` (ICD-10); `tooth?`, `surfaces?`, `suspected?` | 201: `diagnosis_uuid` and the sheet. Marks the chart. 422 on `tooth` when the condition needs one, on `code` when it is already on that tooth |
| DELETE | `visits/{uuid}/dental-case-sheet/diagnoses/{diagnosis_uuid}` | | the sheet |
| POST | `visits/{uuid}/dental-case-sheet/xrays` | multipart: `kind` (`periapical`, `bitewing`, `opg`, `occlusal`), `teeth[]` (none for `opg`), `finding`, `service_id?` (charged on the visit), `image?` (jpg, png, webp, pdf, up to 10 MB), `uuid?` | 201: `xray_uuid` and the sheet. 422 on `visit` when the visit is closed |
| GET | `dental/xrays/{xray_uuid}/image` | | the image, inline |
| POST | `visits/{uuid}/dental-case-sheet/review` | `date` (Y-m-d), `time` (HH:MM), `doctor_id?`, `reason?` | 201: `appointment_uuid` and the sheet. 422 on `review` when the slot is not free |
| GET | `visits/{uuid}/dental-case-sheet/instructions` | | the patient's instructions after treatment as a PDF |

The sheet: `visit_uuid`; `applies` (whether the visit shows the case sheet in place of the general note); `sections[]` (`key`, `label`, `group`, `scope` visit or patient, `has_normal`, `data`, `lines[]` {`label`, `value`} in words, `version`, `recorded_at`, `recorded_by`; patient sections add `confirmed` and `asked_this_visit`); `definitions[]` (each section's `fields[]` with `key`, `type`, `label`, `options`, `suggest`, `abnormal`, `women`, so the app draws the same form); `diagnosis_list[]` (`key`, `label`, `code`, `chart`, `tooth`: true needs a tooth, false is of the mouth, null either); `xray_kinds`; `financial` (null without `billing.view`: `outstanding`, `outstanding_label`, `oldest_unpaid_days`, `last_payments[]`, `usual_method`, `insurance[]`, `accepted_quote`); `chart` (as `GET patients/{uuid}/dental`); `xrays[]` (`uuid`, `kind`, `kind_label`, `teeth`, `finding`, `taken_at`, `recorded_by`, `billed`, `order_uuid`, `image` {`uuid`, `name`, `mime`, `url`} or null); `labs[]` (`name`, `result`, `unit`, `flag`, `status`); `diagnoses[]` (`id`, `uuid`, `code`, `title`, `label`, `tooth`, `surfaces`, `primary`, `suspected`); `done_today[]`; `plan[]` (still to do); `instructions[]` (`key`, `label`, `lines[]`); `suggested_instructions[]`; `review` (`days`, `on`, `why`, `procedure`) or null; `reviews_booked[]`; `next_recall_on`; `instructions_url`.

The patient's red flags also travel on every `alerts` block (`PatientAlerts`) as `medical_flags` (labels), beside `allergies`. There, HIV or hepatitis reads "Infection-control precautions".

Not offline yet: the case sheet is written online. Sections carry `uuid` and `base_version`, so an outbox can replay them safely when the app adds one.
