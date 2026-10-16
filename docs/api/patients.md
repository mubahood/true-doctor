# API: the patient summary

Envelope, auth and error codes are in [`../api.md`](../api.md). Code: `routes/api/extras.php`,
`PatientSummaryController`, logic in `app/Services/PatientSummaryService.php` (the same service the
web patient page reads - see [`../patients.md`](../patients.md), "The patient record").
Tests: `tests/Feature/Api/PatientSummaryApiTest.php`. The older `GET /patients/{uuid}/brief`
(what the desk needs before opening a visit) is unchanged.

## `GET /api/v1/patients/{uuid}/summary`

Authorised like the web page: the user must be able to view the patient (`patients.view`).
Another hospital's patient is `404`. `?limit=` (1-25, default 5) bounds each recent list.

**Sections you may not see are OMITTED, not returned empty.** `sections` lists what you were given.

| Section | Needs | Adds |
|---|---|---|
| `visits` | `visits.view` | `figures.visits_count/last_visit_at/open_visit`, `visits` |
| `clinical` | `visits.view` | `diagnoses`, `medication`, `vitals` |
| `lab` / `imaging` | `visits.view` + practice uses laboratory / radiology | `lab_orders`, `imaging_orders` |
| `money` | `billing.view` | `figures.outstanding/billed_total/paid_total/...`, `invoices`, `payments`, `aging`, the `owes` alert |
| `appointments` | `appointments.view` | `figures.next_appointment`, `appointments` |
| `admissions` | `ipd.view` + practice uses inpatient | `figures.admitted_now`, `admissions`, the `admitted` alert |
| `cards` | `patients.card.manage` + practice uses cards | `figures.card_balance`, `figures.cards_count` |
| `insurance` | `insurance.view` + practice uses insurance | the `insurance` alert (claims awaiting) |

Money is a scale-2 decimal STRING; the `*_label` fields are the same amounts in the hospital's own
format. Dates are ISO-8601. Allergies, chronic conditions, consent and status alerts are always given.

```json
{ "success": true, "code": "ok", "data": {
  "patient": { "uuid": "9f0c…", "patient_no": "PT-2026-000123K", "name": "Grace Nakato", "age": 34, "sex": "female", "status": "active" },
  "sections": ["visits", "clinical", "lab", "imaging", "money", "appointments", "admissions"],
  "figures": {
    "outstanding": "25000.00", "outstanding_label": "USh 25,000", "billed_total": "25000.00", "paid_total": "0.00",
    "outstanding_count": 1, "oldest_unpaid_days": 10,
    "visits_count": 3, "last_visit_at": "2026-10-12T09:14:00+00:00",
    "open_visit": { "uuid": "…", "visit_no": "V-20261012-4", "status": "Ongoing" },
    "next_appointment": { "uuid": "…", "scheduled_at": "2026-10-20T09:00:00+00:00", "doctor": "Dr. Okello", "status": "Scheduled" },
    "admitted_now": null },
  "alerts": [
    { "key": "allergies", "tone": "warn", "items": ["Penicillin"] },
    { "key": "owes", "tone": "danger", "tab": "billing", "amount": "25000.00", "invoices": 1, "oldest_unpaid_days": 10 } ],
  "aging": { "0-30": "25000.00", "31-60": "0.00", "61-90": "0.00", "90+": "0.00" },
  "visits": [ { "uuid": "…", "visit_no": "V-…", "date": "…", "state": "Ongoing", "doctor": "Dr. Okello", "diagnosis": null, "billed": "25000.00", "balance": "25000.00" } ],
  "invoices": [], "payments": [], "diagnoses": [], "medication": [], "vitals": [],
  "lab_orders": [], "imaging_orders": [], "admissions": [], "appointments": [] } }
```

`alerts[].key` is one of `status`, `allergies`, `chronic`, `owes`, `admitted`, `no_consent`, `insurance`;
`tone` is `danger`, `warn` or `info`. `aging` bands are by when the invoice was raised, over invoices that are
issued or part-paid with a balance left (the same definition the reports use); void invoices are never counted.

Errors: `401` no token, `403` cannot view patients, `404` unknown or another hospital's patient.

## `GET /api/v1/patients/{uuid}/statement`

The statement of account as a PDF (the one the web prints), under the web's rule: `billing.view`.
Opens inline; `Content-Type: application/pdf`.
