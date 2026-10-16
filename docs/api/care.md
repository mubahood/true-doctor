# API v1 — Care (scheduling and inpatient)

Base `/api/v1`, Sanctum bearer token, standard envelope and error codes from `docs/api.md`
(`validation_failed` 422, `forbidden` 403, `not_found` 404; another hospital's record is always 404).
Lists use the paginated envelope (`meta.current_page|per_page|total|last_page`), accept `per_page` (1-100, default 20).
Every route does what the matching Livewire page does, through the same service and policy.
Routes live in `routes/api/care.php`; tests: `ScheduleApiTest`, `AdmissionDeskApiTest`, `OccupancyApiTest`.

Permissions: `appointments.view` (read availability), `schedules.manage` (write availability),
`ipd.view` (read inpatient), `ipd.manage` (admit, transfer, discharge, chart), `wards.manage` (bed out of service).
A role without the permission gets 403.

## Doctor availability (`admin.schedules.index`)

| Method | Route | Permission |
|---|---|---|
| GET | `/schedules` | appointments.view |
| GET | `/schedules/doctors/{doctorId}` | appointments.view |
| POST | `/schedules` | schedules.manage |
| PUT | `/schedules/{id}` | schedules.manage |
| POST | `/schedules/{id}/toggle` | schedules.manage |
| DELETE | `/schedules/{id}` | schedules.manage |

**GET /schedules** query: `view=roster` (default) or `list`; `day` (0 Sunday to 6); `state=active|off`;
`unrostered=1` (roster only: doctors with no window at all); `q` (doctor name); `per_page`.

Roster (a page of doctors, each with a Monday-first seven-day grid):

```json
{ "success": true, "data": [ { "id": 7, "name": "Dr Kasujja", "hours_per_week": 4, "has_schedule": true,
  "week": [ { "weekday": 1, "label": "Monday", "windows": [] },
            { "weekday": 4, "label": "Thursday", "windows": [
              { "id": 12, "doctor_id": 7, "room_id": null, "weekday": 4, "weekday_label": "Thursday",
                "start_time": "08:00", "end_time": "12:00", "slot_minutes": 30, "minutes": 240, "slots": 8, "is_active": true } ] } ] } ],
  "meta": { "current_page": 1, "per_page": 20, "total": 2, "last_page": 1,
    "facts": { "doctors": 2, "rostered": 1, "unrostered": 1, "hours": 4, "slots": 8, "windows": 1, "off": 0,
               "coverage": { "0": 0, "1": 0, "4": 1 }, "overlapping_window_ids": [] } } }
```

`meta.facts` covers the whole hospital, not the filtered page (`coverage` = doctors sitting per weekday;
`overlapping_window_ids` = windows that clash with another of the same doctor/day).
`view=list` returns window objects (with `doctor` and `room` names), one per row.

**GET /schedules/doctors/{doctorId}** returns the single doctor object above (all windows, unfiltered); 404 if not a clinician of this hospital.

**POST /schedules** body: `user_id`, `weekday` (0-6, or `all` / `weekdays` / `weekend`), `start_time` (H:i),
`end_time` (H:i, after start), `slot_minutes` (5-240), optional `room_id`, `is_active` (default true).
Returns `{ added, skipped, windows[] }` (201 when something was added, 200 with message when every day already had
that exact window). **PUT** takes the same fields with a single day and returns the window. **toggle** flips `is_active`.
**DELETE** removes the window. Time-off/leave does not exist in the web app, so it has no endpoint.

## Admissions (`admin.admissions.index`, `.show`)

| Method | Route | Permission |
|---|---|---|
| GET | `/admissions` | ipd.view |
| GET | `/admissions/{uuid}` | ipd.view |
| POST | `/admissions` | ipd.manage |
| POST | `/admissions/{uuid}/transfer`, `/discharge` | ipd.manage (existing) |
| GET | `/admissions/{uuid}/vitals`, `/medications`, `/notes` | ipd.view |
| POST | `/admissions/{uuid}/vitals`, `/medications`, `/notes` | ipd.manage |

**GET /admissions** query: `status` (`admitted` default, `discharged`, `deceased`, `absconded`), `ward` (ward id),
`q` (patient name or number), `per_page`. Item:

```json
{ "uuid": "…", "status": "admitted", "status_label": "Admitted", "is_active": true,
  "patient": { "uuid": "…", "name": "Amina K", "patient_no": "P-0001" },
  "ward": { "id": 3, "name": "Male ward" }, "bed": { "id": 9, "name": "M-1", "daily_charge": "50.00" },
  "admitting_doctor": { "id": 4, "name": "Dr Okello" }, "reason": "Malaria",
  "admitted_at": "2026-10-16T09:00:00+00:00", "discharged_at": null, "nights": 1,
  "accrued_so_far": "50.00", "bed_charge_total": "0.00", "nights_billed": 0,
  "visit": { "id": 21, "uuid": "…", "visit_no": "V-20261016-1" }, "discharge_notes": null }
```

**GET /admissions/{uuid}** returns the item plus `transfers[]` (`at, from, to, reason`), `can_manage`, and
`bedside`: `latest_vitals`, `vitals_count`, `medication_count`, `recent_medication[]` (5), `nursing_notes_count`,
`recent_notes[]` (3). The money is the visit's bill: `GET /visits/{visit.uuid}/bill`.

**POST /admissions** (admit; the web dialog) body: `visit_id` and `bed_id` (required), `admitting_doctor_id`,
`reason`, `stay_title`. The patient is whoever the visit belongs to. 201 returns the item. 422 with
`errors.bed_id` (bed taken/not found) or `errors.visit_id` (already admitted, visit mismatch, not found).

**Chart lists** are paginated newest first. Entry shapes: vitals `{id, created_at, temperature, pulse, blood_pressure,
respiratory_rate, spo2, note, recorded_by}`; medication `{id, created_at, drug_name, dose, route, status, note,
administered_by}`; note `{id, created_at, note, recorded_by}`. **POST** bodies: vitals (all optional: `temperature` 25-45,
`pulse`, `blood_pressure` like `120/80`, `respiratory_rate`, `spo2`, `note`); medication (`drug_name` and `status`
`given|withheld|refused`, `dose`, `route`, `note`); note (`note`). 201 returns the entry.
A closed admission answers 422 "This admission is closed."

The existing `GET /beds` is unchanged except each bed now also has `occupant`
(`admission_uuid, patient_name, patient_no, admitted_at`, or null).

## Occupancy board (`admin.admissions.board`)

| Method | Route | Permission |
|---|---|---|
| GET | `/occupancy` | ipd.view |
| GET | `/occupancy/beds/{bedId}` | ipd.view |
| POST | `/occupancy/beds/{bedId}/status` | wards.manage |

**GET /occupancy** query: `ward` (id), `status` (`available|occupied|maintenance`), `q` (bed name or patient).
`data.figures` is hospital-wide (`total, occupied, free, maintenance, percent`); `data.wards[]` is
`{id, name, beds[]}`, bed = `{id, name, ward_id, status, status_label, daily_charge, occupant}`, occupant (null when
empty) = `{admission_uuid, patient{uuid,name,patient_no}, admitting_doctor, reason, admitted_at, nights, accrued_so_far, visit_uuid}`.

**GET /occupancy/beds/{id}**: the bed as above plus `ward`, `recent_transfers[]` (5) and, for an empty bed,
`last_stay` (`patient_name, discharged_at, status`).

**POST /occupancy/beds/{id}/status** body `{ "status": "available" | "maintenance" }`. 422 if `occupied` is asked
("A bed is only occupied by admitting somebody to it.") or the bed has a patient ("Discharge or transfer the patient first.").
Admit from a free bed with `POST /admissions`; transfer/discharge with the existing routes.

## Not covered

Appointments, the check-in queue and booking already had API routes. Wards, beds, rooms and departments CRUD
belong to the admin area.

## Clinical alerts on every patient

Wherever a payload names the patient a clinician is about to act on (a visit, an admission, a lab or imaging order, a prescription in the dispense queue), `patient.alerts` carries the same block (`App\Support\Clinical\PatientAlerts`):

```json
"alerts": { "allergies": ["Penicillin"], "conditions": ["Hypertension"], "deceased": false, "allergies_recorded": true }
```

- `allergies` leaves out "None", "NKDA" and "No known allergies", which record that there are none.
- `allergies_recorded: false` means nobody has asked: show "Allergies not recorded", never nothing.
- `null` when there is no patient on the record.
