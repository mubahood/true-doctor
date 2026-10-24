# Follow-up API (reviews and recalls)

All under `/api/v1`, Sanctum bearer, the usual envelope. Rules in `FollowUpService` ([../follow-up.md](../follow-up.md)).

| Method | Path | Permission | What |
|---|---|---|---|
| GET | `visits/{uuid}/review` | `visits.view` + `appointments.view` | `{ suggestion: {days, reason, date}, booked: [appointment] }` |
| POST | `visits/{uuid}/review` | `visits.view` + `appointments.manage` | Body `{date: "Y-m-d", time: "H:i", doctor_user_id?}`. 201 with the appointment; 422 when the time is not free or outside the doctor's hours |
| GET | `follow-ups?show=week\|missed\|recalls&q=` | `appointments.view` | Paged rows `{kind: review\|recall, id, key, due_on, what, flag, patient, appointment}`; `meta.counts` has the size of each whole list, `meta.show` the list answered, `meta.search` the search applied. `q` (up to 100) finds a patient by name, number or phone, every word somewhere (`Patient::matchWords`), as the web page's search box does |
| POST | `follow-ups/text` | `appointments.manage` | Body `{kind, id}` (a row's `kind` and `id`). `data.sent` true or false, `message` says why not (no consent, no phone, no credit, texts off). Sent once per thing due |

An appointment row: `{uuid, scheduled_at, status, status_label, doctor, reason}`.

A list row, beside `kind`, `id` (what `follow-ups/text` takes), `key` and `due_on`:

- `what`: `Dental check-up` or `Review`.
- `flag`: `{label, tone}` or null, worded by `FollowUpService::flag` for the web page and the app alike: `20 days overdue` (`warn`) for a check-up past its day, `Did not come` (`danger`) for a review marked no-show, `Not attended` (`danger`) for one left booked with the day gone.
- `patient`: `{uuid, server_id, name, first_name, last_name, patient_no, phone, sms_consent, last_visit_at}`. `server_id` is what `POST appointments` takes as `patient_id`, to book from the row. `sms_consent` is the patient's latest answer about texts on their care (`sms.care`, `ConsentService::allowsMany`); a text is only sent with it. `last_visit_at` is their newest visit (ISO 8601) or null. Both are worked out for the page in one query each.

```json
{
  "kind": "recall", "id": 41, "key": "recall:2026-10-04", "due_on": "2026-10-04",
  "what": "Dental check-up", "flag": {"label": "20 days overdue", "tone": "warn"},
  "patient": {"uuid": "9b1e…", "server_id": 41, "name": "Amina Nakato", "first_name": "Amina", "last_name": "Nakato",
              "patient_no": "PT-0041", "phone": "0772123456", "sms_consent": true, "last_visit_at": "2026-04-07T09:12:00+03:00"},
  "appointment": null
}
```

Writes go through the idempotency middleware like every other API write.
