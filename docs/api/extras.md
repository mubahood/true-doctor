# API: suggestions, app sign-up and starter data

Envelope, auth and error codes are in [`../api.md`](../api.md). Code: `routes/api/extras.php` (authenticated),
the public routes in `routes/api.php`, controllers `InsightController`, `StarterDataController`,
`PublicRegistrationController`, logic in `app/Support/Insights/*`, `app/Support/StarterData.php`.
Tests: `tests/Feature/Api/{Insights,PublicRegistration,StarterData}ApiTest.php`.

## 1. Suggestions: `GET /api/v1/insights`

Any signed-in user. `?limit=` (1-20, default 8; otherwise 422 `validation_failed`).

```json
{ "success": true, "code": "ok", "data": {
  "insights": [
    { "key": "stock-expired", "tone": "danger", "title": "3 expired products still in stock",
      "detail": "Worth USh 120,000 on the books. Write it off so it cannot be dispensed.",
      "count": 3, "route": "admin.stock.alerts", "web_url": "https://…/admin/stock/alerts",
      "action_label": "Review expired stock" } ],
  "generated_at": "2026-10-16T08:00:00+00:00", "cache_seconds": 60 } }
```

- `tone`: `danger` > `warn` > `info` > `success`; the list is ranked by tone, then the rule's own priority.
- `route`: a **web route name** (the names in `meta.navigation`); the app maps it to a native screen and falls back to `web_url`.
- `count` may be `null` (e.g. overdue money is an amount, not a number of rows).
- When nothing applies the list is the single `all-clear` item (`tone: success`, route `admin.dashboard`). A platform account (no hospital) gets `[]`.
- Cached per user and hospital for 60 seconds, **before** the limit is applied. A rule that throws is reported and skipped; the rest still answer.

| key | tone | needs (permission, practice module, edition) |
|---|---|---|
| `setup-incomplete` | warn (required step open) / info | `manage-settings`; first open onboarding step |
| `stock-expired` | danger | `pharmacy.view`, pharmacy |
| `stock-running-out` | danger (some out) / warn | `pharmacy.view`, pharmacy |
| `stock-expiring` | warn | `pharmacy.view`, pharmacy |
| `queue-long-waits` | danger (>= 45 min) / warn (>= 20 min) | `appointments.view` (thresholds of `CheckInQueue`) |
| `appointments-not-arrived` | warn | `appointments.view` (today, 15 min past, still scheduled/confirmed) |
| `no-shows-today` | info | `appointments.view` |
| `invoices-overdue` | danger | `billing.view` (unpaid over 30 days, `ReportService::outstanding`) |
| `invoices-unpaid` | warn | `billing.view` (hidden while overdue shows) |
| `visits-stuck-open` | warn | `visits.view`; open 3+ days; a doctor sees their own |
| `lab-waiting` / `radiology-waiting` | warn | `lab.view` / `radiology.view`, module; outstanding past `OLD_HOURS` (24) |
| `claims-awaiting-action` | info / warn (submitted 14+ days) | `insurance.view`, insurance; drafts, approved, stale submitted |
| `insurer-float-short` | warn | `insurance.manage`, insurance; members owe more than the float |
| `books-catch-up` | warn | `finance.manage` (`BooksBackfill::pending`) |
| `financial-year` | warn (none open for today) / info (ends within 30 days) | `finance.view` |
| `admissions-long-stay` | info | `ipd.view`, inpatient; admitted 14+ days |
| `doctors-without-availability` | warn | `schedules.manage` |
| `subscription-ending` | danger (<= 2 days) / warn (<= 7) | `manage-settings`, **SaaS** |
| `licence-updates-ending` | danger (ended) / warn (<= 30 days) | `manage-settings`, **standalone** |

To add one: a class in `app/Support/Insights/Rules/` extending `Rule` (declare `permission()`, `module()`, `edition()`; implement `evaluate()` with aggregate queries only), then list it in `InsightEngine::RULES`.

## 2. Self-registration for the app (SaaS only; 404 on standalone)

Unauthenticated. Same `RegistrationRequest` rules, `RegistrationService` (hospital + owner `hospital_admin` + 14-day trial + welcome email) and picture check as the web `POST /register`.

| Method | Path | Throttle |
|---|---|---|
| GET | `/api/v1/public/plans` | 30/min per IP |
| GET | `/api/v1/public/human-check` | 30/min per IP |
| POST | `/api/v1/public/register` | **6/hour per IP** (the web allows 10) |

`plans`: `{trial_days, plans[{id, name, slug, description, price, price_label, billing_cycle, limits, features, is_featured, trial_days}]}`, active plans, cheapest first.

**Human check.** The web's `HumanCheck` (picture of 5 characters, single use, 10 minutes, a hidden field, and at least 3 seconds on the form) works for an app as it is, so it is mirrored rather than replaced. `human-check` returns `{human_id, human_started, image, expires_in, min_seconds}` where `image` is a `data:image/png;base64,…` URI. The app shows the picture and posts back `human_id`, `human_started` and the typed `human_answer`. Do **not** send `website` (the honeypot is `prohibited`). A wrong answer spends the challenge: fetch a new one.

`register` body: `hospital_name*`, `name*`, `email*`, `password*`, `password_confirmation*`, `plan_id*` (an active plan), `human_id*`, `human_started*`, `human_answer*`, optional `device_name`. Success is **201** with the login payload:

```json
{ "success": true, "code": "ok", "message": "Welcome! Your 14-day trial has started.",
  "data": { "token": "1|…", "user": { "id": 7, "role": "hospital_admin", "hospital": {…}, "permissions": […] },
            "password_change_required": false } }
```

Errors: 422 `validation_failed` (duplicate email or hospital name, weak password, bad plan, failed check; `errors` keyed by field), 429 `too_many_requests`, 404 on standalone. The new owner should open `GET /api/v1/admin/onboarding`.

## 3. Starter data

Same rows and the same `persistSample()` as the catalogue pages' "Import starter data" modals, from the practice's template (`SampleCatalogue`). Rows whose name already exists are skipped, so an import can be repeated.

`GET /api/v1/admin/starter-data` (`access-admin`):

```json
{ "data": { "practice": {"chosen": true, "sector": "dental_clinic"},
  "kinds": [ { "kind": "departments", "label": "Departments", "module": null, "total": 6, "imported": 2,
               "remaining": 4, "already_imported": false, "existing": 3, "can_import": true,
               "app_route": "admin.departments.index" } ] } }
```

Kinds, when the practice uses the module: `departments`, `services`, `wards` (inpatient), `lab_tests` (laboratory), `radiology_studies` (radiology), `stock_categories` and `stock_items` (pharmacy). Stock items arrive with their category and their opening quantity posted through the stock ledger. `imported` counts sample names already present; `existing` is all rows of that kind.

`POST /api/v1/admin/starter-data/{kind}`: body optional `names[]` (import only those sample names; default all remaining). Permission is the web page's: the Policy `create` for that record (`departments.manage`, `services.manage`, `wards.manage`, `lab.manage`, `radiology.manage`, `pharmacy.manage`), otherwise 403. Unknown kind, or a kind the practice does not use: 404. Returns the kind's entry above plus `imported` (rows saved by this call). A second call returns `imported: 0`, "Nothing left to import."

## 4. Onboarding hints

`GET /api/v1/admin/onboarding` steps gain two fields (existing ones unchanged):

| Step | `app_route` | `starter_kind` |
|---|---|---|
| practice / profile / billing | `admin.settings.practice` / `admin.settings.hospital` / `admin.settings.billing` | null |
| departments | `admin.departments.index` | `departments` |
| services | `admin.services.index` | `services` |
| staff | `admin.users.index` | null |
| rooms | `admin.rooms.index` | null (no importer on the web; use `POST admin/settings/practice/apply`) |
| wards | `admin.wards.index` | `wards` |
| catalogues | `admin.lab-tests.index` if the practice uses the laboratory, else `admin.stock-categories.index` | `lab_tests` / `stock_categories` |
