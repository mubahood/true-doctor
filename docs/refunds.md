# Refunds and credit notes

Roadmap 04 §8. Before this there was no way to give money back: a test not
done, a line charged twice or a drug returned unopened stayed on the bill.

## The two documents

| | Credit note | Refund |
|---|---|---|
| What it does | Takes money off what an invoice asks for | Pays money back to the patient |
| Limit | What the invoice still asks for (total less earlier credit notes), less credit notes waiting | What the patient paid beyond what the invoice now asks for, less refunds waiting |
| Number | `CN-2026-00001` | `RF-2026-00001` |
| Books | Dr Credit notes allowed (4910) and Tax payable (its share), Cr Patient receivables | Dr Patient receivables, Cr the book for its method (cash, mobile money, bank, or Patient card deposits) |
| Cashier's day | Counted, no money moves | Leaves the drawer: "Refunded" and "Net received" |

**A refund always follows a credit note.** Refunding money on an invoice that
still asks for it would leave the patient owing it again, so the service
refuses ("Credit it first, then refund"). A refund request may be made while
its credit note is still waiting; approval checks it again against what is
real by then.

The tax on a credit note goes back in the share it was charged: credit 55 of a
110 invoice with 10 tax and 5 of it is tax.

## Asking and approving

- Anyone with `billing.manage` (front desk, doctor, accountant) asks, with a
  reason. The reason is required, printed on the PDF and kept in the books.
- Anyone with `billing.refund` (new; `hospital_admin` and `accountant`)
  approves or rejects (rejection needs a note). When that person asks, it is
  approved at once.
- Approval is the money moment, in one transaction with row locks on the
  invoice and the request: the limits are checked again, the closed-year guard
  runs, the document is numbered (`Sequence`, gap-free per kind and year), the
  invoice's `credited` / `refunded` and `balance` / `status` move, a refund to a
  card credits the card (`books => false`: the refund carries the entry), the
  books get their entry (`Bookkeeper::creditApproved`), and the visit is
  reconciled (a bill credited to nothing closes its visit).
- A request is decided once. After that the row is never edited or deleted
  (the model refuses); a mistake is put right by another document.

## The invoice's figures

`invoices.credited` and `invoices.refunded` sit beside `amount_paid`:

- what it asks for = `total − credited` (`Invoice::netTotal()`)
- what is held = `amount_paid − refunded` (`Invoice::held()`)
- `balance` = what it asks for less what is held, never below nothing
- refundable = what is held beyond what it asks for (`Invoice::refundable()`)

`balance` stays the one column every report reads, and the one definition of
"outstanding" is unchanged. An invoice credited down to nothing is `paid`
(settled); its PDF says "Settled by credit note" when nothing was paid.
`BillingService::recordPayment` reads the same figures.

## Where it shows

- **Invoice page**: a "Credit notes and refunds" card (ask, approve, reject,
  PDF), and Credit notes / Refunded in the totals. The invoice PDF shows them too.
- **Billing → Refunds and credit notes**: every document, filter by status and
  kind, "N waiting for approval", quick view, approve and reject.
- **Cashier's day** (web, PDF, API): `refunds{count,total,by_method}`,
  `credit_notes{count,total}`, `net`.
- **PDF**: `pdf/credit-note.blade.php`, plain like the receipt; approved
  documents only.
- **API**: [`api/refunds.md`](api/refunds.md).
- **The app**: the same list (waiting first), one document with its invoice,
  approve and reject, the PDF; "Credit note / refund" on an invoice; and
  Refunded and Net received on the day close.

## Code

`App\Services\InvoiceCreditService` (rules), `App\Models\InvoiceCredit`,
`App\Enums\CreditKind` / `CreditStatus`, `App\Policies\InvoiceCreditPolicy`
(+ `InvoicePolicy::credit`), `App\Http\Requests\InvoiceCreditRequest`,
`App\Livewire\Credits\Index`, `App\Livewire\Invoices\Show`,
`App\Http\Controllers\Admin\InvoiceCreditController` (PDF),
`App\Http\Controllers\Api\V1\InvoiceCreditController`. Demo:
`database/seeders/DemoScenarios/Refunds.php`. Tests:
`tests/Feature/RefundsAndCreditNotesTest.php`.
