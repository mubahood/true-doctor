# 02 · Backend

Laravel 12, PHP 8.2+ (MAMP 8.3.9 locally), Livewire 3, Sanctum, DomPDF (barryvdh), PHPUnit on SQLite in-memory, Pint, PHPStan. Production DB is MySQL/MariaDB **InnoDB** (a client's shared host defaulted to MyISAM; `config/database.php` now sets `engine => InnoDB`, guarded by `DatabaseEngineTest`).

## Layers and who may do what

| Layer | Allowed | Not allowed |
|---|---|---|
| Model | Relations, casts, scopes, small accessors, data hygiene in `booted()` | Billing, dosing, state changes, anything with side effects on `save()` |
| Service (`app/Services`) | The business rules. Transactions. Locks. Events. History rows. | HTTP concerns, views |
| FormRequest | Validation, `rulesFor($editingId)` shared by web and API | Business decisions |
| Policy | Authorisation by permission string (`patients.view`, `billing.view`, `ipd.view`, …) | Queries |
| Livewire component / API controller | Authorise → validate → call Service → respond | Logic, hand-written queries beyond display |
| Resource | Shape the JSON | Logic |

Permissions are strings from RBAC (`docs/rbac.md`); the API mirrors the web exactly. A section a user cannot see is **omitted** from a response, not returned empty (see the patient summary).

## Tenancy

- `App\Support\CurrentHospital` holds the resolved tenant. `HospitalScope` treats "no tenant resolved" as *unscoped* (so console/queue/seeders work) and a resolved tenant as always filtered.
- `User` cannot carry the scope (super-admins have `hospital_id = null`), so user lists are scoped explicitly in their controller, with an isolation test.
- Middleware priority is set so `Authenticate → ResolveHospital → SubstituteBindings`. Do not reorder it.
- `exists:` and `unique:` rules need the hospital filter written into the rule.
- `Super\*` components and the platform screens call `withoutGlobalScope(HospitalScope::class)` deliberately.
- On a standalone copy database triggers make a second hospital impossible (`SingleEnterpriseGuard`).

## Money

- Amounts are strings with two decimals from FormRequest to column; use bcmath (`bcadd`, `bccomp`). Format only at the edge (`x-ui.money`, `HospitalSettings` currency). API responses carry `"25000.00"` plus a `*_label` string.
- Sequences (invoice numbers, visit numbers, patient numbers) come from `App\Support\Sequence`, never `max()+1`.
- One invoice per visit; payments reduce the balance through `BillingService`; refunds and voids are separate, audited operations.
- "Outstanding" has one definition (issued or part-paid with a balance left; void never counts) shared by the reports and the patient page. Don't invent a second one.

## The API (`/api/v1`)

- Files: `routes/api.php` plus `routes/api/{care,pharmacy,finance,admin,extras}.php`; controllers in `app/Http/Controllers/Api/V1`. Docs for every endpoint group: `docs/api.md` and `docs/api/*.md`. **Update the doc in the same change.**
- Envelope for everything: `{ success, code, message, data, errors }`, lists add `meta`. Errors are rendered centrally in `bootstrap/app.php`: 401 `unauthenticated`, 403 `forbidden`, 404 `not_found`, 422 `validation_failed`, 429, 500. Use `App\Support\ApiResponse`; never return a bare array.
- Auth is Sanctum bearer tokens only. Rate limit is 240/min per user (the app makes ~30 calls at sign-in; don't lower it casually).
- Records are addressed by **uuid**, never numeric id.
- `EnsureAppVersion`: apps send `X-App-Version`; below `services.mobile.min_version` the answer is **426** and the app shows its update screen. Requests without the header (web, Field Mode) pass. Keep old clients working unless you raise the minimum on purpose.
- Sync lives under `/api/v1/sync/*` (protocol in `docs/OFFLINE_SYNC_PROTOCOL.md`). Its contract is versioned; don't change shapes in place.
- Any new GET that the phone will cache should be cheap, deterministic and safe to serve stale.

### Idempotent writes

`App\Http\Middleware\IdempotentRequests` replays the stored response when the same `Idempotency-Key` arrives again for the same user, route and body (table `idempotency_keys`, pruned daily). The phone sends a key on every write and **reuses it after a no-answer failure** (timeout, dropped connection), so a payment that reached the server but whose reply was lost is not charged twice.

- A new write endpoint gets this for free if it is in the API group; check the route is covered and add a test that a replay returns the same response and creates one row.
- Known gap (documented in `docs/api/idempotency.md`): a crash between "work committed" and "key stored" can still repeat the work once. Money flows additionally rely on their own natural guards.

### Insights ("Worth a look")

`app/Support/Insights/Rules/*` + `InsightEngine`; endpoint returns `{ insights: [...] }` (an object with a list — a list-only response once broke the app). A rule: cheap query, hospital-scoped, role-aware, plain sentence, one action route. Add a unit/feature test per rule.

## PDFs and other generated documents

- DomPDF via `resources/views/components/pdf/document.blade.php` (letterhead, brand, `@page` margins). All printables go through it so branding and layout stay uniform: invoice, receipt, visit report, patient ID card, period report, financial statement, patient statement.
- `AppServiceProvider::pointPdfAtAFolderThatExists()` sets DomPDF's `public_path` to a folder that exists and turns on font subsetting. This matters: on a standalone copy the web root is *beside* the app (split layout), and a missing `public_path` made every PDF a 500 (Divine Dental, Oct 2026). Embedded fonts are subset (1.9 MB → ~180 KB).
- `tests/Feature/GeneratedDocumentTest.php` renders every document, including under the split layout and with a size ceiling. **Add every new printable to it.**
- The statement/PDF endpoints return `Content-Type: application/pdf` inline; the phone opens them in the viewer.

## Background work and cPanel reality

- Scheduler runs from one cron (`schedule:run`). On live it runs every 19 minutes, so queue jobs may start late; do not promise instant.
- Standalone builds use classmap-authoritative autoload: **a new class file that is not in the shipped classmap does not exist** on a client's copy. Adding a class in a hotfix by uploading a file will 500 the site. Put hotfix code in a class that already exists. (Real incident, [08](08-lessons.md).)
- No `exec`, no shell, no Redis assumptions. Use database queue/cache drivers unless the edition guarantees otherwise.

## Migrations

- Filenames sort lexically: a migration must be dated after the ones it references (an early date once ran before `create_hospitals` and broke the FK).
- Never edit a shipped migration; add a new one. Standalone copies run them on update with an automatic restore if they fail, so they must be safe on real data and on MySQL *and* SQLite.
- Add indexes for anything the phone filters or syncs by.

## Security basics that are always on

Encrypted casts for sensitive columns (`bank_details`, card numbers with an HMAC lookup hash); private disk + policy-gated streaming for documents; `NoStoreResponses` and `SecurityHeaders`; throttled login; password policy; role-escalation tests; no secrets in git (CI scans). Never log patient data or tokens.
