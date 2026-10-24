# Procurement

Buying stock: suppliers, purchase orders, goods received with batch and expiry, and returns to the supplier (roadmap 04 §7). Pharmacy → Purchase orders and Pharmacy → Suppliers on the web and in the app (the same menu entries open native screens); `docs/api/procurement.md` for the API.

## Who

Read with `pharmacy.view`, act with `pharmacy.manage`: the people who already receive stock by hand. No new permission.

## The order

`PurchaseOrderStatus` is the state machine, enforced in `ProcurementService`:

```
draft ─► ordered ─► part received ─► received
  │         │             └────────► closed (the rest is not coming)
  └─────────┴─► cancelled (only while nothing has been received)
```

- A draft collects lines (item, quantity, unit cost, default the item's cost). Any active item on the stock list can be ordered, in stock or not: what has run out is what gets ordered. The same item twice is one line.
- "Mark as ordered" needs at least one line. From then on the lines are the supplier's copy and do not change.
- Numbers come from `Sequence`: `PO-2026-00001`, `GRN-2026-00001`, `SR-2026-00001`.
- The PDF (`admin.purchase-orders.pdf`, also `api.purchase-orders.pdf`) is plain: letterhead, supplier, lines, total, and a line asking the supplier to quote the PO number and print batch and expiry.

## Goods received

A delivery (GRN) is received against an ordered or part-received order, line by line: how many came, the cost on the supplier's invoice (default the ordered cost), batch and expiry, and the supplier's invoice or delivery-note number. The order is locked while it is received; nothing beyond what is still to come is accepted. The order becomes part received or received by itself.

Each line goes on the shelf through `StockService::receive` (the only writer of stock), with the delivery line as the movement's source. So:

- the stock ledger says which delivery every unit came on;
- the invoice cost becomes the item's cost;
- the books post **Dr inventory, Cr accounts payable** (or the book chosen in finance settings) with the supplier's name as the entry's party and `GRN no / supplier invoice no` as its reference (`Bookkeeper::stockMoved`, through `NamesALedgerParty`);
- the item's batch and expiry: a stock item carries one, and the alerts read it, so the shelf keeps the **earliest** expiry. A delivery onto an empty shelf takes over; onto stock already there it takes over only if it expires sooner. Every delivery line keeps its own batch and expiry.

Receipts are append-only. A mistake is put right with a return, not an edit.

## Returns to supplier

From a delivery line: quantity and a reason. Capped at what that line brought in less what has already gone back, and at what is on the shelf. Posted through `StockService::returnToSupplier` at **the delivery's cost**, so the payable falls by exactly what that delivery added (**Dr accounts payable, Cr inventory**, supplier as the party). Append-only.

## What is not here yet

Paying a supplier is recorded in Finance as money out against Accounts payable (with the supplier as the party), as before; there is no supplier statement or ageing yet.

## Demo

`Database\Seeders\DemoScenarios\Procurement::run($hospital)`: two suppliers, a delivered order with a damaged-pack return, a part-delivered order, an order due next week and a draft.
