# Billing (Phase 1, Step 11)

Everything money-facing is **per-hospital configurable** — no currency, tax rate or fee is
hardcoded. This is **Step 11a**: the config layer, the price list, and billable service lines
on a visit. Invoices, payments (cash + prepaid-card) and PDFs ship in **11b**.

## Dynamic configuration (`HospitalSettings`)

`app/Support/HospitalSettings.php` (a per-request singleton over `CurrentHospital`) reads the
current hospital's `currency` column and `settings.billing` JSON, merged over sane defaults.
The hospital **owner/admin** sets all of it at **Billing settings** (`/admin/settings/billing`,
`BillingSettingController`, gated `manage-settings`):

| Setting | Example |
|---|---|
| Currency code / symbol / position | `KES` · `KSh` · before/after |
| Decimals, thousands & decimal separators | `0` · `,` · `.` |
| Tax enabled / label / rate | on · `VAT` · `16` |
| Default visit fee | `500` |
| Invoice number prefix / footer | `INV` · terms text |

`HospitalSettings::money($amount)` formats any amount in the hospital's currency and is used
everywhere money is shown (prepaid cards, price list, visit charges, and — in 11b —
invoices/receipts). Change the config and the whole app re-formats.

## Price list (`services`)

A hospital-owned catalogue (`ServiceController`, `services.manage`): name (unique per
hospital), code, price (in the hospital's currency), tax-exempt flag, active flag. Prices are
the hospital's own.

## Billable lines (`medical_services`)

Ordering a catalogue service on a visit (`MedicalServiceController`, `billing.manage`)
creates a line that **snapshots** the service name + unit price at order time, so a later
price-list change never rewrites history. `line_total = unit_price × quantity` and all
roll-ups are computed by `BillingService` in **bcmath scale-2** (never a float, never a model
hook). Cancelling a line sets its status to `cancelled` and drops it from the totals.

`BillingService::totalsFor($visit)` rolls up the billable lines and applies the
hospital's configured tax **only to non-exempt lines**, returning scale-2 strings
`{subtotal, taxable, tax, total, currency}`.

## RBAC

`services.manage` (price list — admin, accountant) · `billing.manage` (order lines, and in
11b invoices/payments — admin, doctor, receptionist, accountant) · `billing.view` (admin,
doctor, receptionist, accountant).

## Tests (11a)

`BillingServiceTest` (bcmath line totals, snapshot survives catalogue price change, configured
tax on non-exempt lines only, tax-off, cancelled-line drops out, **currency formatting is
hospital-configurable** across UGX/EUR/KES); `ServiceCatalogueTest` (CRUD, unique-per-hospital,
role gating, isolation); `MedicalServiceOrderTest` (snapshot+total, cross-hospital service
rejected, cancel); `BillingSettingsTest` (admin configures currency & it persists + re-formats,
non-admin blocked).

---

## Step 11b — invoices, payments, PDFs

### Files (11b)

| Concern | File |
|---|---|
| Tables | `2026_07_19_180000_create_invoices_table.php`, `…_180010_create_invoice_items_table.php`, `…_180020_create_payments_table.php` |
| Models | `app/Models/{Invoice,InvoiceItem,Payment}.php` |
| Enums | `app/Enums/InvoiceStatus.php`, `app/Enums/PaymentMethod.php` |
| Service | `BillingService::generateInvoice()`, `recordPayment()`, `createInvoiceWithNumber()` |
| Exception | `app/Exceptions/OverpaymentException.php` |
| Requests | `app/Http/Requests/{InvoiceGenerate,Payment}Request.php` |
| Policy | `app/Policies/InvoicePolicy.php` |
| Controllers | `app/Http/Controllers/Admin/{Invoice,Payment}Controller.php` |
| Views / PDFs | `resources/views/admin/invoices/{index,show}.blade.php`, `resources/views/pdf/{invoice,receipt}.blade.php` |

### Invoicing

`generateInvoice(visit, discount, by)` rolls the visit's billable lines into an
invoice in one transaction: it snapshots each line into `invoice_items`, computes
subtotal/tax (via `totalsFor`), applies a flat discount (`0..subtotal+tax`), and allocates
`invoice_no = <prefix>-<year>-<seq5>` (prefix from `HospitalSettings`) with a locking per-year
count, retried on the unique violation. **One live invoice per visit** — a re-generate
is blocked while a non-void invoice exists. Currency is snapshotted onto the invoice.

### Payments (cash + prepaid card)

`recordPayment(invoice, method, amount, opts, by)` runs in a transaction with a `lockForUpdate`
on the invoice (no lost updates on concurrent payments). It rejects non-payable invoices and
**overpayment** (`OverpaymentException`, bcmath compare against balance). A **card** payment
debits the patient's prepaid card through `CardService` — which locks the card and writes the
append-only card-ledger row — and stores `card_record_id` on the payment so the two money
trails reconcile; if the card lacks funds, `InsufficientFundsException` rolls the whole payment
back (no orphan payment, invoice untouched). `amount_paid`/`balance` are updated and status
moves `issued → partially_paid → paid`. Payments are append-only (`UPDATED_AT = null`).

### PDFs

Invoice and receipt render via DomPDF (`pdf/invoice`, `pdf/receipt`), formatting every amount
through `HospitalSettings::money()` — so both documents show the hospital's own currency, tax
label and invoice footer.

### Tests (11b)

`InvoicePaymentTest` — the calculation suite (§ "unit tests for every calculation"): subtotal/
tax/total roll-up, discount, discount-cap, one-invoice-per-visit, cash partial→full with
status/balance tracking, overpayment rejected, paid-invoice rejects further payment, **card
payment debits the card ledger**, **insufficient-funds rolls everything back**.
`InvoiceHttpTest` — generate→pay HTTP flow, PDF renders, billing.manage gating, tenant isolation.

## Billing settings: the shape of every amount

`/admin/settings/billing` decides how money is written down everywhere — on
screen, on invoices, on receipts. Two things were being decided blind there.

**Changing the currency code RE-LABELS what is already recorded.** It does not
convert it: four hundred invoices in shillings become four hundred invoices in
dollars, at the same numbers. The onboarding step has always said so in a
docblock; the page where it can actually be done said nothing. It now counts
what is on the books and says it plainly — "3 invoices and 0 payments are
already recorded in UGX; saving re-labels them as USD at the same numbers" —
and the save asks for confirmation. A hospital with no money yet sees none of
this, because for them it is not a warning, it is just setup.

**The two separators had to be told apart, and were not.** Each was validated,
neither against the other, so `thousands = "."` with `decimal = "."` was a
saveable way to write one million as `1.234.567.89`. `decimal_separator` now
carries `different:thousands_separator`.

Everything that shapes an invoice is previewed, because a rate is not an amount
and a prefix is not a number: the formatted sample, the next invoice number, and
a worked example of the tax on a real bill. All of it updates as the form is
typed. The currency pills take a currency whole — code, symbol and decimal
places together — since a shilling has no cents and a dollar has two, and
offering the three as unrelated fields is how a UGX hospital ends up printing
"USh 30,000.00". The tax label and rate are asked for only when tax is on.

`save()` merges into `settings['billing']` rather than replacing it: the
letterhead page writes the footer line into the same key, and a save here must
not drop what it does not ask for.
