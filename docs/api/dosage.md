# Dosage on a visit

The web's **Dosage** panel for the app (docs/pharmacy.md, "Dosage"). The rows are prescription lines written by `PrescriptionService::writeDosage`, so the pharmacy queue (`GET prescriptions`), dispensing and the bill follow the prescription flow. Nothing is billed by these calls.

| Method | Path | Needs |
|---|---|---|
| GET | `/api/v1/visits/{visit}/dosage` | `visits.view` and the clinical record (`viewClinical`) |
| POST | `/api/v1/visits/{visit}/dosage` | `prescriptions.prescribe` |
| DELETE | `/api/v1/visits/{visit}/dosage/{dose uuid}` | `prescriptions.prescribe` |

## Read

```json
{
  "items": [
    {
      "uuid": "9c1…",
      "prescription_uuid": "5e2…",
      "stock_item_id": 41,
      "medicine": "Amoxicillin 500mg",
      "dose": "1 capsule",
      "frequency": "Three times a day",
      "days": 5,
      "note": "After meals",
      "prescribed_by": "Dr Okello",
      "created_at": "2026-10-22T10:15:00+03:00"
    }
  ],
  "frequencies": ["Once a day", "Twice a day", "Three times a day", "Four times a day", "When needed"]
}
```

Every line on the visit, oldest first, including lines written on the older prescription form (their `frequency` is the daily slots in words, `stock_item_id` null).

## Write

```json
{ "items": [
  { "stock_item_id": 41, "dose": "1 capsule", "frequency": "Three times a day", "days": 5, "note": "After meals" },
  { "stock_item_id": 57, "dose": "2 tablets", "frequency": "Every 6 hours", "days": 3 }
] }
```

- `stock_item_id`: an item of the hospital's stock (any active item, even one that has run out). Required.
- `dose`: up to 60 characters. Required.
- `frequency`: one of `frequencies`, or the words (up to 60 characters). Required.
- `days`: 1 to 90. Required. `note`: optional, up to 255.

Answers **201** with the same body as the read. The rows become one prescription. A standard frequency also fills the line's daily slots so the pharmacy queue can size the quantity; "When needed" and free text have none. No administration schedule is made: the medicine is taken at home. Send an `Idempotency-Key`; a resend after a lost reply returns the first answer and writes nothing twice.

## Remove

`DELETE …/dosage/{dose uuid}` removes one line and answers with the read body. Refused with **422** once the pharmacy has dispensed against its prescription or a dose of it was recorded. The last line removed takes its empty prescription with it.

The older `GET visits/{visit}/prescriptions` now also carries each line's `uuid`, `stock_item_id` and `frequency`.
