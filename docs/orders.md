# Orders

**An order is one thing to be done for a patient on a visit.** Seeing a
specialist, a blood test, a scan, drugs from the pharmacy, a procedure, an
admission — all of it is an order. An order carries what it is, who should do
it, what state it is in, and **what it cost**.

```
  Catalogue          what we offer and for how much
      ↓              services · lab_tests · radiology_studies · stock_items
  Order              one thing to do for this patient on this visit
      ↓              type · assigned to · status · subject
  Order items        what it actually used — THIS IS THE BILL
      ↓
  Invoice
```

A department's view of the orders assigned to it is a **worklist**. Same
records, filtered — not a second concept.

## Why we are doing this

Today the same idea is spelled five different ways, and the bill is stitched
together from strings.

`LabService::order()`, `RadiologyService::order()`,
`DispensationService::dispense()` and `AdmissionService` each independently call
`BillingService::addCustomLine($visit, "Lab: {$test->name}", …)`. That creates a
`medical_services` row hanging off the **visit**, with a name like
`"Lab: Malaria RDT"` and **no link back to the thing that caused it**.

Three consequences, all real today:

1. **Billing leaks.** `LabOrderStatus::Cancelled` exists, but cancelling an order
   cannot cancel its charge — nothing connects them. The charge stays on the
   bill, or has to be hunted down by name.
2. **Revenue cannot be attributed.** `ReportService::serviceRevenue()` groups by
   the **name string**. Rename a lab test and its history splits in two. Ask
   "what did the lab earn this month" and there is no honest answer — only
   `LIKE 'Lab: %'`.
3. **Nobody can be told what to do.** There is no list of outstanding work for a
   department, because the work is scattered across four tables that share no
   shape.

The fix is not to add a sixth table. It is to give the request layer one shape,
and to make the bill a **child of the work** rather than a sibling of it.

## The model

### Order

| Column | Why |
|---|---|
| `visit_id` | NOT NULL, cascade — everything belongs to a visit (`visits.md`) |
| `patient_id` | NOT NULL — the worklist filters by patient without a join |
| `type` | `consultation · lab · imaging · pharmacy · procedure · admission` |
| `status` | `pending · in_progress · completed · cancelled` |
| `title` | what it is, in words: "Chest X-ray", "See Dr Alice" |
| `assigned_to` | the person who should do it (nullable) |
| `department_id` | or the department it is waiting on (nullable) |
| `requested_by` | who raised it |
| `notes` | instructions / clinical notes |
| `subject_type`, `subject_id` | the specialist record, when there is one |
| `started_at`, `completed_at`, `cancelled_at`, `cancel_reason` | the trail |

`status` deliberately reuses the words `VisitStatus` already uses, so staff
learn one vocabulary and not two.

### Order item — this replaces `medical_services`

`medical_services` is already three-quarters of this: it snapshots name and unit
price, carries a quantity, a line total, a tax flag, a status and `ordered_by`.
It is re-parented and given a second catalogue source.

| Change | Why |
|---|---|
| `visit_id` → **`order_id`** | the bill becomes a child of the work that caused it |
| add `stock_item_id` | pharmacy bills through the same path as everything else |
| keep `service_id` | the priced catalogue entry, when there is one |
| keep name / unit_price / quantity / line_total / tax_exempt / status | unchanged — snapshots stay snapshots |

**Order items do not carry `visit_id`.** They reach a visit through their order,
exactly as `insurance_claims` already reach one through their invoice
(`visits.md`). The invariant is unchanged — nothing floats free — and
`EverythingBelongsToAVisitTest` gains this case rather than losing one.

### What stays specialist, and why

The order is the **request layer only**. Each type keeps its own record where it
has machinery that a generic table would silently destroy:

| Type | Specialist record | What would be lost |
|---|---|---|
| lab | `lab_orders` + items | results, reference ranges, flags |
| imaging | `radiology_orders` + items | findings, impression |
| pharmacy | `dispensations` | **stock decrement** — a generic table would let stock go negative |
| admission | `admissions` | **bed occupancy**, transfers, nights, discharge |
| consultation | `appointments` (when booked) | **slot overlap locking** — two patients, one doctor-minute |
| procedure | `treatment_records` | photos, technique |

The order points at that record through `subject_type` / `subject_id`, nullable
because "see Dr Alice today" needs no specialist record at all.

## What changes, file by file

### New

| File | What |
|---|---|
| `app/Enums/OrderType.php` | the six types, with label, icon and badge |
| `app/Enums/OrderStatus.php` | pending · in_progress · completed · cancelled, with `transitionsTo()` |
| `app/Models/Order.php` | `BelongsToHospital`, relations, `scopeWorklist()` |
| `app/Models/OrderItem.php` | the renamed `MedicalService` |
| `app/Services/OrderService.php` | `place()` · `assign()` · `start()` · `complete(items)` · `cancel(reason)` |
| `app/Http/Requests/OrderRequest.php` | `rulesFor()`, shared by Livewire and the API |
| `app/Livewire/Visits/Panels/Orders.php` | replaces `Panels\Charges` |
| `app/Livewire/Worklist/Index.php` | the department view |
| `database/migrations/*_create_orders_table.php` | |
| `database/migrations/*_medical_services_become_order_items.php` | rename + re-parent + backfill |

### Modified

| File | Change |
|---|---|
| `app/Services/BillingService.php` | `addServiceLine`/`orderService`/`addCustomLine`/`cancelLine`/`totalsFor`/`generateInvoice` read and write `order_items` through an order |
| `app/Services/LabService.php` | `order()` places an Order of type `lab`, attaches items to it, points `subject` at the `LabOrder` |
| `app/Services/RadiologyService.php` | same, type `imaging` |
| `app/Services/DispensationService.php` | same, type `pharmacy`; the item now carries `stock_item_id` |
| `app/Services/AdmissionService.php` | bed charges become items on an `admission` order |
| `app/Services/ReportService.php` | revenue groups by `service_id` / `stock_item_id` / `type`, not by name string |
| `app/Models/Visit.php` | `medicalServices()` → `orders()`, `orderItems()` through orders |
| `app/Enums/MedicalServiceStatus.php` | renamed `OrderItemStatus` |
| `app/Http/Requests/MedicalServiceOrderRequest.php` | folded into `OrderRequest` |
| `routes/web.php` | `admin/worklist` |
| `resources/views/partials/admin-nav.blade.php` | **Worklist** under Patient care |

### Frontend

| Screen | Change |
|---|---|
| Visit dialog (`livewire/visits/actions.blade.php`) | the `charges` section becomes **Orders** — a list of what has been ordered, each with type, assignee, status and its lines; the rail counts orders, not charges |
| `Panels\Orders` | shows the orders; "Add order" opens a dialog: type → what → assign to → notes. Completing one opens a dialog to attach what was used |
| Worklist (`admin/worklist`) | every pending / in-progress order, filterable by type, department and assignee; a row can be started, completed or cancelled in place |
| Visits list row menu | "Add order" replaces "Add charge" |
| Reports | revenue by department and by catalogue entry, not by string |

## Migration

One migration, guarded and resumable in the style of
`2026_09_16_000002_rename_consultations_to_visits`:

1. Create `orders`.
2. **Backfill**: every existing `medical_services` row is given an order. Rows
   created within the same minute on the same visit by the same user are grouped
   into one order, typed by their name prefix (`Lab: ` → `lab`, `Radiology: ` →
   `imaging`, a `stock_item` match → `pharmacy`, otherwise `consultation`), and
   marked `completed` — they are history.
3. Rename `medical_services` → `order_items`; add `order_id` (NOT NULL, cascade)
   and `stock_item_id`; drop `visit_id` **after** the backfill, never before.
4. Where a `lab_orders` / `radiology_orders` / `dispensations` / `admissions` row
   can be matched to the group, point the order's `subject` at it.

`down()` reverses it: re-add `visit_id` from `orders.visit_id`, rename back, drop
`orders`.

## Testing

Nothing merges until all of this is green.

**Backend**

- `OrderServiceTest` — place, assign, start, complete with items, cancel; illegal
  transitions refused; cancelling an order cancels its items so the bill drops.
  *This is the test that proves the leak is closed.*
- `BillingService` suite — totals, invoice generation and payment unchanged from
  the caller's point of view.
- `EverythingBelongsToAVisitTest` — `orders.visit_id` NOT NULL; `order_items`
  reach a visit through their order.
- Migration test — a database of existing `medical_services` rows migrates with
  **the invoice totals unchanged**. Money before must equal money after.
- `ReportServiceTest` — revenue attributed by catalogue id, and a renamed
  service no longer splits its own history.

**Frontend**

- `VisitActionsTest` — the Orders section renders, the rail counts orders,
  placing one from the dialog works, no write field sits open on the page.
- `WorklistTest` — each role sees only the orders it may act on; starting and
  completing from a row works; another hospital's order is a 404.
- `VisitJourneyTest` — the existing A-to-Z walk, rewritten so every clinical step
  goes through an order, ending with the same invoice total it produces today.
- `SidebarMenuTest` — Worklist opens for every role offered it.

## How the visit dialog reads

```
  Summary     who they are · the money · vitals · clinical notes · the pipeline
  Orders      EVERY piece of work, of every kind, in one list
  Bill        what the orders added up to, and the invoice
  Payments    what has been paid against it
  History     how the visit got here
```

Vitals and the clinical notes are **in** the summary, not tabs beside it: they
are what a summary of a visit is, and having both a summary block and a
Clinical section printed the complaints twice.

Orders is a **table, one row apiece** — type, what, who, when, status, cost.
Everything else about an order (its notes, every item, when it started and
finished, who raised it) lives in a dialog over the list, opened by clicking the
row: printing all of that for every order made a visit with a dozen of them
unreadable. That dialog is also where an order is moved on or cancelled.

**Add order** sits in the dialog's header, beside the close button, so it is one
click away however far down the visit a reader has scrolled. `x-ui.modal` gained
an `actions` slot for it.

### Placing one asks the same four things, whatever the kind

What it is, who does it, which department, and any instructions. Two kinds ask
one thing more, and only because they have to: **lab** and **imaging** name
catalogue rows at placement, because their tests and studies carry results,
reference ranges and findings that a free-text title cannot hold.

**Admission is one of those, for a stronger reason still.** A bed is scarce
and *exclusive* — two patients cannot have it — so it is claimed under a lock
at the moment the work is raised, not later when somebody gets round to adding
items. Placing an admission order asks for the ward and the bed (the ward
narrows the bed box, exactly as a department narrows the doctor box) and goes
through `AdmissionService::admit()`, which takes the bed, opens the admission
and raises the order pointing at it, all in one transaction.

That stay **stays open** until discharge, and two things follow from it:

- a visit **cannot reach Billing while its patient is still in a bed**, because
  the gate reads "no order still open". Before this, an active admission was
  not an order at all, so the gate could not see it;
- **Discharge patient** is what *Mark completed* says on a stay, and it runs
  `AdmissionService::discharge()` — freeing the bed and billing the nights onto
  that same order, rather than inventing a second one at discharge as it used
  to.

**Pharmacy is not one of those.** It used to ask for a drug and a quantity and
dispense them on the spot — through `DispensationService`, which created a
*second* order beside the one being placed, so one action produced two pieces
of work. What was dispensed is an **order item**: it is added in the order's
own dialog, where the charge and the stock movement happen in one transaction
and a removal reverses both. Asking for it twice, in two shapes, was the
duplication the order model exists to end.

Orders is one tab like any other. The six kinds are **chips inside it**, not
entries in the rail: they narrow one list rather than naming places to go, and
nesting them made the rail lopsided against four flat siblings. Adding an order is one dialog whose body changes with
the type — pick Lab and it asks which tests, Pharmacy and it asks which drug and
how much — and each of those routes through the service that owns the
machinery (`LabService`, `RadiologyService`, `DispensationService`), because
results, findings and stock levels are real and a generic form would lose them.

Placing one is not a single insert — it can move stock, raise a lab order and
put a line on the bill together — so the dialog shows the wait properly: a veil
over the whole dialog (the footer included, so nothing is submitted twice) with
a rule sweeping the top edge and four squares marching below it.

It does not then vanish. Ordering comes in runs — bloods and a scan in the same
breath — so on success the form empties and the dialog turns into its own
answer: what was placed, and the two things anyone wants next. **Add another**
starts a clean form on the same kind, still assigned to whoever is raising it;
**Done for now** closes. A refused order keeps the form and everything typed
into it.

## Managing one order

The order dialog is not a read-out. It is where the work is actually done, and
it carries the three things an order accumulates: **what it used**, **what was
found**, and **where it has got to**.

### What it used — the items

Every order has items, and the items are the bill *and* the material record of
what the patient received. Adding one asks four things and nothing else:

```
  ( ) Service            ( ) Product
      ↓                        ↓
  the price list         the pharmacy shelf
      ↓                        ↓
  quantity  ──────────→  total, computed, never typed
```

The split matters because the two behave differently. **A service is sold; a
product is sold and *leaves the shelf*.** Adding a product line posts a
`Dispensed` movement through `StockService`, in the same transaction as the
charge, so stock and money cannot disagree. Removing it posts
`ReturnedToStock`. That reason has existed in the enum since the ledger was
written and nothing has ever used it — this is what it was for.

The total is never typed. `line_total = unit_price × quantity` in bcmath, from
the catalogue price snapshotted at the moment of adding, exactly as every other
line on the system is priced.

**`order_items.quantity` becomes `decimal(12,2)`.** It is an integer today,
which is why `DispensationService` has to fold "× 6 tablets" into the line's
*name* and charge a quantity of one. That works for printing and breaks for
everything else: it cannot be reversed (the line says one), it cannot be
reported on, and it cannot be shown in a column. With a real quantity the
dispensing path stores what it actually did, and the money is unchanged —
`unit_price × quantity` comes to the same figure it came to before.

### What was found — the report

Two new pieces on the order: a `report` body, and attachments.

| | |
|---|---|
| `orders.report` | what was found, in words — typed, autosaved |
| `orders.report_updated_at` | so "Saved" can be shown honestly |
| `order_attachments` | results, films, scans, consent — on the **private** disk |

Attachments follow `patient_documents` exactly (HMS_PLAN §1): files land on the
private `local` disk under a per-hospital path and are only ever streamed back
through a policy-gated controller. A lab result is PHI; it does not go on a
public disk and it does not get a guessable URL.

Everything saves as it is done. The report autosaves as it is typed; a dropped
file is stored on drop; an added item is added; a removed one is removed. There
is no Save button on this dialog, because there is nothing it could mean.

### Where it has got to — and what cancelling costs

**Mark in progress** and **Mark completed** sit in the footer, driven by the
same `OrderStatus::transitionsTo()` the worklist will use.

**Cancelling is not a `wire:confirm`.** It reverses money and stock, and the
person pressing it should see exactly what, so the dialog turns into the
confirmation and lists it: every live line and what comes off the bill, every
product line and how many units go back on the shelf, the total, and a box for
the reason. `orders.cancel_reason` is displayed today and nothing has ever
written to it; this is where it gets written.

Cancelling a **dispensing** order reverses its stock too. It did not before:
`OrderService::transition()` cancelled the charges and left the drugs gone. The
reversal reads the movement ledger rather than the line — it returns what the
ledger says went out, so it is right even when the two could disagree.

### Finished has to mean something happened

An order cannot be **completed** with nothing on it. Any one of three things is
enough, and they are all evidence of work:

- a line that still stands on the bill,
- a written report,
- a file attached to it — a result that arrived as a PDF is evidence whether or
  not anybody typed a summary of it.

An order finished with none of them tells the next reader that work happened
and leaves no trace of what, which is worse than one still sitting open. The
rule lives in `OrderService::transition()`, the single place an order moves, so
every path is covered; `Order::hasEvidence()` is what it asks.

**Cancelling is always available.** An order raised by mistake should be called
off, not furnished with evidence first — and a removed line is the absence of
evidence rather than some of it, so it does not count.

The screen says so before the button is pressed rather than after: where *Mark
completed* would be, a locked note names what is missing.

One consequence worth knowing: discharging from a bed that costs nothing adds
no charge, so `AdmissionService` writes the stay's own report ("Discharged
after 3 nights.") — which it should have been doing anyway.

### The lock

Items may be added and removed while the order is open or completed, and stop
being editable at two points: once the order is **cancelled**, and once the
visit has a **live invoice**. The invoice snapshots its lines; letting the
order drift afterwards would silently desync the two.

**Bill**, not "Charges" or "Billing": the sidebar's Billing is a module
(invoices, claims); within one visit the thing is the bill, and it reads
naturally next to Payments.

## Order of work

Each stage leaves the suite green and could ship on its own.

| Stage | What | Risk |
|---|---|---|
| 1 | `orders` + `order_items`, migration and backfill. No behaviour change. | The money-preserving migration test is the gate. |
| 2 | `BillingService` reads and writes through orders. | Invoice totals must not move. |
| 3 | Lab, imaging, pharmacy and admission place real orders. | Each service's own suite. |
| 4 | Visit dialog: Charges → Orders. | Frontend only. |
| 5 | The Worklist page and its menu entry. | New surface, no existing behaviour. |
| 6 | Reports attribute by catalogue id. | Report figures change *by design* — old name-grouped rows merge. |

## The bill does not edit its own lines

Every charge is an item on an order, so every charge is owned by a piece of
work. The Bill section shows them, adds one, and invoices — and it changes none
of them.

Cancelling a line from the bill used to be possible, and it quietly
desynchronised three things at once: the drugs never came back to the shelf,
the order kept saying it was completed, and the evidence that justified saying
so vanished from under it. So the action is gone — not hidden, **gone** — and
every row instead offers the way into the order that raised it, which is the
one place a charge can change.

| Doing this | Happens where |
|---|---|
| change a quantity or a note | the order's own dialog |
| take a charge off | remove the line on its order, or cancel the order |
| add a charge with no clinical order behind it | **Add charge**, which raises its own order for it |

That last row is the point: *Add charge* is not an exception to the model. It
places a completed order named after the service and bills the line onto it, in
one transaction — so a charge with no work behind it cannot exist.

### Putting the bill back in step

`line_total` is stored rather than derived, so a catalogue reprice can never
move a charge that was already raised. The cost of that is a line **can** drift
— a bad import, a hand-edited row — and nothing would ever notice.

The small **refresh** button is what notices. `BillingService::recompute()`
recalculates every live line from its own unit price and quantity, says how
many were out of step, and refuses on an invoiced visit because those lines
have been snapshotted onto a document already issued.

### Add charge is not an exception

It asks the same two kinds an order's items do — **Service** off the price
list, **Product** off the pharmacy shelf — with a searchable picker, a live
total that is never typed, and a note. A product sold here leaves the shelf in
the same transaction as the charge, so a walk-in buying paracetamol moves stock
exactly as a dispensing does, and a sale larger than the shelf holds is refused
with nothing billed.

There is deliberately **no free-text charge**. A line with no catalogue entry
behind it cannot be attributed, which is the whole reason revenue reporting was
rewritten (see *Why we are doing this*). Something the hospital charges for
belongs on the price list first.

### The discount is agreed at the counter

It used to be a parameter to `generateInvoice()` — typed into the generate
dialog and gone if you closed it. That is the wrong moment: a discount is
agreed while the bill is being read, and whoever agreed it is not always
whoever raises the invoice.

So it lives on the visit, with **the reason and the hand that gave it**,
because giving money away is the kind of thing an audit asks about. The totals
show it as it stands:

```
  Total       8,000
  Discount   −3,000    · Staff rate · David Combs
  Due         5,000
```

`totals['total']` still means what the work came to, because that is what every
existing caller means by it; `discount` and `due` are their own keys. The
discount is **capped at the bill on every read**, not only when it was agreed —
lines come off orders afterwards, and one left larger than what is owed would
drive the total negative. Raising an invoice carries it across without anyone
retyping it, and after that it cannot change, because it is printed on the
invoice.

### The invoice lives in the visit

Raising one no longer throws the reader onto another page. The Bill section
shows the number, what was invoiced, paid and outstanding, and offers the
**PDF** — whoever raised it is looking at the visit, and that is where they
should stay. Once it exists, the bill is fixed: no adding, no invoicing again,
no rebilling.

## The shelf and the bill agree

Stock moves **when the work is done**, not when the bill is paid: a drug leaves
the shelf when it is given to the patient. Adding a product line posts the
movement, correcting the quantity posts the difference, removing the line puts
it back — all in the same transaction as the money.

On top of that, **every time a visit is confirmed and moved on**, the two are
checked against each other. `OrderService::reconcileStock()` compares, per line,
what the *ledger* says is still out against what the *line* says was used, and
posts only the difference:

| The line says | The ledger says | What happens |
|---|---|---|
| 6 given | 6 out | **nothing at all** |
| 6 given | nothing out | 6 taken |
| removed | 6 out | 6 put back |

That shape is what makes it safe. It is not a second place that deducts stock;
it is a comparison that posts a difference, so **it is idempotent by
construction** — running it five times can no more double-deduct than running
it once could, and on a visit where nothing has drifted it writes no movement
at all. Both of those are tested.

## Taking the money

The last link in the chain. Each method needs something different, and the form
asks for exactly that and nothing else:

| Method | Asks for | Why |
|---|---|---|
| Cash | the amount | cash is its own receipt |
| Prepaid card | **which card**, with its balance | it debits the card ledger |
| Mobile money | **the transaction ID** | it is the phone's record, not ours |
| Bank transfer | **the slip number** | same |
| Insurance | **the claim number** | same |
| *Flutterwave* | — | **never offered here** |

All of that was already supported by `BillingService::recordPayment()` through
its `opts` array, and none of it was reachable: the panel passed `[]`. So a
prepaid-card payment was impossible from the visit — the service refused it for
want of a card — and a reference could not be recorded at all, which is the one
thing that leads back to somebody else's record when the figures are
questioned.

**A gateway is not a counter method.** Flutterwave settles itself and is
recorded server-side once verified; offering it on this form would let someone
mark an invoice paid with money nobody has confirmed arrived. It is filtered
out of the choices *and* refused if the request is forged.

The rest is what a counter needs: the balance filled in by default with a
**Full balance** button when it has been edited, part payments with the
remainder shown on a bar, every payment's **receipt** one click away, and the
balance-after on each row so a disputed figure can be walked back through.

A settled invoice closes the visit by itself — that is the automatic gate, and
this section says so.

## The rule, once this lands

> Nothing is billed except as an item on an order, and no order exists except on
> a visit.

Which makes the whole chain one sentence: **a visit has orders, an order has
items, and the items are the bill.**
