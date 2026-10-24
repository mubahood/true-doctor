# Orders and the bill at the counter

The web's order dialog and the visit's **Bill** section for the app (docs/orders.md, "Managing one order" and "The bill does not edit its own lines"). Every endpoint calls the service method the web panel calls, with the same permissions; every write is safe to resend with the same `Idempotency-Key`, and the money and stock writes below **require** one from the app (`IdempotentRequests::KEY_REQUIRED_ROUTES`).

| Method | Path | Needs | Service |
|---|---|---|---|
| PATCH | `/api/v1/orders/{order}/items/{id}` | `visits.create` or `visits.manage` | `OrderService::updateItem` (web: `OrderDetail::saveItem`) |
| GET | `/api/v1/visits/{visit}/bill` | `viewBill` | `BillingService::totalsFor` |
| POST | `/api/v1/visits/{visit}/bill/lines` | `viewBill` and `billing.manage` | `BillingService::addCharge` (web: `Charges::addLine`) |
| PUT | `/api/v1/visits/{visit}/bill/discount` | `viewBill`, `billing.manage` and `billing.discount` | `BillingService::applyDiscount` (web: `Charges::saveDiscount`) |
| POST | `/api/v1/visits/{visit}/bill/rebill` | `viewBill` and `billing.manage` | `BillingService::recompute` (web: `Charges::rebill`) |

`viewBill` is the panel's own rule (`VisitPolicy::viewBill`): whoever has `billing.view` reads every bill; somebody with only `billing.manage` (a doctor, by default) reads the bills of their own patients. Another hospital's visit or order is **404**. Errors use the usual envelope; a refusal by the service is **422** with its sentence, beside the field it is about where there is one.

A charge is changed **on the order that raised it**, never on the bill: each bill line names its `order`, and its quantity, its Billed or Not billed, and taking it off go through the order's endpoints (this `PATCH`, `PUT /orders/{uuid}/items/{id}/billed`, `DELETE /orders/{uuid}/items/{id}`), with the order dialog's rule (`changeable` below).

## Correct a line in place

`PATCH /orders/{order uuid}/items/{id}`, any of:

```json
{ "quantity": "2", "notes": "Two wounds", "unit_price": "65000" }
```

- `quantity`: more than zero, at most two decimals. On a product it is how much left the shelf: raising it takes more (refused, by `quantity`, when the shelf does not hold it), lowering it puts the difference back, in the same transaction as the money.
- `notes`: up to 255; `null` or empty clears it.
- `unit_price`: only a service priced as a range moves, and only inside its range (the line's `price_range`). A fixed price, a product, or a charge another record placed (a lab request, a dental plan item) is refused by `unit_price`: "Dressing has a fixed price, so its price cannot be changed here." Sending the price it already has is no change.
- A field left out keeps what the line has.

Answers the order (as `GET /orders/{uuid}`, without `reversal`) and "Line updated.". **422** with the reason, and no field, once the order is cancelled or the visit invoiced, or for a removed line ("That line was removed, so it cannot be changed.").

## What an order's answers now carry

Every answer of `GET /orders/{uuid}` and the order writes (`PATCH`, `move`, `cancel`, `report`, `items`) adds:

```json
{
  "labels": { "kind": "Treatment", "title": "Service", "assignee": "Dentist assigned" },
  "asks_teeth": true,
  "products_billed": false,
  "charge_swappable": true,
  "items": [
    { "id": 12, "name": "Scaling", "kind": "service", "service_id": 4, "stock_item_id": null,
      "quantity": "1.00", "unit_price": "65000.00", "line_total": "65000.00", "status": "ordered",
      "notes": null, "billed": true, "billing_set_at": null,
      "price_range": { "from": "50000.00", "to": "80000.00" },
      "ordered_by": "Dr Okello", "ordered_at": "2026-10-24T09:10:00+03:00",
      "changed_by": "Dr Okello", "changed_at": "2026-10-24T09:30:00+03:00" }
  ]
}
```

- `labels`: what this practice calls the order form's fields (`OrderDesk::wording`): a dental clinic's work is a **Treatment** and its clinician the **Dentist assigned**; elsewhere **Type** and **Clinician assigned**. The title field is **Service**.
- `asks_teeth`: whether a procedure here names the teeth it is on (`DentalService::teethOnOrders`); the app asks only on a `procedure`.
- `products_billed`: where a product added now starts, Billed or Not billed (Billing settings, `HospitalSettings::productsBilledByDefault`; a dental clinic starts at Not billed until it is set).
- `charge_swappable`: whether Edit order may swap the order's one charge for another service (`service_id`, `quantity`, `unit_price` on `PATCH /orders/{uuid}`): not placed by another record, not invoiced, one service line at most and no product. Otherwise the charge is corrected line by line.
- Per line: `service_id` / `stock_item_id`; `price_range` (null for a fixed price, a product, or a charge another record placed); who put it on and who last changed it (`changed_by` null when nobody has).

`GET /visits/{uuid}/orders` and `GET /orders/catalogue` add `labels`, `asks_teeth` and `products_billed` beside what they had (the list's lines leave out `price_range` and the names). `GET /orders/lines?kind=service` adds `max_price` (null for a fixed price), and is open to `billing.manage` as well, for the bill's Add charge.

## The bill

`GET /visits/{uuid}/bill`:

```json
{
  "lines": [
    { "id": 31, "name": "Composite resin", "kind": "product", "quantity": "2.00", "unit_price": "0.00", "line_total": "0.00",
      "billed": false, "tax_exempt": false, "notes": null, "status": "ordered",
      "order": { "uuid": "7f1…", "title": "Filling", "type": "procedure", "status": "pending" },
      "changeable": true },
    { "id": 30, "name": "Filling", "kind": "service", "quantity": "1.00", "unit_price": "40000.00", "line_total": "40000.00",
      "billed": true, "tax_exempt": false, "notes": null, "status": "ordered",
      "order": { "uuid": "7f1…", "title": "Filling", "type": "procedure", "status": "pending" },
      "changeable": true }
  ],
  "removed_lines": [ { "id": 32, "name": "X-ray", "…": "…", "status": "cancelled", "changeable": false } ],
  "totals": { "subtotal": "40000.00", "taxable": "37000.00", "tax": "0.00", "discount": "3000.00", "total": "40000.00", "due": "37000.00", "currency": "UGX" },
  "discount": { "type": "amount", "value": "3000.00", "reason": "Staff rate", "by": "Grace Atim", "amount": "3000.00" },
  "tax_label": "VAT",
  "products_billed": false,
  "invoice": null,
  "unbilled": "0.00",
  "can_add": true, "can_discount": true, "can_rebill": true,
  "can_invoice": true, "can_pay": false,
  "methods": [ { "value": "cash", "needs_reference": false } ],
  "cards": []
}
```

- `lines` are the standing lines, newest first as the panel lists them; a removed line is never among them (it is in `removed_lines`, for the trail). A product given **Not billed** stands at `"0.00"` with `"billed": false`: it left the shelf and is not on the bill.
- `changeable`: whether this reader may change the line on its order: `visits.create` or `visits.manage`, the order not cancelled, and nothing invoiced (the order dialog's rule).
- `discount`: as agreed at the counter. A percentage keeps its rule (`value` "25.00" with `type` "percent"); `amount` is what it is worth against the bill now, capped at the bill.
- `can_add`, `can_discount`, `can_rebill`: `billing.manage` (and `billing.discount`) while nothing is invoiced. Once invoiced the bill is fixed.
- Every amount is a two-decimal string.

## Add a charge

`POST /visits/{uuid}/bill/lines`:

```json
{ "kind": "service", "service_id": 4, "quantity": "1", "unit_price": "65000", "note": "Upper and lower" }
{ "kind": "product", "stock_item_id": 41, "quantity": "2", "billed": false }
```

- `kind`: `service` (off the price list, active) or `product` (off the shelf, active), each checked inside this hospital: "Choose a service." / "Choose a product." / "That service is not on the price list.".
- `quantity`: more than zero, at most two decimals.
- `unit_price`: required for a service priced as a range, inside it ("Say what to charge within the range." / "Charge between USh50,000 and USh80,000."); ignored otherwise.
- `billed`: a product only; left out, the hospital's choice (`products_billed`).
- `note`: optional, up to 255.

It raises an order of its own, completed (a consultation for a service, a pharmacy order for a product), so the charge has a piece of work behind it; a product leaves the shelf in the same transaction, and more than the shelf holds is refused by `stock_item_id` with nothing billed. Answers **201** with the bill and "Added to the bill.". **422** "This visit has been invoiced, so its charges can no longer be changed." once there is an invoice (the service asks again under the visit's lock, the lock invoicing takes).

## The discount

`PUT /visits/{uuid}/bill/discount`:

```json
{ "type": "percent", "value": "25", "reason": "Staff rate" }
```

`type` `amount` or `percent`; `value` at least 0, at most two decimals, `null` or 0 clears it (and its reason and name); `reason` up to 255. Whoever sends it is recorded against it. Refused by `value`: more than the bill ("A discount cannot be more than the bill — USh8,000."), more than 100%, or once invoiced. Answers the bill and "Discount saved.". Raising the invoice carries it across.

## Putting the bill back in step

`POST /visits/{uuid}/bill/rebill` recalculates every standing line from its own unit price and quantity. Answers the bill with `rebill: { checked, fixed, lines }` and either "2 line(s) were out of step and have been put right." or "The bill is in step — 3 line(s) checked.". **422** once invoiced.

## Dispensing

`GET /visits/{uuid}/dispensations` adds `meta.products_billed` (where a new line of the dispense form starts, as the web's Dispense panel) and, per dispensed line, `billed` (true, false, or null when it cannot be paired with its charge). `POST /visits/{uuid}/dispensations` already takes `billed` per line.

## Older servers

A hospital's own server that predates these answers 404 "The route … could not be found." for the new paths and sends none of the new fields; the app then leaves the actions out and the billed choice off, and the server's default applies.
