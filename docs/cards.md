# Cards and insurers

A card is the one instrument a hospital uses to let somebody be treated without
paying at the counter — whether the money is their own, their family's, or an
insurer's. This is how the card, the people who may use it, and the company
behind it fit together.

It builds on what already exists: `patient_cards` and its append-only
`card_records` ledger (Phase 1 Step 7), and the insurer directory, coverage
rows and claims lifecycle in [insurance.md](insurance.md). Nothing here moves
money by a new route — every figure still lands in one of those two ledgers.

## The shape

```
insurance_provider ──< insurance_transactions        (the insurer's float)
        │                        │
        │                        └──────────────┐   (a settlement pays a card)
        ▼                                       ▼
  patient_card ──< card_holders >── patient   card_records
   (balance)         (the family)            (what was spent, by whom)
```

Four things are true of every card, and the fourth is the new one:

1. **A card is active or it is not.** `CardStatus` — active, inactive,
   suspended. Only an active, unexpired card transacts.
2. **A card has a ledger.** `card_records`, append-only, `+` for money in and
   `−` for money out, each row carrying the balance it left behind.
3. **A card has terms.** Whether it may go into debt (`accepts_credit`) and how
   far (`max_credit`).
4. **A card has holders, and may have an insurer.** One card, several people —
   a family — and optionally a company that stands behind the debt.

## 1. Holders — one card, a family

`patient_cards.patient_id` stays: it is the **primary holder**, the person the
card was issued to, and it never changes. `card_holders` adds everyone else.

| Column | Meaning |
|---|---|
| `patient_card_id`, `patient_id` | the link, unique together |
| `relationship` | spouse · child · parent · ward · employee · other |
| `status` | `active` · `revoked` — a holder is revoked, never deleted, because their spending stays on the ledger |
| `added_by`, `revoked_by`, `revoked_at` | who let them on, who took them off |

`PatientCard::covers(Patient $p)` is the single question everything asks: true
for the primary holder, and for any patient with an active holder row. A holder
must belong to the same hospital as the card.

**What this changes elsewhere.** `BillingService::recordPayment` refused any
card whose `patient_id` did not equal the invoice's patient — which is exactly
the case a family card exists for. It now asks `covers()`.

**Who spent it.** `card_records.patient_id` already exists and has until now
been a copy of the card's owner, which says nothing. It now records **the
patient the money was spent on**, defaulting to the primary holder when nobody
else is named. That is what makes a per-member usage report possible, and it
costs no migration: every existing row is the owner's own spending.

## 2. The insurer behind the card

`patient_cards` gains `insurance_provider_id` (nullable) and `member_no`. A
card with a provider is an **insurance card**:

* It is normally issued with `accepts_credit = true` and a `max_credit` — that
  is the whole point of it. The issue form defaults to the provider's own
  configured terms.
* Its negative balance is a debt the **insurer** clears, not the patient.
* Its spending appears on that insurer's usage report.

A card without a provider is an ordinary prepaid card and behaves exactly as it
does today.

> **Migration note.** Adding a constrained column to `patient_cards` on SQLite
> makes Laravel rebuild the table — copy, `drop table`, rename — and its
> `PRAGMA foreign_keys = 0` guard is silently ignored inside a transaction. The
> drop then cascades and takes every `card_records` and `payments` row that
> pointed at it. `App\Support\SchemaKeys` is the one place that decides: it adds
> the constraint where the engine can do it in place and skips it where it
> cannot, and every migration that alters a table to add a key goes through it.

## 3. Terms, after issue

`CardService::updateTerms()` — status, `accepts_credit`, `max_credit`, expiry —
in one place, row-locked, and refusing to lower `max_credit` below a debt the
card is already carrying. Until now terms could only be set at issue, which
meant the only way to raise a family's limit was to issue a second card.

## 4. The insurer's float

An insurer deposits money with the hospital; that money clears its members'
negative cards. Two rows, one transaction, both ledgers:

`insurance_transactions` — the insurer's own append-only ledger.

| Column | Meaning |
|---|---|
| `insurance_provider_id` | whose float |
| `type` | `deposit` (+) · `settlement` (−, paid onto a card) · `adjustment` (±) |
| `amount`, `balance_after` | bcmath scale-2, snapshot per row |
| `patient_card_id` | the card a settlement paid, null otherwise |
| `reference`, `notes`, `created_by` | the bank reference, the hand |

`insurance_providers.float_balance` carries the running figure, moved only
inside `InsuranceLedgerService` under a `lockForUpdate` on the provider row.

**Clearing the negatives** (`settle`): for one card or for every card of the
provider that is below zero, oldest debt first —

1. lock the provider, read the float;
2. for each card, the amount is `min(the card's debt, what is left of the
   float)` — a float that runs out clears what it can and stops;
3. post a `credit` to the card through `CardService` (so the card lock, the
   balance and `card_records` all behave exactly as they do for a top-up), and
   a `settlement` against the float, linked to each other by
   `card_records.insurance_transaction_id`;
4. all of it in one transaction — a settlement that cannot write both rows
   writes neither.

Deposits and settlements go through `FinancialYearService::assertPostingAllowed`,
like every other posting in the system.

## 5. The usage report

What the hospital hands the insurer. For one provider between two dates:

* every debit on every card of that provider, by member — the patient the money
  was spent on, not the cardholder;
* per-member and grand totals;
* the deposits received and the settlements paid in the same window;
* the closing float and the outstanding debt across all its cards.

Rendered on screen, downloadable as a PDF through the same private route as
every other report.

## Where it lives in the menu

Until now a card could only be reached by knowing whose it was: open the
patient, scroll to the panel. That is the wrong way round for everything a
card desk actually does — find a card by its number, see which cards are in
debt, read today's movements, reconcile an insurer's float. So cards get their
own group under **Billing & finance**, beside Billing and Finance:

| Entry | Page | What it is for |
|---|---|---|
| **Cards** | `admin.cards.index` | every card in the hospital: holder, insurer, status, balance, limit — filtered by insurer, by status, or down to the ones in debt |
| **Card records** | `admin.card-records.index` | the hospital's whole card ledger, newest first, filtered by card, patient, type, insurer or date |
| (no row) | `admin.cards.show` | one card: its terms, its holders, its ledger. Reached from either page above |

The patient page keeps its Cards panel, and the line between the two is drawn
so that **no control exists twice**:

* the **panel** answers *what cards does this person have, and what is on them*
  — issue one, top it up, charge it, read its ledger;
* the **card page** answers *what is this card* — its terms, who else may spend
  on it, which insurer stands behind it, and clearing its debt.

Every row in the panel links to the card page, so nothing is more than one
click away. Both read the same rows and write through the same `CardService`.
A control over a credit limit that exists in two screens is a control that will
one day behave differently in the two, and these are balances;
`CardSectionTest` asserts the panel has not grown its own copy of any of them.

`SidebarMenuTest` walks every menu entry and asserts the page it lands on is
headed with the same words, so "Cards" opens a page titled Cards.

## Files

| Concern | File |
|---|---|
| Tables | `2026_09_19_000001_a_card_can_be_held_by_a_family.php`, `…_000002_a_card_can_belong_to_an_insurer.php`, `…_000003_insurers_keep_a_float.php` |
| Models | `app/Models/{PatientCard,CardRecord,CardHolder,InsuranceProvider,InsuranceTransaction}.php` |
| Enums | `app/Enums/{CardStatus,CardEntryType,CardHolderStatus,CardHolderRelationship,InsuranceEntryType}.php` |
| Services | `app/Services/{CardService,InsuranceLedgerService}.php` |
| Policy | `app/Policies/PatientCardPolicy.php` |
| Livewire | `app/Livewire/Cards/{Index,Show}.php`, `app/Livewire/CardRecords/Index.php`, `app/Livewire/InsuranceProviders/{Index,Show}.php`, `app/Livewire/Patients/Panels/Cards.php` |
| PDF | `app/Http/Controllers/Admin/InsuranceProviderController.php`, `resources/views/pdf/insurance-usage.blade.php` |
| Schema helper | `app/Support/SchemaKeys.php` |
| Tests | `tests/Feature/{CardHolderTest,InsuranceLedgerTest}.php`, `tests/Feature/Livewire/CardSectionTest.php` |

## RBAC

`patients.card.manage` — issue a card, move money on it, add or revoke a
holder, change its terms (admin, receptionist, accountant).

`insurance.manage` — register an insurer, take a deposit, settle its members'
cards (admin, accountant). `insurance.view` — read the float and the usage
report (adds receptionist).

A card's balance and credit limit are never rendered for a role without
`patients.card.manage`; that rule predates this and is unchanged.

## Rules this system holds to

1. **The ledgers are the truth.** `patient_cards.balance` and
   `insurance_providers.float_balance` are caches of their ledgers.
   `CardService::reconcile()` and `InsuranceLedgerService::reconcile()` prove
   each against its own rows, and the card and insurer pages say so out loud
   when the two disagree.
2. **Nothing moves outside a service.** No controller, component or model hook
   writes a balance.
3. **Every movement is locked, and always in the same order:** the PROVIDER
   first, then the card. Two clerks settling the same insurer at once queue
   rather than deadlock, and neither can spend the same float twice.
4. **bcmath scale-2, always.** Amounts are strings from the request to the
   column.
5. **A ledger row is never edited or deleted.** A mistake is corrected by an
   `adjustment`, which says who corrected it and why.
