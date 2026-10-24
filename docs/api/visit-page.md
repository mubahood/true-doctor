# The visit page on the phone

What the app needs to draw a visit the way the web's visit page does (`Visits\Show`, `Visits\Actions`), for a general hospital and a dental clinic alike. All under `/api/v1`, Sanctum bearer, the usual envelope; a visit is addressed by its uuid and another hospital's is a 404. Routes: `routes/api/gap-visit.php`.

| Method | Path | Needs | Answer |
|---|---|---|---|
| GET | `visits/{uuid}/report` | `visits.view` and `visits.clinical` or `visits.report` (`VisitPolicy::printReport`) | the visit report as a PDF, inline: the web's own `Admin\VisitController::report`, so the patient summary where the practice prints one (or for somebody who does not read the record), the full record otherwise |
| GET | `visits/{uuid}/prescription` | `visits.view` and `visits.clinical` | the prescription as a PDF: the web's `Admin\PrescriptionPdfController`, the same document a patient link opens |
| GET | `visits/{uuid}/review/times` | `visits.view` and `appointments.manage` | `?date=Y-m-d` optional, today or later: the free times for the review booked from this visit (below) |
| GET | `dosage/medicines` | `prescriptions.prescribe` | `?q=` optional: the medicines a dosage is written from (below) |

Both PDFs are the documents the web prints (they are in `GeneratedDocumentTest`), read by the phone with `Accept: application/pdf`.

## How a visit is written: `meta.visit_form`

`GET meta` (and `bootstrap.meta`) carries `visit_form` for every signed-in user, not only the administrator: `App\Support\Practice\VisitForm::toArray()`, the web's own source.

```json
{
  "vitals": ["blood_pressure"], "vitals_title": "Blood pressure", "vitals_customised": false, "shows_bmi": false,
  "dental_notes": "simple", "dental_notes_customised": false, "dentistry": true, "dental_clinic": true,
  "sections": ["summary", "chart", "plan", "prescription", "orders", "bill", "payments", "history"],
  "asks_reason": false, "suggests": false, "dental_visit_form": true, "dosage": true,
  "visit_report": "summary", "visit_report_customised": false
}
```

- `vitals`: the readings this hospital records, in the order a form asks for them; `vitals_title` is "Blood pressure" when that is all, else "Vital signs". `shows_bmi`: only with a weight and a height.
- `dental_notes`: how a dental visit is written by default, `simple` notes or the `detailed` case sheet (a visit already written one way stays that way: see `layout` below).
- `asks_reason`: whether the new visit form asks for a reason (a dental clinic writes the presenting complaint and the server fills the reason from it).
- `sections`: the visit page's sections in the order the work happens (`Visits\Actions::SECTIONS`); a dental clinic's chart, plan and prescription come before the orders and the bill. Who sees each one is still a matter of the user's permissions.
- `dosage`: medicine is written as a dosage (`docs/api/dosage.md`) in place of the prescription form.

The same block is in `GET visits/phrases` (`form`) and in the practice settings.

## How one visit's page is laid out: `layout`

One visit (`GET visits/{uuid}`, and every write that answers with the visit) carries `layout`; a list does not.

```json
"layout": { "notes": "simple", "chart": null }
```

- `notes`: `simple` (the dental notes, `docs/api/dental.md`, "Simple notes"), `case_sheet` (the dental case sheet) or `general` (the clinical note and coded diagnoses). From `DentalNotesService::appliesTo` and `DentalCaseSheetService::appliesTo`, as the web page decides.
- `chart`: on a `general` visit in a hospital that does dentistry, `open` when the dental chart shows by itself (a dental department, a patient already charted) and `press` when it waits behind "Open the chart" (`DentalService::chartOnVisit`); null otherwise. A dental visit's chart is part of its notes or case sheet.

## The review's free times

```json
{ "kind": "dental", "date": "2026-10-31", "doctor_user_id": 7, "doctor": "Dr Sarah Nakato", "minutes": 30,
  "times": ["08:00", "08:30", "09:00"], "note": null }
```

- A dental visit (`kind` `dental`) books from its case sheet's Follow-up (`POST visits/{uuid}/dental-case-sheet/review`): with the visit's dentist, else whoever books, for half an hour (`DentalCaseSheetService::REVIEW_MINUTES`). Without a `date`, the suggested day or the first day after it within a fortnight on which the dentist is free (`DoctorSlots::firstFreeDay`).
- Any other visit (`general`) books from Book a review (`POST visits/{uuid}/review`, `docs/api/follow-up.md`): with the visit's doctor, for `FollowUpService::REVIEW_MINUTES`. Without a `date`, the suggested day.
- `times` are the times that book (`DoctorSlots::open`); when there are none, `note` says why in plain words ("This visit has no doctor yet. Book the review from Appointments instead.", a day the doctor does not sit, a day fully booked). A past `date` is a 422 on `date`.

The rules are `FollowUpService::reviewTimes`, the web panels' own.

## The dosage's medicines

```json
[ { "id": 41, "uuid": "…", "name": "Amoxicillin 500mg", "sku": null, "unit": "capsules", "on_hand": "40.00",
    "in_stock": true, "meta": "40 capsules in stock" } ]
```

Every active item of the hospital's stock, even one that has run out (`meta` "Out of stock"), by name or SKU, ordered by name, at most 20: `StockItem::scopeMedicineChoices`, the web's medicine picker's own query. `id` is the `stock_item_id` a dosage row takes.

## Other additions

- `GET visits/{uuid}/dental-case-sheet` answers `asks_pregnancy`: whether the fields marked `women` (the pregnancy question) are asked of this patient (`CaseSheetService::asksPregnancy`).
- Deleting a visit (`POST visits/{uuid}/delete`) and the dental notes, case sheet, dosage, chart and review endpoints are documented with their areas (`docs/api.md`, `docs/api/dental.md`, `docs/api/dosage.md`, `docs/api/follow-up.md`).
