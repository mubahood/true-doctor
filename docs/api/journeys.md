# Journeys API (Super app plan, Wave 2 server)

All routes are under `/api/v1`, authenticated (Sanctum), inside the subscribed group, and use the standard
envelope (`docs/api.md`). Records are addressed by uuid. Tenancy is the global scope: a record of another hospital
is `404`. Routes: `routes/api/journeys.php`. Public share routes: `routes/web.php` (`share.invoice`, `share.receipt`).

| Endpoint | Permission (same as the web) |
|---|---|
| `GET /me/next-actions` | any signed-in user; the list is filtered by what they may do |
| `GET /me/agenda?days=3` | any signed-in user; each source filtered by its permission |
| `POST/DELETE /invoices/{invoice}/share` | `billing.view` (as the invoice PDF) |
| `POST/DELETE /payments/{payment}/share`, `GET /payments/{payment}/receipt.json` | `billing.view` |
| `GET /cashier/day-summary`, `/pdf` | `billing.view` |
| `GET /debtors` | `billing.view` |
| `GET /prescriptions?status=pending` | `pharmacy.dispense` |
| `GET /patients/{patient}/last-prescription` | `visits.view` |
| `GET /ward/doses-due`, `/ward/handover`, `/ward/handover/history`, `/ward/handover/{id}/pdf` | `ipd.view` |
| `POST /ward/handover`, `POST /ward/handover/{id}/acknowledge` | `ipd.manage` |
| `GET /patients/possible-duplicates` | `patients.view` |

Wards have no uuid in this API: `ward_uuid` (or `ward_id`) is the ward's integer `id` as in `GET /beds` and `GET /wards`.

## GET /me/next-actions

Up to 8 items, most urgent first (urgent, then high, then normal; ties by an internal priority). Cached 45 s per user.
`route` is a web route name the app maps to a screen (the same names as `GET /insights`); `uuid` is the record to open or `null`.
`kind` is stable (examples: `next-patient`, `results-ready`, `doses-overdue`, `doses-due`, `vitals-due`, `pending-prescriptions`,
`unpaid-visits`, `lab-queue`, and the Insight keys `queue-long-waits`, `appointments-not-arrived`, `invoices-overdue`, `invoices-unpaid`,
`stock-expired`, `stock-running-out`, `stock-expiring`, `setup-incomplete`, `subscription-ending`, `licence-updates-ending`...). An unknown kind must be shown, not hidden.

```json
{ "success": true, "code": "ok", "message": null, "errors": null,
  "data": {
    "items": [
      { "kind": "doses-overdue", "title": "3 doses are overdue", "sub": "Oldest 60 min late. Give or record why not.",
        "route": "admin.admissions.board", "uuid": null, "urgency": "urgent", "count": 3 },
      { "kind": "next-patient", "title": "Next patient: Amina Nakato", "sub": "Vitals done 12 min ago.",
        "route": "admin.visits.show", "uuid": "6b0c...-visit-uuid", "urgency": "high", "count": null },
      { "kind": "invoices-unpaid", "title": "4 unpaid invoices", "sub": "USh 212,000 waiting to be collected.",
        "route": "admin.invoices.index", "uuid": null, "urgency": "high", "count": 4 }
    ],
    "generated_at": "2026-10-18T09:00:00+00:00", "cache_seconds": 45 } }
```

Sources: the Insight rules (`docs/agents/02-backend.md`) mapped danger/warn/info to urgent/high/normal, plus: doctor (next patient of mine
with vitals done; lab results I ordered completed in the last day), nurse (doses overdue / due in 30 min; admitted patients with no
observations for 6 hours), pharmacist (prescriptions waiting; stock rules), front desk and cashier (visits waiting to pay; waits, no-shows,
overdue and unpaid invoices), lab (orders waiting; "high" once one is 4 hours old), admin (setup, trial, licence, stock).

## GET /me/agenda?days=3

`days` 1-14 (default 3). Time-ordered. `priority` is `urgent|high|normal`. `kind`: `appointment`, `dose`, `lab-result`,
`stock-expiring`, `invoice-unpaid`, `visit-open`. Appointments: everyone with `appointments.manage` sees the diary, others only their own bookings.
Unpaid invoices appear from 30 days after issue (the "payable within 30 days" terms), `high` once past.

```json
{ "data": { "items": [
  { "kind": "dose", "due_at": "2026-10-18T08:00:00+00:00", "priority": "urgent",
    "subject": "Amina Nakato - Quinine 600mg (F-1)",
    "action": { "route": "admin.admissions.show", "uuid": "admission-uuid", "verb": "Give" } },
  { "kind": "appointment", "due_at": "2026-10-18T10:00:00+00:00", "priority": "high",
    "subject": "10:00 Amina Nakato - Review",
    "action": { "route": "admin.appointments.show", "uuid": "appointment-uuid", "verb": "Open" } } ],
  "days": 3, "generated_at": "2026-10-18T07:00:00+00:00" } }
```

## Sharing an invoice or receipt

`POST /invoices/{invoice}/share` and `POST /payments/{payment}/share` (200). 30 requests a minute.

```json
{ "data": {
    "url": "https://example.test/s/i/3f2c...-invoice-uuid/Qw8xK2mZp4Ls9TnA?expires=1792400000&signature=9d3a...",
    "expires_at": "2026-10-25T09:00:00+00:00",
    "phone": "0700 111 222",
    "whatsapp_text": "Dear Amina, here is your invoice INV-0042 from Kisenyi Referral. Balance: USh 20,000. Open it here (valid 7 days): https://...",
    "sms_text": "Kisenyi Referral: invoice INV-0042. Balance: USh 20,000. https://..." } }
```

- The link is a Laravel temporary signed URL (7 days) with the document uuid and the invoice's share nonce; **nothing that names a person**.
  It opens the invoice or receipt PDF with no login (`inline`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex`), 30 requests a minute per IP.
- `phone` is the patient's `phone_1` as typed (or `null`); the app builds `wa.me`/`sms:` from it and the texts.
- Revoke: `DELETE /invoices/{invoice}/share` (or `DELETE /payments/{payment}/share`, same effect) changes the invoice's `share_nonce`; every link issued
  before, for the invoice and its receipts, answers `410`. A tampered or expired link answers `403`; a void invoice `404`. A new `POST` after a revoke
  issues a working link.

## GET /payments/{payment}/receipt.json

The receipt as data for a thermal printer. Money is strings; `qr_payload` is `td:receipt:{payment uuid}:{amount}:{currency}`.

```json
{ "data": {
    "uuid": "pay-uuid", "receipt_no": "R3F2C9A1B",
    "brand": { "name": "Kisenyi Referral", "address": "Plot 14, Nakivubo Road", "phone": "+256 700 000 000", "email": null, "registration": null },
    "currency": "UGX", "invoice_no": "INV-0042",
    "patient": { "name": "Amina Nakato", "patient_no": "PT-2026-000123" },
    "lines": [ { "description": "Consultation", "quantity": "1", "unit_price": "30000.00", "line_total": "30000.00" } ],
    "totals": { "subtotal": "30000.00", "discount": "0.00", "tax": "0.00", "total": "30000.00", "paid_this_payment": "10000.00", "paid_to_date": "10000.00" },
    "method": "mobile_money", "method_label": "Mobile money", "reference": "MM-1", "card": null,
    "balance": "20000.00", "settled": false, "received_by": "Grace Auma", "paid_at": "2026-10-18T09:00:00+00:00",
    "qr_payload": "td:receipt:pay-uuid:10000.00:UGX", "footer": "Thank you." } }
```

## GET /cashier/day-summary?date=YYYY-MM-DD

`date` defaults to today **in the hospital's time zone** (the day is that zone's calendar day, converted to the stored clock). `totals_by_method`
and `by_user` are largest first. `voided` is invoices voided that day. `GET /cashier/day-summary/pdf?date=` is the printable sheet.

```json
{ "data": { "date": "2026-10-18", "currency": "UGX",
  "totals_by_method": [ { "method": "cash", "count": 2, "amount": "25000.00" }, { "method": "mobile_money", "count": 1, "amount": "10000.00" } ],
  "total": "35000.00", "payments_count": 3, "invoices_raised": 5, "voided": 0,
  "by_user": [ { "name": "Grace Auma", "total": "20000.00" }, { "name": "Peter Ocen", "total": "15000.00" } ] } }
```

## GET /debtors?bucket=&q=&page=&per_page=

Invoices with money owed: `Invoice::outstanding()` (issued or part-paid, balance above zero, never void), the definition the reports and the patient page use.
`bucket`: `0-30`, `31-60`, `61-90`, `90+` (send `90%2B`; a bare `90` is also read as `90+`). `q` searches invoice number and patient. Oldest first.
`age_days` is whole days since the invoice was raised. `meta.aging` and `meta.total_outstanding` cover **all** outstanding invoices, not the page or the filter.

```json
{ "data": [ { "uuid": "inv-uuid", "number": "INV-0007", "invoice_no": "INV-0007",
    "patient": { "uuid": "p-uuid", "name": "John Okello", "patient_no": "PT-2026-000044", "phone": "0777000111" },
    "currency": "UGX", "total": "40000.00", "balance": "40000.00", "age_days": 120, "bucket": "90+", "issued_at": "2026-06-20T08:00:00+00:00" } ],
  "meta": { "current_page": 1, "per_page": 20, "total": 4, "last_page": 1,
    "aging": { "0-30": "1000.00", "31-60": "2000.00", "61-90": "10000.00", "90+": "4000.00" },
    "total_outstanding": "17000.00", "outstanding_count": 4 } }
```

## GET /prescriptions?status=pending&page=

The dispense queue, longest wait first. Only `status=pending` exists. A line is undispensed when nothing on its visit was dispensed under that
drug's name; a dispensation recorded with `prescription_id` closes the whole prescription. Prescriptions from the last 14 days (newest 300 considered).
`qty` is slots per day times days. `stock_item_uuid`/`stock_available` are `null` when no stock item has that name (exact, else prefix);
`short` is true when the shelf holds less than `qty`.

```json
{ "data": [ { "uuid": "rx-uuid", "visit_uuid": "visit-uuid",
    "patient": { "uuid": "p-uuid", "name": "Amina Nakato", "patient_no": "PT-2026-000123" },
    "prescribed_at": "2026-10-18T09:00:00+00:00", "waited_minutes": 25,
    "items": [ { "drug_name": "Amoxicillin 500mg", "qty": 10, "dose": "1 tab", "instructions": null,
                 "stock_item_uuid": "stock-uuid", "stock_available": "30.00", "short": false },
               { "drug_name": "Unstocked syrup", "qty": 1, "dose": null, "instructions": null,
                 "stock_item_uuid": null, "stock_available": null, "short": false } ] } ],
  "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1, "oldest_wait_minutes": 25, "window_days": 14 } }
```

## GET /patients/{patient}/last-prescription[?visit={uuid}]

The patient's latest prescription (`?visit=` leaves that visit's out, so a doctor mid-visit gets the previous one). `data.prescription` is `null` when none.
Items carry `dose` (and `dosage`, the same value), `slots`, `days`, `instructions`.

```json
{ "data": { "prescription": { "uuid": "rx-uuid", "visit_uuid": "visit-uuid", "prescribed_by": "Dr Lwanga", "prescribed_at": "2026-10-10T09:00:00+00:00", "notes": null,
  "items": [ { "drug_name": "Coartem", "dose": "4 tabs", "dosage": "4 tabs", "slots": ["morning", "night"], "days": 3, "instructions": "With food" } ] } } }
```

## Ward round

Slots have no clock time in the data; the ward gives them 08:00 morning, 13:00 afternoon, 18:00 evening, 22:00 night in the hospital's time zone.

**GET /ward/doses-due?ward_uuid=&within=60** (`within` minutes 0-1440): pending doses of admitted patients due within that time or already overdue, soonest first,
doses more than 2 days old ignored. `dose_record_id` is what `POST /doses/{record}` takes to give or miss it.

```json
{ "data": [ { "admission_uuid": "adm-uuid", "patient": { "uuid": "p-uuid", "name": "Amina Nakato", "patient_no": "PT-2026-000123" },
    "bed": { "name": "F-1", "ward": "Female ward", "ward_id": 3 }, "drug": "Quinine", "dose": "600mg", "slot": "morning",
    "due_at": "2026-10-18T08:00:00+00:00", "overdue_minutes": 60, "dose_record_id": 1812 } ],
  "meta": { "total": 1, "within": 60 } }
```

**GET /ward/handover?ward_uuid=**: one SBAR seed per admitted patient (all wards without `ward_uuid`). Each part has an editable `text` and the facts behind it.
`assessment.trend` is `worsening|stable|improving|unknown` (flags in the last two observation rounds), `flags` the readings outside plain adult limits.
`recommendation.notes_since_last_handover` are nursing notes since the last saved handover of that ward.

```json
{ "data": [ { "admission_uuid": "adm-uuid", "patient": { "uuid": "p-uuid", "name": "Amina Nakato", "patient_no": "PT-2026-000123" },
    "bed": { "name": "F-1", "ward": "Female ward", "ward_id": 3 },
    "situation": { "text": "Severe malaria. Diagnosis: Malaria. Day 2 of admission.", "reason": "Severe malaria", "diagnosis": "Malaria", "day": 2 },
    "background": { "text": "Allergies: Penicillin. Chronic: Asthma.", "allergies": ["Penicillin"], "chronic": ["Asthma"], "history": null },
    "assessment": { "text": "Last obs 1 hour ago: T 39.2, P 125, BP 110/70, SpO2 90%. Concern: fever, pulse, low oxygen. Trend worsening.",
      "last_vitals": { "temperature": "39.2", "pulse": 125, "blood_pressure": "110/70", "respiratory_rate": null, "spo2": 90, "at": "2026-10-18T08:00:00+00:00" },
      "trend": "worsening", "flags": ["fever", "pulse", "low oxygen"] },
    "recommendation": { "text": "Pending: 1 lab order awaiting results. Next meds: Quinine 600mg (afternoon). Notes since last handover: Vomited twice",
      "pending_orders": ["1 lab order awaiting results"], "meds_due_next": [ { "drug": "Quinine", "dose": "600mg", "slot": "afternoon", "due_at": "2026-10-18T13:00:00+00:00" } ],
      "notes_since_last_handover": ["Vomited twice"] } } ],
  "meta": { "total": 1, "since": null } }
```

**POST /ward/handover** (`ipd.manage`, 201): `{ ward_uuid, shift: morning|afternoon|evening|night, notes: [{ admission_uuid, situation, background, assessment, recommendation }] }`
(each text up to 2000 characters, plain strings: send back the seed's `text`, edited). Every `admission_uuid` must be an admitted patient of that ward (else `422`).
Patient name and bed are stored with the note so the record stays true after a discharge.

```json
{ "data": { "uuid": "handover-uuid", "ward": { "id": 3, "name": "Female ward" }, "shift": "morning", "author": "Sr Namuli",
    "created_at": "2026-10-18T08:00:00+00:00", "acknowledged_by": null, "acknowledged_at": null,
    "notes": [ { "admission_uuid": "adm-uuid", "patient": { "uuid": "p-uuid", "name": "Amina Nakato", "patient_no": "PT-2026-000123" }, "bed": "F-1",
      "situation": "...", "background": "...", "assessment": "...", "recommendation": "Review SpO2 at 14:00" } ] } }
```

**GET /ward/handover/history?ward_uuid=&page=**: paginated list of the same objects, newest first.
**POST /ward/handover/{uuid}/acknowledge**: the incoming nurse takes it. The author cannot (`422`); a repeat returns the same record.
**GET /ward/handover/{uuid}/pdf**: the printable handover (`application/pdf`, inline).

## GET /patients/possible-duplicates?first=&last=&dob=&phone=

At least one of first, last or phone. `dob` is `YYYY-MM-DD`. Up to 5 candidates in this hospital, strongest reason first: `same_phone` (last 9 digits,
spaces/dashes/plus ignored), `same_name_dob`, `similar_name` (first three letters of both names, either order). `reason` is the sentence to show.

```json
{ "data": { "candidates": [
  { "uuid": "p-uuid", "name": "Amina Nakato", "patient_no": "PT-2026-000123", "dob": "1990-04-02", "sex": "female", "phone": "+256 700 123 456",
    "reason": "Same phone", "reason_code": "same_phone" } ] } }
```

## Idempotency-Key is required on money endpoints (from the app)

`POST /invoices/{invoice}/payments`, `POST /cards/{card}/transactions`, `POST /cards/{card}/settle`, `POST /stock-items/{stock}/receive|adjust|write-off`
and `POST /visits/{visit}/dispensations` need an `Idempotency-Key` header **when the request carries `X-App-Version`** (the apps always do; the web and Field Mode
do not, and are unchanged). Without it:

```json
{ "success": false, "code": "validation_failed", "message": "This request moves money or stock, so it needs an Idempotency-Key header (a fresh UUID per attempt; reuse it when retrying).",
  "data": null, "errors": { "Idempotency-Key": ["The Idempotency-Key header is required."] } }
```

Status `422`, checked after authentication (a signed-out caller still gets `401`). The app's `ApiClient` already sends a key on every write except sign-in.
The list lives in `IdempotentRequests::KEY_REQUIRED_ROUTES`.
