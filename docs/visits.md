# The visit

**A visit is one patient attendance, and everything that patient does belongs
to one.** Orders, prescriptions, procedures, dispensations, the bill, an
admission — all of it hangs off a single `visits` row. That is the core of the
model; nothing clinical or billable floats free of it.

## Why "visit" and not "consultation"

This was called a *consultation* until it was renamed. The old name was wrong in
a way the system's own state machine already knew about:

```
registration → triage → consultation → orders → billing → payment → completed
```

A consultation is **one stage** a visit passes through, not the container. A
lab-only walk-in, a dressing change, a pharmacy sale or a six-day admission is
a visit with no consultation in it at all — and calling a six-day inpatient
stay "a consultation" reads wrong to any clinician.

So the entity is a **Visit**, and the word *consultation* keeps its narrow
clinical meaning everywhere it is still correct:

| Still "consultation" | What it means |
|---|---|
| `VisitStatus::Consultation` | the stage where the doctor sees the patient |
| `RoomType::Consultation` | a consultation room |
| `consultation_fee` | the default fee for that service |
| "General consultation", "Specialist consultation" | priced services on the price list |

Other candidates and why not: **Encounter** is the formal HL7/FHIR term and the
right one for an integrator, but no receptionist says it. **File** already means
attachments (`PatientDocument`). **Case** usually means a condition managed over
time — that is a bigger container than one attendance, and the word is worth
keeping free for when episodes of care are modelled.

## The rule, and where it is enforced

Three layers, so it cannot be bypassed:

1. **The database.** `visit_id` is `NOT NULL` on every clinical and billable
   table, with `ON DELETE CASCADE` — a visit's records die with it:
   `invoices`, `admissions`, `treatment_records`, `lab_orders`,
   `radiology_orders`, `prescriptions`, `dispensations`, `orders`,
   `visit_status_histories`. Two tables reach a visit through their parent
   instead: an insurance claim through its invoice, and a billing line
   (`order_items`) through the order that raised it — see
   [`orders.md`](orders.md).
2. **The services.** `AdmissionService` and `TreatmentService` call
   `VisitService::currentOrOpenFor($patient)` when no visit is given.
3. **The tests.** `EverythingBelongsToAVisitTest` asserts the schema, the
   refusal, and the service behaviour together.

### Care is never blocked for missing paperwork

An emergency admission or a walk-in procedure arrives with no visit open. The
answer is to **open one**, not to refuse the patient —
`VisitService::currentOrOpenFor()` returns the patient's open visit for today,
or opens a fresh one. Callers that know the visit always pass it; this is the
floor, not the normal path. A visit that is already `completed` or `cancelled`
is never reused: the next piece of work opens the next visit.

### What is deliberately *not* bound to a visit

Patient **identity** exists before the first visit and between visits, so
binding it to one would make a newly registered patient impossible to record:

- `patient_insurances` — the cover they hold
- `patient_dependents` — who they are responsible for
- `patient_cards` — the prepaid card itself (its *spend* reaches a visit through
  the payment → invoice → visit chain)

`patient_documents` sits in between and takes an **optional** `visit_id`: an ID
scan belongs to the patient, a referral letter belongs to the visit it arrived
with.

## Starting one

The new-visit dialog asks three questions in order, and **each answer narrows
the next**. The order is the design, not the layout:

```
  1 · Who is it?          registered patient, or register one now
        ↓
        the patient's picker fills a brief: an open visit, a booking
        today, money still owed — all of it discovered too late otherwise

  2 · Where is it going?  department FIRST, because choosing it narrows the
        ↓                 doctor box to that department; choosing a doctor
                          first fills their department in instead

  3 · Why have they come? reason, offered as the phrases this hospital
                          actually writes, its own words before the curated set
```

### What the brief is for

Each line of it is a thing that is expensive to learn late:

| Shown | Because |
|---|---|
| **An open visit** | Opening a second one splits the patient's bill in two. The dialog offers to go to the existing one instead. |
| **Today's booking** | The visit can fulfil it — one click carries the appointment, the doctor, the department and the agreed reason across. |
| **Outstanding balance** | The desk is the only place this can be raised, and it is standing in front of the patient. |

The open-visit line is a **warning, not a block**. Clinical work must never be
stopped because the paperwork disagrees (see `currentOrOpenFor` above); a second
visit is sometimes right, and the person at the desk is the one who knows.

### One appointment, one visit

`visits.appointment_id` has existed since the column was added and the form
never set it, so a booking and the visit that attended it were connected by
nothing. Now the dialog links them — and the database enforces it:

- `visits.appointment_id` carries a **unique index**. A double-click, two
  receptionists or a retried request can no longer produce two visits, and two
  bills, for one attendance. Both engines allow many NULLs, so walk-ins are
  unaffected.
- The server re-checks that the appointment is *this patient's* and has no visit
  yet. The dialog only ever offers a valid one, but the property is public and
  the browser is not trusted.

### No whole-table dropdowns

Doctor and department were `<select>`s rendering every row on every render.
Both are `<livewire:ui.select-search>` now, and the picker gained a `#[Locked]`
`scope` so `doctors` can be narrowed to a department through `staff_profiles`.
That scope is what makes the cascade real rather than cosmetic: the narrowing
happens in the query, not in the view.

Every picker's key carries a form nonce, so closing and reopening the dialog
cannot leave the last person picked sitting in a box the parent has cleared.

## Status and stage: two questions, not one

A visit used to have one field answering two different questions at once —
*is this visit alive?* and *what is being done to it right now?* — with eight
values that mixed clinical steps (triage, consultation) with money steps
(billing, payment). It meant a cancelled visit had no stage, a finished one
could not be told apart from a settled one, and the only way forward was for
somebody to pick the next word off a list.

Now there are two fields, and each answers one question.

### Status — is this visit alive?

| | |
|---|---|
| **Pending** | opened; nothing has been done yet |
| **Ongoing** | work is happening |
| **Completed** | finished — see the outcome |

### Outcome — how it ended

Only set when the status is Completed.

| | |
|---|---|
| **Closed** | it ran its course and was settled |
| **Cancelled** | it was called off, with a reason |

### Stage — what is being done right now

| | |
|---|---|
| **Ongoing** | care is being given |
| **Billing** | care is finished; the bill is being drawn up |
| **Payment** | the bill is issued; money is being collected |
| **Completed** | settled |

One badge is still shown, built from the pair, because that is what a reader
wants: `Pending` · `Ongoing` · `Billing` · `Payment` · `Completed` · `Cancelled`.
The difference is that the system now *knows* which of the two it means.

## Nobody picks a stage off a list

The stages are not chosen. Each one has a **gate** that the system evaluates,
and the control only appears once its gate is open:

```
  Ongoing ──── all orders complete ────▶ Billing
                                            │
  Billing ──── an invoice exists  ────▶ Payment
                                            │
  Payment ──── balance is zero    ────▶ Completed     (automatic)
```

| Move | Gate | How it happens |
|---|---|---|
| Ongoing → Billing | **no order still open** | a *Ready for billing* button, which does not appear until the gate is open |
| Billing → Payment | **a live invoice exists** | a *Ready for payment* button, same |
| Payment → Completed | **balance is zero** | **by itself**, the moment the last shilling is paid |
| anywhere → Cancelled | — | *Cancel visit*, with a reason |
| Pending → Ongoing | — | **by itself**, when the first work is recorded |

Two of them are a click because they are decisions a human makes — *we have
finished treating this patient*, and *this bill is final*. The other two are
not decisions at all, so the system does them.

A blocked gate says what is blocking it, in figures: "2 orders are still open",
"UGX 41,000 still outstanding". A button that is merely greyed out teaches
people nothing.

### The hard rules

- **A visit cannot reach Billing while any order is open.** Billing a visit
  whose lab work has not come back invoices for work nobody has finished.
- **A visit cannot leave Payment until the balance is zero.** This is the one
  that used to be enforced by nothing at all: a visit could be marked completed
  with money still owed on it, and then nobody was looking for it any more.
- **Cancelling is always available** and always takes a reason.

### And the admin can still put it anywhere

`visits.override` sets the status, the outcome and the stage directly,
regardless of gates, with a reason, recorded in the trail as *out of order*.
Everything above is the shape of a normal day; the override is for the days
that are not.

## What happened to the old stages

Registration, Triage, Consultation and Orders were four names for *the patient
is here and we are dealing with them* — which is one state, and it is called
Ongoing. Where the patient physically is (triage bench, doctor's room) is
answered by their **orders**, which say who is doing what and where it has got
to. That is what orders are for (`docs/orders.md`), and duplicating it in the
visit's own status meant two things could disagree.

| Old | Becomes | |
|---|---|---|
| `registration` | Pending | nothing has been done yet |
| `triage` · `consultation` · `orders` | Ongoing, stage Ongoing | one state, not four |
| `billing` | Ongoing, stage Billing | |
| `payment` | Ongoing, stage Payment | |
| `completed` | Completed, outcome Closed, stage Completed | |
| `cancelled` | Completed, outcome Cancelled | |

**The history is not rewritten.** `visit_status_histories` is an append-only
audit table, and rows written before this change really do say "Triage →
Consultation" — that is what happened, and editing it to say something tidier
would be falsifying a record. The columns hold plain strings and the model
knows both vocabularies, so an old trail keeps reading correctly.

## What the row menu offers

Nine entries under four headings is a wall. A row menu is for the two or three
things anyone does *from a list*; the rest of the visit is one click away
inside it. No headings, no counts, and one rule — **nothing in it that the
click would refuse**:

```
  Open visit
  Add an order                 (open visits, with write permission)
  Ready for billing / payment  (only while its gate is open)
  Cancel visit
  ─────────
  Bill & payments
  Full page
  Patient record
  ─────────
  Set state…                   (visits.override only)
```

The gate is decided per row from counts the listing query already fetched —
`open_orders_count` and `live_invoices_count` as subqueries — so offering it
costs nothing and never lies. Cancelling is never performed from the menu: it
costs a reason, so it opens the dialog that asks for one.

This needs saying because for a while the menu did lie. It offered Record
vitals, Clinical notes, Order lab tests, Order imaging, Prescribe and Dispense
long after those stopped being sections — the clinical work became orders
(`docs/orders.md`) — so all six fell through to Summary and silently did the
same thing as *Open visit*. The guard is now a test that reads every `section:`
the menu names out of the rendered markup and checks each against
`Actions::SECTIONS`. Asserting the labels, which the old test did, could never
have caught it.

## Naming across the stack

| Thing | Name |
|---|---|
| Table | `visits`, `visit_status_histories` |
| Foreign key | `visit_id` |
| Number | `visit_no`, formatted `V-20260916-001` |
| Model | `App\Models\Visit`, `App\Models\VisitStatusHistory` |
| Service | `App\Services\VisitService` |
| Enum | `App\Enums\VisitStatus` |
| Routes | `admin.visits.*`, `api/v1/visits` |
| Permissions | `visits.view`, `visits.create`, `visits.manage`, `visits.vitals`, `visits.diagnose` |
| Sequence key | `visit` |

The word *encounter* was a second name for the same thing and has been removed:
one concept, one noun.

## The rename migration

`2026_09_16_000002_rename_consultations_to_visits` does the whole move: renames
the tables and columns, re-prefixes the numbers, migrates the permission rows
and the sequence key, backfills a visit for any invoice or admission that had
none, and only then tightens the columns.

Two things it does deliberately:

- **Every step is guarded** (`hasTable` / `hasColumn`). MySQL cannot roll back
  DDL, so a migration this wide must resume from wherever it stopped rather
  than leave a database that can go neither forward nor back.
- **Foreign keys are dropped by lookup, not by name.** The old constraints were
  `ON DELETE SET NULL`, and MySQL refuses to make a column `NOT NULL` while a
  constraint on it may write NULL. Renaming a column leaves the old constraint
  name behind on MySQL, and a rollback-then-reapply leaves a different one, so
  the migration asks `Schema::getForeignKeys()` which constraint actually sits
  on the column instead of assuming a naming convention.

It is reversible: `down()` puts every name, column, number and permission back.
