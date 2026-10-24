# Follow-up API (reviews and recalls)

All under `/api/v1`, Sanctum bearer, the usual envelope. Rules in `FollowUpService` ([../follow-up.md](../follow-up.md)).

| Method | Path | Permission | What |
|---|---|---|---|
| GET | `visits/{uuid}/review` | `visits.view` + `appointments.view` | `{ suggestion: {days, reason, date}, booked: [appointment] }` |
| POST | `visits/{uuid}/review` | `visits.view` + `appointments.manage` | Body `{date: "Y-m-d", time: "H:i", doctor_user_id?}`. 201 with the appointment; 422 when the time is not free or outside the doctor's hours |
| GET | `visits/{uuid}/review/times?date=` | `visits.view` + `appointments.manage` | The free times for the review, as the visit page offers them (a dental visit's from its case sheet, any other's from here): `{kind, date, doctor_user_id, doctor, minutes, times[], note}` ([visit-page.md](visit-page.md)) |
| GET | `follow-ups?show=week\|missed\|recalls` | `appointments.view` | Paged rows `{kind: review\|recall, id, key, due_on, patient: {uuid, name, patient_no, phone}, appointment}`; `meta.counts` has the size of each list |
| POST | `follow-ups/text` | `appointments.manage` | Body `{kind, id}` (a row's `kind` and `id`). `data.sent` true or false, `message` says why not (no consent, no phone, no credit, texts off). Sent once per thing due |

An appointment row: `{uuid, scheduled_at, status, status_label, doctor, reason}`.

Writes go through the idempotency middleware like every other API write.
