# Financial years (Phase 4, Step 18)

Accounting periods with close/reopen and per-period reporting. Tenant-scoped.

## Files

| Concern | File |
|---|---|
| Table / Model | `2026_07_19_270000_create_financial_years_table.php`, `app/Models/FinancialYear.php` |
| Enum | `app/Enums/FinancialYearStatus.php` |
| Service | `app/Services/FinancialYearService.php` |
| Exception | `app/Exceptions/ClosedPeriodException.php` |
| Request / Policy | `app/Http/Requests/FinancialYearRequest.php`, `app/Policies/FinancialYearPolicy.php` |
| Controller / Views | `app/Http/Controllers/Admin/FinancialYearController.php`, `resources/views/admin/financial-years/…` |

## Periods

`FinancialYearService::create` enforces **non-overlapping** date ranges per hospital. `close`
freezes a period (records who/when); `reopen` unfreezes it.

## Closing is a posting boundary

A closed period **blocks new postings dated inside it**:
`FinancialYearService::assertPostingAllowed(date)` throws `ClosedPeriodException` if the date
lands in a closed period, and `BillingService::generateInvoice` calls it before issuing — so you
can't backdate an invoice into books that are closed. (Payments settle open invoices at "now",
so they're unaffected unless the current date itself is in a closed period.)

## Per-period report

`report()` rolls up, in **bcmath**, all payments (by method) and invoices whose date falls in
the period: payments received, invoiced total, outstanding balance, and a by-method breakdown —
rendered as the period's report page.

## RBAC

`finance.view` (reports) · `finance.manage` (create/close/reopen) — hospital admin + accountant.

## Tests

`FinancialYearTest` (overlap rejected, close/reopen, **posting into a closed period blocked**,
report roll-up); `FinancialYearHttpTest` (create→close→reopen, report renders, doctor no access,
tenant isolation).
