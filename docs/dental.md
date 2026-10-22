# Dental

The dental chart, the treatment plan, billing and the recall, for a hospital whose practice includes the dental pack (`Practice::of($hospital)->packKeys()` has `dental`). Rules live in `App\Services\DentalService`; the web's dental tab (`Patients\Tabs\Dental`, on the patient record and on a visit), the API (`docs/api/dental.md`) and the app call it.

## The chart

- **FDI notation.** Permanent teeth 11-18, 21-28, 31-38, 41-48; a child's teeth 51-55, 61-65, 71-75, 81-85 ("Child's teeth" switches the odontogram).
- **Surfaces:** M mesial, O occlusal, D distal, B buccal, L lingual or palatal, I incisal. Stored as letters in that order (`MO`, `MOD`).
- **Findings** (`App\Enums\DentalFinding`): sound, caries, filled, missing, to extract, crown, bridge, implant, root canal, fracture, mobility (graded 0-3), note. Missing, to extract, crown, bridge, implant, root canal, mobility and sound concern the whole tooth: their surfaces are ignored. Sound clears the tooth.
- **Append-only.** `dental_chart_entries` is never edited. The chart on a date is the latest entry per tooth and per surface up to that date, so "the chart as it was in March" is a query, not a backup. A correction is a new entry.
- **Offline.** The app records findings with no network through the sync outbox (`dental_chart_entries`, create only, `DentalChartHandler`); the device's uuid is the entry's uuid, so a replay never charts twice.

## The treatment plan

- Procedures come from the clinic's own price list (`services`), on a tooth and surfaces or on none. A range price is chosen inside its range (`Service::chargePrice`).
- **Statuses** (`DentalTreatmentStatus`): planned → accepted → in progress → done; cancelled from any open status. Done and cancelled are final.
- **Done means billed.** Marking done needs an open visit of the patient (the one the tab sits on, else the newest open one). The charge goes through `OrderService::forSubject` + `addServiceItem` under a row lock on the item, and the item keeps `order_item_id`: a repeat of "done" changes nothing, so a procedure is never billed twice and never forgotten. Treatment items do not sync offline: billing is the server's to judge.
- **Quote:** `GET /admin/patients/{patient}/dental-quote` is a PDF of what is still to do (planned, accepted, in progress) and its total.

## Simple notes

A dental visit is written one of two ways, chosen per hospital in Settings, Your practice, **Visit form** (`App\Support\Practice\VisitForm`, stored in `settings.clinical.dental_notes`): **simple notes** (a dental clinic's default) or the **detailed case sheet** below (every other practice's default). A visit is shown and printed the way it was written: notes saved keep it simple; any part of a case sheet on it (a section, an X-ray at the chair, a coded diagnosis, the patient's history asked on it) keeps it detailed, whatever the hospital chose since (`DentalNotesService::appliesTo`).

- **What it holds.** The presenting complaint (the visit's `complaints`), then History (`medical_history`, `family_history`, `dental_history`, `surgical_history`) and Oral examination (`hard_tissues`, `soft_tissues`, `xray`, `diagnosis`), all plain text up to 2,000 characters, no suggestions (`DentalNotesService::FIELDS`).
- **Storage.** `dental_notes`: append-only versions per visit, the eight notes encrypted at rest in `data` (a medical history can say HIV or pregnancy), written by `DentalNotesService::save` under a lock on the visit. A `base_version` that is no longer current is refused with who changed it and when; a save that changes nothing writes no version; a client `uuid` makes a resend return the first save. Audited (`AuditTrail::MODELS`, the text masked).
- **What the visit mirrors.** The Oral examination diagnosis is written into `visits.diagnosis` (and cleared there only if it was the notes' own), so coding, the worklists and the reports keep reading it. The complaint goes on the visit through `VisitService::updateClinical`, which in a practice that does not ask for a reason (a dental clinic) keeps `visits.reason` as the complaint's first words (`VisitForm::reasonFrom`: the first line or sentence, at most ten words and 60 characters) unless somebody wrote a reason or a booking carried one. The first clinician to save notes on a visit with no doctor becomes its doctor.
- **Carried forward.** Medical, family and surgical history are the patient's: a visit with no notes yet shows the latest saved on any other visit (`carriedForward`), marked as not saved here yet; the new visit form fills them in when the patient is chosen (and drops them on the New patient tab). A patient with no notes yet but a case-sheet medical or surgical history gets that history in words.
- **Where.** The new visit form (for `visits.diagnose`; the desk sees the presenting complaint only), the visit page (`Visits\Panels\DentalNotes` in `partials/dental-notes`, then the chart, treatment done today, the plan and the review), the quick view, the visit report (`pdf/partials/dental-notes`: complaint, History, vitals, Oral examination, chart, treatment done; empty headings dropped; a visit with only a reason prints it as the complaint), and the app (`docs/api/dental.md`, "Simple notes").

## Dosage and the patient summary

A dental clinic writes medicine as a **dosage** on the visit (medicine from stock, dose, how often, days, note; docs/pharmacy.md, "Dosage"), also optional on the new visit form after the Oral examination. Its **Visit report** prints the **patient summary** by default (treatment done with teeth, dosage, bill, payments, balance, next appointments; no notes or examination; docs/documents.md). The notes and case sheet print as above when the clinic chooses **Full clinical record**.

## The case sheet

A dental visit (a dental clinic, or a visit in a department whose name has the word dental, dentist, dentistry or orthodontics: `DentalCaseSheetService::appliesTo`) shows the **Dental consultation** in place of the general Clinical and Coded diagnoses panels. Its headings are the dental case sheet's: History (presenting complaint, dental, medical, surgical, financial), Examination (extra-oral, intra-oral soft tissues, investigations, the chart as hard tissues), Diagnosis, Treatment done today, Plan and follow-up. Web: `resources/views/livewire/visits/partials/dental-case-sheet.blade.php` and the `Visits\Panels\CaseSheetSection`, `DentalXrays`, `DentalDiagnoses`, `DentalFollowUp` panels; the chart and the plan are the dental tab's own (`part="chart"`, `part="plan"`), kept current by the `dental-changed` event.

- **The template.** `App\Support\CaseSheet\DentalCaseSheet` defines each section as fields (`chips`, `choice`, `text` with `suggest`, `textarea`, `list`), the dental diagnosis list with ICD-10 codes and chart marks, the X-ray kinds, the instruction templates and the review each procedure suggests. The same definition draws the web form, checks what the app sends (`CaseSheetService::clean`) and prints. A maternity or general case sheet would be another template on the same machinery.
- **Visit sections** (`case_sheet_entries`): append-only versions per visit and section, written by `CaseSheetService::save` under a lock on the visit. A save that names a `base_version` that is no longer current is refused with who changed it and when. A section with `normal` takes one tap (`markNormal`) and drops the fields that only describe what is abnormal. The complaint is mirrored into `visits.complaints`; the first clinician to write on a visit with no doctor becomes its doctor.
- **Patient sections** (`patient_history_entries`): medical, surgical and financial history belong to the patient. Shown from the latest version; **Still correct** (`confirmPatientHistory`) writes a `confirmed` version with the same data, once per visit. Allergies and conditions live on the patient (`allergies`, `chronic_conditions`); an empty box leaves them alone. The ticked red flags and pregnancy are kept on `patients.medical_flags` and shown on the alert bar (`App\Support\Clinical\MedicalFlags`, `PatientAlerts::for()['medical_flags']`). Pregnancy is asked only of a woman of 12 to 55 (or of unknown age).
- **Financial history** is billing's own figures (outstanding by the shared `Invoice::outstanding()`, last payments, the usual method, valid insurance, the accepted plan) plus one editable **Payment arrangement**. Only for `billing.view`; omitted from the API and the printed report otherwise.
- **Diagnosis per tooth.** `visit_diagnoses` has `tooth` and `surfaces`; the same code on two teeth is two diagnoses (`DiagnosisService::add` refuses the same code on the same tooth). `DentalCaseSheetService::addDiagnosis` codes it (the label is kept in `note`), records the chart finding (caries, to extract, fracture) or a note on the tooth, and mirrors the coded list into `visits.diagnosis`. WHO ICD-10 has no reversible or irreversible split, so both pulpitis entries are K04.0.
- **X-ray at the chair** (`dental_xrays`): kind, teeth, finding. `recordXray` places an imaging order on the visit with the X-ray as its subject, adds the price-list line (optional), writes the finding as the order's report, files the image as the order's attachment, and completes the order: billed, filed and printed like any other work, and it never holds the visit's gate open. Needs an open visit.
- **Treatment done today** is the plan items done on this visit (`done_visit_id`); anaesthetic and materials are the `treatment_notes` section.
- **Instructions** are the `instructions` section (template keys plus free text); the first time it opens, the ones that fit today's procedures are ticked. `GET /admin/visits/{visit}/dental-instructions` prints them for the patient.
- **Review**: `reviewSuggestion` reads today's procedures (else the ones planned on the visit) against `DentalCaseSheet::REVIEWS` (extraction 7 days, denture 2 and so on). `bookReview` books through `AppointmentService::book` with `origin_visit_id`, so it is a follow-up and gets the usual reminders; a taken slot is a 422 in plain words.
- **The visit report** prints a dental visit under the case sheet's headings with the chart as a grid (`pdf/partials/dental-chart`) and the X-ray findings; any other visit that charted teeth prints the chart, and every visit prints its coded diagnoses.

## Recall

`patients.next_recall_on`: the next check-up, six months by default ("In 6 months"), never in the past. The recall message (roadmap 05) reads it.

## Reports

`DentalService::report($from, $to)`: procedures done by dentist, by procedure and by month with revenue, and plans accepted against done (`GET /api/v1/dental/report`).

## Permissions

| Action | Needs |
|---|---|
| See the chart and plan | `treatments.view` or `visits.diagnose` |
| Chart, plan a procedure | `visits.diagnose` |
| Accept, start, mark done, cancel | `treatments.manage` or `visits.diagnose` |
| Set the recall | `patients.update` or `visits.diagnose` |
| The report | `reports.view` or `visits.diagnose` |
| Read the case sheet | `treatments.view` or `visits.diagnose` (and `visits.view`) |
| Write the case sheet, diagnose, record an X-ray | `visits.diagnose` |
| See the financial history | `billing.view`; the payment arrangement is written with `visits.diagnose` or `billing.manage` |
| Book the review | `appointments.manage` |
