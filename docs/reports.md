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
