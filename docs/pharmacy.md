# Pharmacy / inventory (Phase 2, Step 12)

Stock management rebuilt around an **append-only movement ledger** and one writer
(`StockService`) — replacing the legacy's model boot-hook quantity recalculation. Tenant-scoped
via `BelongsToHospital`. This is **Step 12a** (inventory engine); dispensing against
prescriptions ships in **12b**.

## Files (12a)

| Concern | File |
|---|---|
| Tables | `2026_07_19_190000_create_stock_categories_table.php`, `…_190010_create_stock_items_table.php`, `…_190020_create_stock_movements_table.php` |
| Models | `app/Models/{StockCategory,StockItem,StockMovement}.php` |
| Enum | `app/Enums/StockMovementReason.php` |
| Service | `app/Services/StockService.php` |
| Exception | `app/Exceptions/InsufficientStockException.php` |
| Requests | `app/Http/Requests/{StockCategory,StockItem,StockReceive,StockAdjust}Request.php` |
| Policies | `app/Policies/{StockItem,StockCategory}Policy.php` |
| Controllers | `app/Http/Controllers/Admin/{StockItem,StockCategory,StockAlert}Controller.php` |
| Views | `resources/views/admin/{stock,stock-categories}/…` |

## The ledger & StockService

`StockService` is the **only** thing that changes a quantity. Every movement:

1. runs in a transaction with a `lockForUpdate` read on the item (no lost updates on
   concurrent receive/dispense/adjust),
2. applies the movement using `StockMovementReason::sign()` (+1 in / −1 out) — the single
   source of truth for direction,
3. **rejects any outgoing movement that would drive stock negative** (`InsufficientStockException`),
4. re-derives `current_stock_value = current_quantity × cost_price`, and
5. appends an **immutable** `stock_movements` row with a `balance_after` snapshot.

All quantity/value math is **bcmath scale-2**. A `receive` with a unit cost updates the item's
`cost_price` before revaluing. The static item form (`StockItemRequest`) can never touch
`current_quantity`/`current_stock_value` — those move only through the ledger.

## Reasons

`opening_stock`, `received`, `returned`, `adjustment_in` (in) · `dispensed`, `wastage`,
`adjustment_out` (out). Receive is a purchase-in; adjust covers wastage/returns/corrections.
Dispensing (`dispensed`) is driven by 12b.

## Delete-protection

An item (or category) with history can't be hard-deleted — a stock item with any movement is
audit evidence, so `destroy` refuses and the item is **deactivated** instead. A category with
items refuses deletion until they're reassigned.

## Alerts

`/admin/stock/alerts` (`StockAlertController`) is the inventory alerts board — **low-stock**
(`current_quantity <= reorder_level`, the `lowStock` scope) and **near-expiry** (`expiringBefore`,
90-day horizon). A real, queryable surface (the legacy's empty `InventoryAlerts` widget, built);
push/email channels can subscribe to the same scopes later.

## RBAC

`pharmacy.view` · `pharmacy.manage` (items, categories, receive/adjust) — held by pharmacist
and hospital admin.

## Tests (12a)

`StockServiceTest` (receive revalues, dispense decreases, **can't go negative**, append-only
running balance, wastage adjust, low-stock/expiry scopes); `StockHttpTest` (create+receive,
adjust-negative blocked, **delete-protection**, role gating, tenant isolation, alerts board).

---

## Step 12b — dispensing against a visit

### Files (12b)

| Concern | File |
|---|---|
| Tables | `2026_07_19_190030_create_dispensations_table.php`, `…_190040_create_dispensation_items_table.php` |
| Models | `app/Models/{Dispensation,DispensationItem}.php` |
| Service | `app/Services/DispensationService.php` |
| Request | `app/Http/Requests/DispensationRequest.php` |
| Controller | `app/Http/Controllers/Admin/DispensationController.php` |
| Views | dispense section on the visit page + `resources/views/admin/dispensations/show.blade.php` |

`DispensationService::dispense(visit, lines, …)` runs in **one transaction** that, per
line: snapshots the drug's `sale_price`, creates a `dispensation_item`, **deducts stock**
through `StockService::dispenseOut` (append-only movement, negative-stock guard), and **adds a
billable line** to the visit through `BillingService::addCustomLine` (quantity folded
into the description so a fractional dispense still bills its exact line total). Because stock
deduction and billing share the transaction, a shortfall on any line
(`InsufficientStockException`) rolls back the whole dispensation — no partial stock movement,
no orphan charge. The dispensed drugs then flow onto the invoice at Step-11 invoice generation.

RBAC: `pharmacy.dispense` (pharmacist, hospital admin); the pharmacist also gained
`visits.view` so they can open the visit they dispense against.

### Tests (12b)

`DispensationServiceTest` (deducts stock + bills the drug; **insufficient stock rolls the whole
dispensation back** — no dispensation, untouched stock, no bill lines); `DispensationHttpTest`
(pharmacist dispenses→deduct+bill, role gating, cross-hospital stock rejected).

## Stock categories show what is on the shelf

The page listed a name, a unit and a count of items, which says a category
exists and nothing about whether the store behind it is in trouble. A
storekeeper opens it to find out **what is running out**, and that was the one
question it could not answer — every answer meant opening the stock list and
reading it by eye.

Each row now carries: how many items, how much is **in store**, how many are
**running out**, how many are **expiring** within the alerts page's horizon, and
what the shelf is **worth**. The counts are selected in the query, not counted
per row — a page of twenty categories would otherwise be sixty extra round
trips — which also makes them sortable columns.

Every count links to the items behind it (`?category=…`, plus `&filter=low` or
`&filter=expiring`), because a count nobody can open is a number rather than an
answer. The stock list gained that `category` filter for the purpose.

**One definition of "running out".** `StockItem::applyLowStock()` and
`applyExpiringBefore()` hold the rules as plain conditions; the model's scopes
are those, and so is every `withCount` closure on this page. A count that used
its own threshold would let the alerts page and the categories page disagree
about which items are in trouble, which is worse than either being wrong alone.
`EXPIRY_HORIZON_DAYS` is read off `Stock\Alerts` rather than repeated, and a
test asserts the two match.

An **inactive** item still counts towards the category's item total — it is
still filed there — but towards nothing else: a retired line is not running out,
not expiring, and not stock on hand.

The form suggests the units a store actually uses, so "tabs", "tab" and
"Tablet" do not become three, and while editing it says what the category
already holds — renaming one that carries four hundred items is a different act
from renaming an empty one, and deleting is refused outright once it holds
anything.

## Accountability: the ledger was already there

Every movement on or off a shelf is a `StockMovement` — append-only, with the
reason, the quantity, **the balance after it**, a note, who did it, and a
polymorphic link to the work that caused it. `StockService` is the only writer:
it takes a row lock, refuses to drive a shelf negative, and updates the balance
and the stock value in the same transaction. Dispensing, returns and order
corrections all post to it.

So separate "stock out records" would have been a second, weaker copy of
something the system already had. What was missing was not the record — it was
being able to READ it across the store, and being able to ACT from the screen
where you notice a problem.

### The store's record book

`/admin/stock/movements` shows every movement: what moved, which way (colour,
not a word to decode), how much, what the shelf stood at afterwards, who, and
why. Filter by item, direction, reason or period; the totals in and out are of
the **filtered** set, because "1,200 out" means nothing without knowing out of
what and when. Nothing on it can be edited — a ledger that can be rewritten is
not one, and a test asserts the page offers no way.

### Acting where the problem is

`App\Livewire\Concerns\MovesStock` puts receive, adjust and **write off** on
the stock list and on the alerts board, held once so three screens cannot come
to disagree about what a write-off is.

A write-off is an adjustment whose reason is already decided (`Wastage`), and it
**requires a reason in words** — "wastage, 400 tablets" with no explanation is
the entry an auditor stops at. It opens with the whole remaining quantity,
because writing off half a shelf of expired stock and leaving the rest on the
books is the mistake it exists to stop, and suggests the usual reasons
(expired, damaged, contaminated, recalled, lost in a count).

### The alerts board: four problems, worst first

They are four different problems, and one list called "alerts" made the reader
work out which was which:

1. **Out of stock** — nothing left. `lowStock()` is `quantity <= reorder_level`,
   which put an item at ZERO beside one that had merely dipped below the line.
   Zero is not a warning about next week; it cannot be dispensed at all.
2. **Already expired** — a loss taken, still counted in what the hospital says
   it holds.
3. **Running out** — below the reorder level, with **how much would put it
   right and what that would cost**: the two things somebody raising an order
   needs and had to work out in their head.
4. **Expiring soon** — still usable, use it first. Banded at
   `EXPIRY_URGENT_DAYS`, because "in six days" and "in eighty-eight days" are
   not the same instruction, and every row says how long is left rather than
   only when.

Two of the four headline figures are **money** — what the expired stock is
worth, and what restocking every shortage would cost. Counts alone never made
anybody act.

All four lanes are one partial (`stock/partials/alert-lane`), so a reader learns
the table once and the difference between lanes is the words at the top.

### The expiry sweep

A month's expiries is a sweep, not eleven separate decisions: pick several
lines (or all of them) and write them off under one reason. Every line still
becomes its **own** ledger movement with its own quantity and its own balance —
only the asking is batched, because a single lumped row would be untraceable.
Each is posted on its own, so one failing (something dispensed while the dialog
was open) does not take the others with it, and the toast names the ones that
could not go.

### The old two-lane note



**Already expired** is now its own lane, above the rest: stock to destroy is a
different problem from stock to order, and one list sorted by date buried the
second among the first. It carries **what the expired stock is worth**, which is
the figure that makes anybody act, and a Write off button on the row. The
running-out lane shows **how short** each item is — what somebody ordering
actually needs and had to work out in their head.

## What it cost, and what it sold for

The ledger recorded `unit_cost`, and only on the way IN. So the two questions a
store is actually judged on could not be answered from it: an item repriced last
week made every dispensation before it look as though it had been sold at
today's price.

**Both prices are now stamped on every row** as they stood at that moment
(`stock_movements.unit_price` alongside `unit_cost`), by `StockService::post()`,
for incoming and outgoing alike. A repricing today cannot rewrite what last
month looked like — `StockLedgerTest` pins exactly that.

Rows written before this are **not** backfilled. The honest answer for them is
"not recorded"; a report quietly filled in with today's prices is worse than one
that says what it does not know, so they are excluded from the money figures and
the page says how many.

### Receiving asks what the invoice says

A supplier's note says "500 at 60,000" — not "120 each". Either box fills the
other in, so nobody divides before they can type. The selling price is asked for
in the same breath, because without it nothing that leaves the shelf can be
priced, and a **margin preview** runs under both: a price entered the wrong way
round is invisible between two boxes and obvious the moment that figure goes red.

### A loss says what happened to it

`StockMovementReason` gained the reasons a store actually loses things —
`Expired`, `Damaged`, `Lost`, `ReturnedToSupplier` — because "adjustment (out),
400 tablets" is a number nobody can act on, while expired is a shelf-life
problem, damaged is a handling problem and stolen is a security problem, and a
store that files all three as "wastage" cannot fix any of them. A write-off now
picks from those (defaulting to Expired when the item's own date says so) AND
still requires a note.

A loss is valued at **cost** — what the hospital paid for something it never
sold. Valuing it at the selling price would book a profit nobody made, so
`margin()` returns null for anything that was not dispensed.

The ledger totals **bought · sold · profit on what sold · lost**, over the rows
currently filtered, and each row shows what it was worth at cost and what it
made or lost.
