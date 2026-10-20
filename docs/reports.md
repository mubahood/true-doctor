# Reports & dashboards (Phase 4, Step 20)

A read-only analytics dashboard over the whole system, tenant-scoped, money in bcmath.

## Files

| Concern | File |
|---|---|
| Service | `app/Services/ReportService.php` |
| Controller / View | `app/Http/Controllers/Admin/ReportController.php`, `resources/views/admin/reports/index.blade.php` |

## What it reports (`/admin/reports`, date-range filtered)

- **Revenue** — total payments in range, broken down by method and by day (bcmath).
- **Doctor productivity** — visits + appointments per doctor, ranked.
- **Patient demographics** — counts by sex, status and age band.
- **Service revenue** — top billed services by count and revenue.
- **Stock valuation** — total current stock value + low-stock / near-expiry counts.
- **Ward occupancy** — occupied/available beds and occupancy rate.

Headline KPIs (revenue, stock value, occupancy %, patient count) render as cards; the rest as
tables. Everything comes from `ReportService`, which runs plain tenant-scoped aggregate queries
(no caching yet — fine at single-hospital volumes).

## RBAC

`reports.view` — hospital admin + accountant.

## Tests

`ReportServiceTest` (revenue by-method bcmath sums, demographics sex/age buckets, stock
valuation totals + low/expiring flags, occupancy rate); `ReportHttpTest` (admin sees the
dashboard, nurse forbidden).

## Monthly reports (`/admin/reports/monthly`)

The four books a clinic used to keep in spreadsheets, for a month (default) or
any range of whole days up to a year, each as a **Detailed table** or a
**Summary**, and each as PDF (letterhead, landscape for the table), Excel
(.xlsx) or CSV. Permission: `reports.view`. Menu: Finance, **Monthly reports**.

| Concern | File |
|---|---|
| Entry point (types, period, paging, cell formats) | `app/Services/Reports/MonthlyReports.php` |
| The period (hospital's own days, previous period) | `app/Services/Reports/ReportPeriod.php` |
| One class per report | `IncomeReport`, `ExpenseReport`, `ReceivablesReport`, `PatientRecordsReport` (all extend `MonthlyReport`) |
| Page | `app/Livewire/Reports/Monthly.php`, `resources/views/livewire/reports/monthly.blade.php` |
| Files | `app/Http/Controllers/Admin/MonthlyReportController.php`, `resources/views/pdf/monthly-report.blade.php`, `App\Support\Imports\XlsxWriter` |
| API | `app/Http/Controllers/Api/V1/MonthlyReportController.php` ([`api/reports.md`](api/reports.md)) |

Where each figure comes from, so it agrees with the page it is checked against:

- **Income**: payments by when they were taken and approved refunds (negative
  lines) by when they were approved, on the hospital's calendar days. The
  month is exactly the sum of its cashier's days (`CashierDaySummary`). Credit
  notes move no money and are shown beside the figures. "By service" shares
  each payment across its bill's lines in proportion, to the cent (the last
  line takes the rounding).
- **Expenditure** (type key `expenses`): the expenditure book, cash basis.
  Every Finance "Money out" entry, whatever it paid for, and every stock
  delivery paid from a money book on the spot (a system entry from
  `Bookkeeper::stockMoved` with a book on its other side), one line per
  account, each with its kind (Running expense, Stock purchase, Equipment and
  other assets, Paying a supplier, Paying what is owed, Owner drawings, Cost
  of sales). **Running expenses** (expense accounts other than cost of sales)
  is its own total and equals the income statement's running costs when they
  came through Money out; "Total paid out" is everything. A reversed entry
  stays in its own month and its reversal counts negative in its month.
- **Accounts receivable**: while the period runs, exactly the debtors list
  (`Invoice::outstanding()`, stored balance, `DebtorsService` bands). For a
  period that has ended, each balance is worked back to the last day from its
  payments, refunds and credit notes. Void invoices never count. The summary
  adds what was owed at the start, what was collected from it in the period,
  and the ten largest balances by patient.
- **Patient records**: visits in the period, not cancelled; the presenting
  complaint and the diagnosis are blank, and Top diagnoses left out, for a
  reader without `visits.clinical` (an accountant), in every format; "new" is the
  patient's first visit here (the HMIS 105 rule); the diagnosis is the coded
  one in force, else the written one; treatment is what was charged, with the
  tooth for a dental procedure; billed, paid and balance are the visit's
  invoice.

The clinician column is "Dentist" for a dental clinic and "Doctor" otherwise
(`MonthlyReport::clinician()`, from the practice type).

Large months: summaries are database sums plus one chunked pass (500 rows at a
time). A page of the detailed table asks the database for that page only
(offset and limit; income pages one ordered union of payments and refunds); a
past receivables period works each balance out first. Counts, totals and
summaries are kept for 60 seconds per hospital, report, period and reader. CSV
and Excel stream every line from chunked queries, the workbook written to
temporary files that are removed even when a report fails. A detailed PDF
prints the first 1,500 lines and says how many more are in the Excel and CSV
files; its totals cover every line. CSV text cells that start like a formula
get a quote in front. With no hospital in context (a super admin who has not
opened one) every entry point answers 404 and the menu does not offer it.

Tests: `tests/Feature/MonthlyReportsTest.php` (agreement with the cashier's
day, the income statement and the debtors list; tenancy; permissions; every
export; the API; the demo).
