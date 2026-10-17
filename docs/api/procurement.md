# Procurement API

Suppliers, purchase orders, goods received and returns to supplier. The same rules as the web (`docs/procurement.md`), through `ProcurementService`. All under `/api/v1`, authenticated. Read with `pharmacy.view`; everything that writes needs `pharmacy.manage`.

Suppliers, orders, receipts and returns are addressed by **uuid**. An order's lines and a delivery's lines are addressed by their integer `id`, always inside the order they belong to.

| Method | Path | Body / query | Answer |
|---|---|---|---|
| GET | `suppliers` | `?q=&status=active\|inactive&per_page=` | a page of suppliers |
| POST | `suppliers` | `name`, `contact_person?`, `phone?`, `email?`, `address?`, `tin?`, `payment_terms_days?`, `notes?`, `is_active?` | 201: the supplier (an archived twin with the same name is restored) |
| GET | `suppliers/{uuid}` | | the supplier plus `figures` {`orders`, `open`, `received`, `returned`} |
| PUT/PATCH | `suppliers/{uuid}` | any of the fields above | the supplier; only the fields sent change |
| GET | `purchase-orders` | `?status=` (`open` = ordered or part received, or one status) `&supplier_uuid=&q=&per_page=` | a page of order summaries, newest first |
| POST | `purchase-orders` | `supplier_uuid`, `expected_date?`, `notes?`, `lines[]?`: `stock_item_id`, `quantity`, `unit_cost?` (default the item's cost) | 201: the order, a **draft** |
| GET | `purchase-orders/{uuid}` | | the order in full |
| POST | `purchase-orders/{uuid}/lines` | `stock_item_id`, `quantity`, `unit_cost?` | 201: the order. Drafts only; the same item again adds to its line |
| DELETE | `purchase-orders/{uuid}/lines/{id}` | | the order. Drafts only |
| POST | `purchase-orders/{uuid}/order` | | the order, now `ordered` (needs at least one line) |
| POST | `purchase-orders/{uuid}/receive` | `received_on?` (not in the future), `supplier_invoice_no?`, `notes?`, `lines[]`: `purchase_order_item_id`, `quantity` (0 skips the line), `unit_cost?` (default the ordered cost), `batch_no?`, `expiry_date?` | 201: `receipt`, `order`. **Idempotency-Key** required from the app |
| POST | `purchase-orders/{uuid}/returns` | `goods_receipt_item_id`, `quantity`, `reason` | 201: `return`, `order`. **Idempotency-Key** required from the app |
| POST | `purchase-orders/{uuid}/close` | `reason` | the order, `closed` (part received only) |
| POST | `purchase-orders/{uuid}/cancel` | `reason` | the order, `cancelled` (draft or ordered with nothing received) |
| GET | `purchase-orders/{uuid}/pdf` | | the order as a plain PDF, inline |

**A summary:** `uuid`, `po_no`, `status`, `status_label`, `supplier` {`uuid`, `name`}, `order_date`, `expected_date`, `total`, `total_label`, `lines_count`, `updated_at`.

**The full order** adds `notes`, `ordered_at`, `ended_at`, `end_reason`, `figures` {`ordered`, `received`, `outstanding`, `returned`, each with a `*_label`}, `can` {`edit_lines`, `order`, `receive`, `close`, `cancel`}, `lines[]` {`id`, `stock_item_id`, `stock_item_uuid`, `name`, `unit`, `quantity`, `received_quantity`, `outstanding`, `unit_cost`, `line_total`, `line_total_label`}, `receipts[]` and `pdf_url`.

**A receipt:** `uuid`, `grn_no`, `received_on`, `supplier_invoice_no`, `total`, `total_label`, `notes`, `lines[]` {`id`, `purchase_order_item_id`, `name`, `quantity`, `unit_cost`, `line_total`, `batch_no`, `expiry_date`, `returned_quantity`, `returns[]`}.

**A return:** `uuid`, `return_no`, `quantity`, `unit_cost`, `total`, `total_label`, `reason`, `created_at`.

Statuses (`/meta` enum `purchase_order_status`): `draft`, `ordered`, `part_received`, `received`, `closed`, `cancelled`.

Errors: 422 in the standard envelope with the field (`lines.0.quantity` when more is received than is still to come, `quantity` for a return beyond what that delivery brought in or what is on the shelf, `status` for a move the order's state does not allow, `name` for a supplier name already used in this hospital); 403 without the permission; 404 for another hospital's record.

Not offline: receiving and returning move stock and post the books, so they need the server, like dispensing.
