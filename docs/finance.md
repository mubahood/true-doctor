# The books (finance)

Every hospital keeps a double-entry general ledger. The hospital's own work —
invoices, payments, card top-ups, insurer floats, stock — is posted into it as
it happens; everything else (rent, salaries, a grant, cash banked) is recorded
by hand. The statements are read straight off it.

Before this, each money record kept its own trail and none of them could say
what the hospital earned after its costs, what it is worth, or where the money
went. They still keep their trails; the ledger is what they answer to.

## The three tables

| Table | What it is |
|---|---|
| `ledger_accounts` | The chart of accounts. `type` is asset / liability / equity / income / expense. `is_book` marks an account money actually sits in (cash drawer, bank, mobile money). `system_key` is how the system finds an account to post to. |
| `journal_entries` | One event, written once. `kind` says where it came from. `source_type/source_id/source_event` name the system record it was posted from — unique together, which is what makes posting idempotent. |
| `journal_lines` | Its debits and credits. Always balanced. The entry's date is copied onto each line so a balance is one indexed sum. |

Dates are stored as `Y-m-d` by `App\Casts\DateOnly`; Eloquent's `date` cast
writes a time that SQLite keeps and MySQL trims, and range sums must read the
same on both.

## Writing to it

`App\Services\Finance\LedgerService` is the only writer. Before anything is
written it checks: at least two lines, each a debit or a credit and not both,
nothing negative, every account the hospital's own and switched on, debits
equal to credits to the cent, and (for anything typed at a desk) the date not
in a closed financial year. Then the entry and lines go in one transaction with
a gap-free number (`JE-2026-00001`, via `App\Support\Sequence`).

**Nothing is edited.** `reverse()` writes the mirror image dated today and
links the two (`reverses_id` / `reversed_by_id`). Only entries a person wrote
can be reversed by hand; a system entry is corrected by undoing its cause, or
by a journal.

## What the system posts

`App\Services\Finance\Bookkeeper`, called inside the transaction that writes
the source record, under the record's own hospital (`CurrentHospital::within`):

| Event | Debit | Credit | Called from |
|---|---|---|---|
| Invoice issued | Patient receivables (total); Discounts allowed | Income per line; Tax payable | `BillingService::generateInvoice` |
| Payment | The book for its method (settings) — card: Patient card deposits; insurance: Insurance receivables | Patient receivables | `BillingService::recordPayment` |
| Card top-up | The book for how it was paid, else the top-up book | Patient card deposits | `CardService::credit` |
| Card charged at its desk | Patient card deposits | Other patient income | `CardService::debit` |
| Insurer deposit | Insurer-deposit book | Insurer deposits | `InsuranceLedgerService` |
| Float settles a card | Insurer deposits | Patient card deposits | `InsuranceLedgerService` |
| Float adjusted | Suspense / Insurer deposits | the other | `InsuranceLedgerService` |
| Opening stock | Inventory | Opening balance equity | `StockService` |
| Stock received | Inventory | Accounts payable (or the book in settings); a delivery against a purchase order names the supplier as the party and the GRN as the reference | `StockService` (`ProcurementService::receive`) |
| Dispensed / returned | Cost of sales ↔ Inventory | | `StockService` |
| Expired, damaged, lost, wastage, adjustment out | Stock losses | Inventory | `StockService` |
| Adjustment in | Inventory | Stock count gains | `StockService` |
| Returned to supplier | Accounts payable | Inventory, at the delivery's cost, supplier as the party | `StockService` (`ProcurementService::returnToSupplier`) |

Stock is valued at the `unit_cost` the stock ledger keeps on every movement.
A payment by card is posted once, with the payment: `BillingService` passes
`books => false` to the card debit. A settlement's card credit carries
`insurance_transaction_id` and is posted with the float.

**Which income account a line earns into**: its service's own
`ledger_account_id` if set and usable; Pharmacy sales for anything off the
shelf; otherwise by order type (consultation, lab, imaging, pharmacy,
procedure, admission). Any gap between the lines and the invoice subtotal goes
to Other patient income, so the entry always balances to the invoice.

System postings may land on a switched-off account: a finance setting must
never be what stops a patient being billed.

### History, and anything that slipped

`App\Services\Finance\BooksBackfill` asks every money record "is there an entry
for you?" and posts those without. `php artisan finance:backfill` runs it for
every hospital (the deploy script runs it each time — it is idempotent), and
the finance overview shows a count with a "Bring them in" button.

## Recorded by hand

`App\Services\Finance\CashbookService`:

- **Money in** — Dr book, Cr category (income, or a receivable collected, a
  loan, capital).
- **Money out** — Dr category (expense, or a payable settled, equipment,
  drawings), Cr book.
- **Transfer** — between two books; earns nothing.
- **Journal** — any accounts, any number of lines, as long as they balance.
- **Opening balances** — each figure to its account, the difference to opening
  balance equity, so the entry balances without anyone working it out.

A book cannot be a category (that is a transfer), and inventory cannot be
bought by hand (receive it in Pharmacy so the shelf and the books agree).

## The chart

`App\Services\Finance\ChartOfAccounts::STANDARD` is provisioned the first time
anything asks, plus the practice's own accounts (`docs/practice.md` — a dental
practice gets Dental income and Dental laboratory fees). After that the chart
is the hospital's: rename, renumber, add, switch off. Accounts with a
`system_key` cannot be switched off, deleted or change type; accounts with
entries cannot be deleted or change type. `ensure()` adds back any system
account that is missing, and never a standard one the hospital deleted.

`settings.finance` (Finance settings page) holds account ids for: the book each
payment method lands in, the card top-up book, the insurer-deposit book, and
what stock deliveries are owed against. Blank or switched-off falls back to the
standard account.

## The statements

`App\Services\Finance\FinancialStatements` — nothing stored, every figure a
sum of lines over a date range:

- **Income statement** — income from patient care, less discounts = net
  income; less cost of sales = gross profit; less running costs = operating
  profit; plus other income = net profit. Optional comparison with the
  previous period or a year earlier.
- **Balance sheet** — as at a date. Equity includes profit kept from earlier
  years and this year's profit so far, so it balances without closing entries.
- **Trial balance** — every balance in its debit or credit column; this year's
  income and expenses, earlier years' in retained earnings.
- **Cash flow** — each book movement put down to the other side of its entry
  ("Patients paying their bills", "Rent"), plus each book's start, in, out and
  end. Transfers net to nothing.
- **Account ledger** — opening balance, every line with the balance after,
  closing.

"This year" is the financial year containing the date, or the calendar year.
Closing a financial year (`FinancialYearService`) blocks postings dated in it;
it does not post closing entries.

## Pages and permissions

| Page | Route | Permission |
|---|---|---|
| Finance overview | `admin.finance.index` | `finance.view` |
| Transactions (cashbook) | `admin.finance.transactions` | view; recording needs `finance.record` |
| Journal | `admin.finance.journal` | view; journals, opening balances, reversals need `finance.manage` |
| Chart of accounts / ledger | `admin.finance.accounts(.show)` | view; changes need `finance.manage` |
| Financial statements | `admin.finance.statements` (+ `statement-pdf`, `export`) | `finance.view` |
| Finance settings | `admin.finance.settings` | `finance.manage` |

`finance.record` is new; `hospital_admin` and `accountant` hold all three.
The price list's "Earns into" field sets a service's income account.
Card top-ups ask how they were paid (`PaymentMethod::forTopUp()`).
