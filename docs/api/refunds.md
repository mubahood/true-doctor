# API: refunds and credit notes

Rules and figures: [`../refunds.md`](../refunds.md). Envelope as everywhere
(`success, code, message, data, errors`). Every write is covered by the
`Idempotency-Key` middleware: the app sends a key and reuses it after a lost
reply, and a replay returns the first answer (`Idempotent-Replay: true`)
without creating a second document. An installed app (any request carrying `X-App-Version`)
must send the key on `credit-notes` and `refunds`: without it the answer is
`422` (`errors.Idempotency-Key`).

## Endpoints

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api/v1/credits?status=&kind=&q=&waiting_first=&per_page=` | `billing.view` | Every document, newest first; `waiting_first=1` puts the ones waiting for approval at the top (newest first within each part). `meta` adds `pending` (waiting for approval). |
| GET | `/api/v1/credits/{credit}` | `billing.view` | One document with its invoice, the invoice's lines and figures (below): the web's quick view. |
| GET | `/api/v1/invoices/{invoice}/credits` | `billing.view` | The invoice's documents and figures (below). |
| POST | `/api/v1/invoices/{invoice}/credit-notes` | `billing.manage` or `billing.refund` | `{amount, reason}` |
| POST | `/api/v1/invoices/{invoice}/refunds` | `billing.manage` or `billing.refund` | `{amount, method, reason, reference?, card_uuid?}`; `method` is `cash`, `mobile_money`, `bank` or `card`; a reference is required for mobile money and bank, a card for `card`. |
| POST | `/api/v1/credits/{credit}/approve` | `billing.refund` | 422 if already decided or the limits no longer allow it. |
| POST | `/api/v1/credits/{credit}/reject` | `billing.refund` | `{note}` |
| GET | `/api/v1/credits/{credit}/pdf` | `billing.view` | `application/pdf`, inline. Approved documents only (404 otherwise). |

`{invoice}` and `{credit}` are uuids. Another hospital's record is a 404.

A request by someone holding `billing.refund` comes back approved; anyone else's
comes back `pending`. A limit broken is a 422 `validation_failed` with the
reason in `message` and `errors.amount`.

## Shapes

Credit (`InvoiceCreditResource`):

```json
{
  "uuid": "…", "kind": "refund", "kind_label": "Refund", "number": "RF-2026-00001",
  "status": "approved", "status_label": "Approved",
  "amount": "20000.00", "amount_label": "USh 20,000", "tax_part": "0.00",
  "method": "cash", "method_label": "Cash", "card": null,
  "reference": null, "reason": "Cash back for the scan.",
  "invoice": {"uuid": "…", "invoice_no": "INV-2026-00012"},
  "patient": {"uuid": "…", "name": "…", "patient_no": "…"},
  "requested_by": "Ruth Namubiru", "requested_at": "2026-10-17T09:12:00+03:00",
  "decided_by": "Agnes Nansubuga", "decided_at": "2026-10-17T09:30:00+03:00",
  "decision_note": null, "can_approve": false
}
```

`number` is null until approved. `card` is `{uuid, masked}` for a refund put
back on a prepaid card (`"•••• 1234"`), else null.

`GET credits/{credit}` returns the document, its invoice (`InvoiceResource`
with `items`: `description, quantity, unit_price, line_total`), the invoice's
figures (as below) and whether this person may decide it:

```json
{
  "credit": { … },
  "invoice": {"uuid": "…", "invoice_no": "…", "patient": {…}, "total": "50000.00",
              "amount_paid": "50000.00", "credited": "0.00", "refunded": "0.00",
              "balance": "0.00", "status": "paid", "items": [ … ], "issued_at": "…"},
  "figures": { … },
  "can_approve": true
}
```

`invoice` and `figures` are null only if the invoice is gone. `GET invoices/{invoice}/credits`
and both POSTs on an invoice return:

```json
{
  "credit": { … },                       // POSTs only: the document just made
  "invoice": {"uuid": "…", "invoice_no": "…", "status": "paid"},
  "figures": {
    "total": "50000.00", "credited": "20000.00", "refunded": "20000.00",
    "net": "30000.00", "held": "30000.00", "balance": "0.00",
    "refundable": "0.00", "creditable": "30000.00",
    "pending_credit": "0.00", "pending_refund": "0.00", "can_refund": "0.00"
  },
  "can_request": true, "can_approve": true,
  "refund_methods": ["cash", "mobile_money", "bank", "card"],
  "refund_method_options": [
    {"value": "cash", "label": "Cash", "needs_reference": false},
    {"value": "mobile_money", "label": "Mobile money", "needs_reference": true},
    {"value": "bank", "label": "Bank transfer", "needs_reference": true},
    {"value": "card", "label": "Prepaid card", "needs_reference": false}
  ],
  "cards": [{"uuid": "…", "masked": "•••• 1234", "balance": "15000.00"}],
  "credits": [ … ]
}
```

`refund_method_options` are the refund form's choices with their words;
`cards` are the patient's own prepaid cards, as the web's refund dialog
lists them (empty for someone who may not ask).

`creditable` and `can_refund` are what may still be asked for, counting
requests already waiting. The invoice resource (`GET invoices/{invoice}` and
everywhere it appears) adds `credited`, `refunded` and `refundable`.

The cashier's day summary (`GET cashier/day-summary`) adds
`refunds{count,total,by_method[{method,amount}]}`, `credit_notes{count,total}`
and `net` (received less refunded).
