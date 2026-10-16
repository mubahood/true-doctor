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
