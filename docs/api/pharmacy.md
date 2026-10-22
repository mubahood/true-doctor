# API: pharmacy and catalogues

Base `/api/v1`, Sanctum bearer token, the standard envelope (`success`, `code`, `message`,
`data`, `errors`; lists add `meta`). Routes live in `routes/api/pharmacy.php`; tests in
`tests/Feature/Api/PharmacyBackOfficeApiTest.php`. Authorisation is the web page's:
the same Policies, so a role that cannot open the page gets 403 here. Tenancy is by global
scope: another hospital's rows are 404. Validation is the web form's own `rulesFor()`
(`StockItemRequest`, `ServiceRequest`, `LabTestRequest`, `RadiologyStudyRequest`,
`StockCategoryRequest`); failures are 422 `validation_failed` with `errors.<field>`.
Money and quantities are decimal strings (`"1200.50"`).

**The store's name.** Lists and boards carry `module_label`: what this practice calls the
pharmacy ("Pharmacy", or "Drugs inventory" for a dental clinic), from `Practice::moduleName`.

Shared by the logic with the web (extracted, so the two cannot drift):
`StockAlertBoard` (alerts lanes and the sweep), `StockLedger` (ledger filters and totals),
`CatalogueStore` (saving a catalogue row, restoring an archived twin, usage figures),
`StockItem::deletionBlocker()` / `StockCategory::deletionBlocker()`.

## Writes (all catalogues and stock items)

* `POST` creates (201). Sending the name of an archived row restores it
  (201, message "... restored from the archive.") exactly as the web does.
* `PUT` / `PATCH` update (200). Send only what changes; the rest is kept. Leaving out
  `is_active` does not deactivate. `""` on an optional text field clears it to `null`.
* `DELETE` archives (soft delete, 200, `data: null`). A refusal is 422 with the reason in `message`.
* Unknown fields are ignored. A stock item's quantity can never be set here.

## Stock alerts

`GET /stock-alerts` - `pharmacy.view`. Query: `category` (stock category id), `lane`
(`out|expired|low|expiring`, default all four). Inactive items are excluded; thresholds are
the web's: out = quantity <= 0; low = quantity > 0 and <= reorder level; expired = quantity > 0
and expiry date today or earlier; expiring = expiry within 90 days, not yet expired.

```json
{ "data": {
    "summary": {"out":1,"low":1,"expiring":1,"expired":1,"value":"6.00","shortfall":"30.00"},
    "lanes": { "out": [Row], "expired": [Row], "low": [Row], "expiring": [Row] },
    "horizon_days": 90, "urgent_days": 30,
    "sweep_reasons": ["expired","damaged","lost","wastage"],
    "categories": [{"id":1,"name":"Medicines"}],
    "module_label": "Pharmacy" } }
```
`Row` = the stock item (see below) plus `days_left` (negative once expired, `null` without an
expiry), `expiry_tone` (`"warn"` within 30 days, else `""`), `short_by` (to reach the reorder
level) and `shortfall_value` (`short_by` x cost). `summary.value` is what the expired stock is
worth; `summary.shortfall` what it costs to put shortages right.

`POST /stock-alerts/sweep` - `pharmacy.manage`. Write off several expired lines under one
reason; each becomes its own ledger movement, and one failing does not stop the rest.
Body: `items` (array of stock item uuids, must all be currently expired), `reason`
(one of `sweep_reasons`), `note` (3-255 chars, required). Response `data`:
`{"done": 2, "refused": ["Item name"]}`. 422 for a missing note, a non-loss reason, or a line
that is not expired.

## Stock ledger

`GET /stock-movements` - `pharmacy.view`. Paginated (`per_page` max 100, newest first).
Query: `item` (stock item uuid), `direction` (`in|out`), `reason` (a `meta.reasons` key),
`from`, `to` (dates, inclusive), `q` (item name or note), `sort` (`created_at|quantity|reason`),
`dir` (`asc|desc`, default desc). A bad `direction`/`reason`/date is 422.

```json
{ "data": [ { "id": 41, "item": {"uuid":"...","name":"Gloves","unit":"box","category":"Supplies"},
    "reason": "damaged", "reason_label": "Damaged", "direction": "out",
    "quantity": "2.00", "balance_after": "18.00", "unit_cost": "3.00", "unit_price": null,
    "cost_value": "6.00", "sale_value": null, "margin": null, "note": "Dropped box",
    "by": "Pat Pharmacist", "source": null, "at": "2026-10-16T09:12:00+00:00" } ],
  "meta": { "current_page":1,"per_page":20,"total":3,"last_page":1,
    "totals": {"in":"30.00","out":"2.00","rows":3,"bought":"...","sold":"...","margin":"...","lost":"6.00","unpriced":0},
    "reasons": {"received":"Received", "...": "..."}, "module_label": "Pharmacy" } }
```
`totals` are over the filtered set. `source` is `{type, id}` when the movement came from a
piece of work (e.g. `Dispensation`).

## Stock items

Existing: `GET /stock-items` (`filter=low|expiring|expired`, `category`, `q`; meta now also has
`module_label`), `GET /stock-items/{uuid}`, `.../movements`, `.../receive`, `.../adjust`,
`.../write-off`. Resource gained `sku`, `is_active`, `category_id`.

* `POST /stock-items` - `pharmacy.manage`. Required: `name` (120), `unit` (32), `cost_price`,
  `reorder_level` (0..99999999.99). Optional: `sale_price` (empty or left out: it equals
  `cost_price`, stored as such), `stock_category_id` (this hospital's),
  `sku` (48), `batch_no` (64), `expiry_date`, `is_active` (default true). Returns the item (201).
  Opening stock goes in through `POST .../adjust` with reason `opening_stock`.
* `PUT|PATCH /stock-items/{uuid}` - `pharmacy.manage`. Same fields, all optional.
* `DELETE /stock-items/{uuid}` - `pharmacy.manage`. 422 "This item has stock movements and
  cannot be deleted - deactivate it instead." once it has any movement; deactivate with
  `PATCH {"is_active": false}`.

Item: `uuid, server_id, name, sku, is_active, category_id, unit, category, current_quantity,
cost_price, sale_price, current_stock_value, reorder_level, batch_no, expiry_date, is_low_stock, is_expired`.

## Catalogues

Lists are paginated, ordered by name, `?q=` searches the name (price list: name or code),
`per_page` max 100. `GET .../{id}` returns the row plus `usage` = `{times, earned, days: 90}`
(orders and earnings in the last 90 days, as the web's peek). Read needs the page's
`viewAny` permission, so the `show` is the same.

| Catalogue | Routes | Read | Write |
|---|---|---|---|
| Price list | `/services` | `access-admin` | `services.manage` |
| Lab tests | `/lab-tests` | `lab.view` | `lab.manage` |
| Radiology studies | `/radiology-studies` | `radiology.view` | `radiology.manage` |
| Stock categories | `/stock-categories` | `pharmacy.view` | `pharmacy.manage` |

Each has `GET` list, `POST`, `GET /{id}`, `PUT|PATCH /{id}`, `DELETE /{id}` (ids are integers).

**Price list** fields: `name` (unique per hospital, 120), `code` (32), `category` (60, the
specialty), `ledger_account_id` (one of this hospital's income accounts), `price` (>= 0),
`max_price`, `tax_exempt`, `is_active`. Price ranges: when `max_price` is set it must be
greater than `price` (422 on `max_price` otherwise) and the line is a range
("50,000 - 80,000"); the counter then charges an amount within it
(`Service::chargePrice`, used by the order and visit-charge endpoints). Clear a range with
`max_price: null`. Resource: `id, name, code, category, ledger_account_id, price, max_price,
has_range, price_label, tax_exempt, is_active`. List `meta` has `income_accounts [{id,name}]` and
`categories` (specialties in use) for the form pickers.

**Lab tests**: `name`, `code`, `specimen` (64), `unit` (32), `reference_range` (120), `price`, `is_active`.

**Radiology studies**: `name`, `modality` (32), `body_part` (64), `price`, `is_active`.

**Stock categories**: `name`, `unit` (32, required), `description` (255), `is_active`. List
query: `status=active|inactive`, `attention=1` (only shelves with something low or expiring),
`sort` (`name|items_count|on_hand|stock_value|low_count|is_active`), `dir`. Each row adds
`items_count, active_count, low_count, expiring_count, on_hand, stock_value`; `meta.store` has the
whole store's tally and `meta.module_label`. `GET /stock-categories/{id}` adds `items` (the 8
active lines with the least left). `DELETE` is refused with 422 "This category has stock items -
reassign them first." while it holds any item.

## Error codes

| Status | `code` | When |
|---|---|---|
| 401 | `unauthenticated` | no or bad token |
| 403 | `forbidden` | the page's permission is missing |
| 404 | `not_found` | no such row in this hospital |
| 422 | `validation_failed` | rules above, or a refused delete (reason in `message`) |

### Dispensing without billing

`POST /visits/{uuid}/dispensations`: each `items[]` row may carry `billed` (boolean). Not billed
still takes the stock off the shelf and posts its cost; the charge goes on with no amount and is
left off the invoice. Left out, the hospital's choice (Billing settings, `products_billed`).
