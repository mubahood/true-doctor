# True-Doctor — Master Plan: Registry → SaaS Hospital Management System

**Decisions locked in:** reuse the existing Laravel 12 shell · full-hospital module set ·
multi-tenant **SaaS on subscriptions** · this document drives implementation.

**Inputs to this plan (three codebases):**

1. **This repo (True-Doctor, Laravel 12)** — the shell we keep and build on.
2. **Legacy hospital repo audit** (`/Applications/MAMP/htdocs/hospital`, Laravel 8 EOL,
   `encore/laravel-admin` + JWT API for a separate React frontend) — the "what went wrong" reference.
3. **GlobalHealth HMS analysis** (same Laravel 8 lineage) — the feature inventory and
   hardening roadmap reference.

The legacy audits are binding on this plan in two ways: their **feature inventory** defines what
the new system must cover (§4, §5), and their **failure patterns** define hard design constraints
we must not violate (§3). Nothing from either document is dropped; every finding is mapped below.

---

## 1. What we keep, what we drop (True-Doctor shell)

### Keep
| Asset | Reuse as |
|---|---|
| Laravel 12 base (in support — legacy systems were on EOL Laravel 8; per audit G, never start on an EOL version), Breeze auth, `/admin` shell (layouts, sidebar, Blade components, Tailwind) | HMS back-office UI |
| Spatie roles/permissions | Per-tenant RBAC (replaces `encore/laravel-admin`'s DB-table RBAC from the legacy systems) |
| Spatie activity log + `status_histories` pattern | Clinical & billing audit trail (legacy `AuditLogging` middleware was a 0-byte stub — here it's real from day one) |
| Service-class architecture (thin controllers → Services → Models) | The layer the legacy systems scaffolded (`app/Services/`, `app/Repositories/` all 0-byte) but never implemented — mandatory here (constraint A1) |
| `VerificationCode` generator + QR + DomPDF certificate infra | Patient numbers (`PT-2026-000123K`), consultation numbers, invoice/receipt numbers, QR patient ID cards, PDF invoices/reports |
| Credential upload/review workflow (private disk) | Patient documents, lab/radiology result attachments, insurance documents, treatment photos |
| Horizon/Redis queues, mail, Intervention image, settings k/v, `districts` | As-is |
| Sanctum (already installed) | The **single** API auth mechanism (constraint C16) |

### Drop / retire (move to `_legacy`, excluded from autoload)
Registry domain: `registrations`, `categories`, `regulatory_bodies`, `renewals`,
`institution_members`, `fraud_reports`, `verification_logs`, `routes/verify.php`,
`VerifyController`, `PublicController`, `RegistrationService`, `VerificationService`, and the
public scan-first home (replaced by SaaS marketing/landing + tenant login).

Per constraint A3, retirement is a **hard delete from the live tree** in one change — no dead
controllers, unrouted duplicates, or 0-byte files left looking like features.

---

## 2. Target architecture

```
                    ┌────────────── SaaS layer (central) ──────────────┐
                    │ hospitals (tenants) · plans · subscriptions ·    │
                    │ subscription_payments · super-admin panel        │
                    └──────────────────────┬───────────────────────────┘
                                           │ hospital_id on every row
┌──────────────────────────────────────────▼───────────────────────────────────────┐
│  HMS modules (per tenant)                                                        │
│  Patients (+dependents/guardians, cards) → Appointments → Encounters (OPD)       │
│  → Orders (lab/radiology/pharmacy) → Admissions (IPD: wards/beds/rooms)          │
│  → Nursing (vitals/doses/notes) → Discharge                                      │
│  Billing (invoices/payments/card-credit/insurance claims) ← every chargeable act │
│  Pharmacy inventory · Lab · Radiology · HR/staff · Events/meetings ·             │
│  Financial years · Notifications · Reports/analytics                             │
└──────────────────────────────────────────────────────────────────────────────────┘
```

### 2.1 Multi-tenancy (constraint B8 — the legacy cautionary tale)
The legacy system ran **two competing tenancy keys** (`company_id` used in 33 files but hardcoded
to `1`; `enterprise_id` newer with "CRITICAL: tenant isolation" comments but the `Enterprise`
model itself an empty file) and **no global scope** — a real cross-tenant data-leak risk. Here:

- **One key, one mechanism**: single DB, `hospital_id` column on every tenant table.
- `Hospital` model (name, slug, logo, address, timezone, currency, settings JSON, status) —
  a real implemented model, seeded and tested, not a stub.
- `BelongsToHospital` trait: global Eloquent scope + auto-fill on create. Applied to **every**
  tenant model; never manual per-query filtering.
- Current hospital resolved from `users.hospital_id`; super-admin (`hospital_id = null`) can switch.
- All unique indexes composite with `hospital_id` (patient no, invoice no, card no unique per hospital).
- Decided up front (per B8): tenancy is **per-hospital**; branches within a hospital group are a
  `branch_id` sub-scope added later without changing the isolation boundary.
- Middleware `EnsureSubscribed` blocks tenant routes when subscription lapses (configurable grace).
- **Isolation test suite is a deliverable**: automated tests assert hospital A can never read/write
  hospital B's rows, on every module, via UI and API.

### 2.2 Subscription billing (SaaS)
- `plans` (name, price, billing_cycle, limits JSON: max_users, max_patients, modules enabled),
  `subscriptions` (hospital_id, plan_id, starts_at, ends_at, trial_ends_at, status),
  `subscription_payments`.
- Start manual/mobile-money-friendly (record payment → extend). Gateway (Flutterwave — proven in
  the legacy system for Ugandan mobile money — or Stripe/Pesapal) behind a `PaymentGateway`
  interface in Phase 4. **Gateway secrets live only in config/env** — the legacy repo hardcoded a
  live Flutterwave secret key inside a model file (twice); that is banned here (constraint C11).
- Feature gating: `hospital->hasModule('lab')` from plan limits.

### 2.3 Application layering (constraints A1, A2, B9, B10)
Legacy failure: fat models (`Consultation` 792 lines, `User` ~28KB) doing cross-entity
orchestration in `boot()` hooks, plus one reflection-driven generic CRUD endpoint
(`ApiResurceController`, `api/{model}`) driving writes for arbitrary models — the source of the
entire documented "FIX" bug history (virtual attributes leaking into INSERTs, missing `$fillable`,
`"true"` string booleans, `'Active'` written to integer columns, relation-loading crashes,
route-ordering bugs behind a catch-all route). Binding rules for this build:

1. **Thin controllers → single-purpose Services** (`PatientService`, `AppointmentService`,
   `ConsultationService`, `BillingService`/`ConsultationBillingService`,
   `DosageScheduleGenerator`, `StockDeductionService`, `NotificationService`…), each
   unit-testable in isolation. Model `boot()` hooks only for pure data hygiene (uuids, slugs,
   number generation) — **never** cross-entity orchestration (creating a patient, generating
   invoices, deducting stock).
2. **No generic reflection-driven writes.** A generic *read* query-builder (filter/sort/
   paginate/field-select/date-range/eager-load/CSV export — the useful part of the legacy
   pattern) may be shared, with an **explicit model allow-list** (legacy relied only on
   `class_exists`). All **writes** go through typed Form Requests / DTOs per resource.
3. **No transient/staging attributes on Eloquent models.** Patient-intake-during-consultation
   uses a request DTO/form object; nothing throwaway is ever assigned onto a model instance
   (kills the "temporary fields leaking into INSERT" bug class, B9).
4. **Typed at the boundary** (B10): `$casts` on every model, Form Request rules per field,
   booleans/enums normalized at the API edge — never patched deep in model hooks.
5. **No magic strings**: PHP 8 backed enums for every status
   (`ConsultationStatus::Completed`, `PaymentStatus::PartiallyPaid`, …).
6. **No silent duplicates or scaffold debris** (A3, A4): mid-project pivots delete the old path
   in the same change (legacy had two patient models, three auth mechanisms, two tenancy keys,
   `PatientController` vs dead `PatientsController`, `ConsultationController` vs 0-byte
   `OptimizedConsultationController`, `TaskOldController` with a colliding class name, ~25
   same-day stub files). **CI check fails the build on empty/stub PHP class files.**
7. **Explicit, enforced state machines** (A5): every workflow (consultation, appointment,
   admission, invoice, claim, card) is an enum + guarded transitions inside its Service +
   history table + activity log — not a mutable status column filtered differently by five
   screens (legacy reused one Consultation grid five times: Consultation/Billing/Payment/Dose/
   ProgressMonitoring controllers as status filters). We may still present role-specific
   *views* of a pipeline, but the state machine underneath is singular and enforced.

---

## 3. Design constraints from the legacy audits (binding, in full)

### A. Architecture & code organization
Covered in §2.3: service layer not fat models (A1); no generic writes (A2); no coexisting
duplicate implementations (A3); scaffolding tracked + CI gate on empty files (A4); explicit
state machines (A5).

### B. Data model
- **B6 — Patient is a first-class model**, never a `user_type` flag on the staff table (the
  legacy `admin_users`-as-patients design caused identity fragmentation with an abandoned dental
  `Patient` model coexisting). Separate `Patient`, `User` (staff), and `Guardian/Dependent`
  entities with explicit relations. Patients need different fields (medical history, insurance,
  guardians, consent), usually **no login**, and different retention/privacy rules.
- **B7 — Appointments/scheduling are core, phase 1** — legacy stubbed `AppointmentController`,
  `DoctorScheduleController`, `RoomController` and never built them. We build doctor calendars,
  slot/conflict detection, room/resource booking, and reminders as first-class features.
- **B8 — single enforced tenancy** (§2.1).
- **B9 — DTOs for staging data** (§2.3.3).
- **B10 — typed columns + boundary validation** (§2.3.4). Also: never ship a silently-broken
  migration (legacy `create_departments_table` had `return true;` before `Schema::create` — a
  no-op); CI runs `migrate:fresh` so broken migrations fail loudly.

### C. Security (healthcare-specific — every item is a requirement, not advice)
- **C11 — secrets hygiene**: `.env` git-ignored from the first commit (legacy committed `.env`
  with DB password, `APP_KEY`, `JWT_SECRET`, mail + AWS keys — its most serious finding);
  `.env.example` placeholders only; all third-party keys (payment, SMS, mail, storage) from
  config/env, never hardcoded; **secrets-scanning check in CI/pre-commit**.
- **C12 — treat as a healthcare data system**: encryption at rest for PII/PHI fields
  (Eloquent `encrypted` casts for card numbers, bank details, sensitive clinical fields);
  comprehensive audit logging of **who viewed/edited which patient record and when** (reads,
  not just writes); configurable data-retention / right-to-erasure support; **field-level RBAC**
  (e.g. billing staff see amounts, not clinical notes), not just screen-level.
- **C13 — authorize per-role on every API endpoint** via Laravel Policies/Gates mirroring the
  admin-panel RBAC, so the two surfaces can't drift (legacy: admin panel enforced roles, JWT API
  only authenticated).
- **C14 — no predictable default passwords** (legacy: `'4321'` staff, `'patient123'` inline
  patients; True-Doctor's own seeded `4321` also goes). Random temporary password + forced
  first-login reset, or invite/magic-link onboarding. Password policy enforced. Patients get no
  credentials unless a portal is enabled.
- **C15 — CORS locked to known origins** in production (legacy: `['*']` everywhere); **no
  substring-matching auth bypass** (legacy `JwtMiddleware` skipped auth for any URI containing
  "login"/"otp"/"register") — exempt routes by exact route name lists only.
- **C16 — exactly one API auth mechanism**: **Sanctum** (legacy ran JWT with ~1-year TTL +
  mostly-dead Sanctum + a custom `EnsureTokenIsValid` middleware trusting an unsigned client
  `User-Id` header). Token expiry sane; refresh handled properly.
- **2FA for admin roles**; session security headers; **rate limiting** (`throttle`) on all API
  routes (legacy `ApiRateLimit`/`ApiSecurity` middleware were 0-byte and unregistered); no
  sensitive data in logs; API key rotation supported; no raw unparameterized SQL.

### D. API design
- **D17 — keep the manifest pattern** (the one legacy pattern explicitly worth keeping):
  `GET /api/v1/manifest` returns config, role-filtered navigation, permissions, and reference/
  lookup option-sets in one cached payload; `manifest/public` unauthenticated variant;
  reliable cache invalidation; payload growth monitored so it doesn't become unbounded.
- **D18 — version the API from day one**: everything under `/api/v1/…` (important for the
  slower release cadence of a future mobile client).
- **D19 — offline sync is a deliberate decision, not scaffolding** (legacy `SyncQueue`/
  `SyncRecord`/`OfflineSyncController` were 0-byte and unreferenced; `routes/mobile-api.php`
  empty and never loaded). Phase 0 records the decision: **out of scope for v1**; if/when built
  for field/rural use, design conflict resolution (last-write-wins vs merge), on-device PHI
  encryption, and queue-and-replay semantics up front.
- **D20 — one lookup/dropdown mechanism** (legacy had three overlapping ones): a single
  allow-listed `GET /api/v1/options/{resource}?q=…` endpoint reused by admin panel and API.
- **Standardized response envelope** (`success/code/message/data`) + consistent error format +
  custom exceptions (`PatientNotFoundException`, `InsufficientFundsException`, …).
- **OpenAPI/Swagger documentation generated from day one** (legacy `API_DOCUMENTATION.md` was a
  0-byte placeholder).
- Pagination on **every** list endpoint; gzip/response compression; response caching where safe.

### E. Testing, quality & documentation
- **E21 — tests are not optional** for a system that computes bills, deducts inventory, and
  manages medication dosing (legacy had near-zero coverage and debugged in production via
  `\Log::info()`). Minimum: **unit tests** for invoice/billing calculation, stock deduction,
  dosage-schedule generation, card-credit math, subscription expiry; **feature tests** for the
  consultation lifecycle, patient registration, payments, and tenancy isolation; target **>80%
  coverage on financial/clinical calculation code**; CI gate blocks empty/stub PHP files.
- **E22 — no ad hoc debug scripts at repo root** (legacy committed 4 manual PHP/bash debug
  scripts): repeatable tests in `tests/` or nothing.
- **E23 — one canonical doc per feature** (legacy had 11+ root markdown files, four describing
  the same CSS redesign, two 0-byte "documentation" placeholders): docs live in `docs/`,
  updated in the same PR as the code; this file is the single planning source of truth.
- Tooling: **Pint** (style), **PHPStan** (static analysis), type hints throughout, CI/CD
  pipeline (tests + Pint + PHPStan + secrets scan + empty-file check + `migrate:fresh`).

### F. Data consistency & transactions
- **All financial operations wrapped in DB transactions** (payment save + balance recompute +
  card debit + stock deduction commit or roll back together — legacy could half-apply).
- Guard against **race conditions** on concurrent payments/stock (row locking /
  `lockForUpdate` on balances and quantities); **optimistic locking** on concurrent edits of
  clinical records; cascade/soft-delete rules explicit so no orphaned records.
- Money columns `decimal(12,2)`; append-only ledgers for payments and stock movements.

### G. Performance & observability
- Eager-loading conventions per module (no N+1 — legacy's known problem); indexes on every FK
  and hot filter column (`patient_id`, `status`, `created_at`, `hospital_id` composites);
  Redis cache for manifest/lookups/reports; query scopes; pagination defaults.
- **Health-check endpoint** (`/health`: DB, cache, queue), **structured logging** (JSON context:
  ids, actor, timestamp), **error tracking (Sentry)**, Telescope + Horizon dashboards locally,
  APM (New Relic/DataDog) optional in production, uptime monitoring + alerting on critical
  failures, performance dashboards.

---

## 4. Data model (new tables — full inventory)

**Central (SaaS):** `hospitals`, `plans`, `subscriptions`, `subscription_payments`.

**People & identity:**
- `patients` — first-class (B6): `patient_no` (generated, checksummed), uuid, demographics
  (first/last name, dob, sex), contacts (phone_1, phone_2, email, current/home address,
  district), medical (blood type, allergies JSON, chronic conditions JSON), family/emergency
  (spouse, father, mother, emergency contact name+phone), optional extras the legacy captured
  (education history, bank details — encrypted), insurance info, consent records, photo.
- `patient_dependents` — guardian/dependent links (relationship, status) — legacy's
  `is_dependent`/`dependent_id` flags become a real relation.
- `patient_cards` — the legacy prepaid card system, kept as a module: card_number (generated),
  expiry, status machine (Active/Inactive/Suspended), accepts_credit, max_credit, balance;
  `card_records` append-only debit/credit ledger. Card numbers **encrypted at rest**.
- `patient_documents` — reuses credential-review pattern.
- `staff_profiles` — extends `users`: specialty, license no, qualifications, signature,
  department, schedule JSON.
- `departments` (head, description), `rooms` (ward link, type, status).

**Scheduling (B7):** `doctor_schedules` (availability templates), `appointments` (patient,
doctor, department, room, date/slot, source, status machine:
scheduled → confirmed → checked_in → in_progress → completed / no_show / cancelled),
conflict detection in `AppointmentService`, reminder notifications (queued).

**Clinical flow:**
- `consultations` (= encounters): consultation_no (generated `Y-m-d-N` style), patient,
  receptionist, specialist/doctor, department, appointment link, reason,
  services_requested, vitals (temperature, weight, height, computed BMI), complaints,
  diagnosis, doctor/receptionist/patient remarks, status machine
  (Registration → Triage → Consultation → Orders → Pharmacy/Billing → Payment → Completed),
  plus billing rollups (subtotal, fees_total, discount, total_charges, total_paid, total_due,
  invoice_processed) maintained **only** by `ConsultationBillingService` inside transactions.
- `medical_services` + `medical_service_items` (billable service lines, priced from
  `service_items` price list or stock items), `services` catalogue.
- `prescriptions` → `dose_items` (drug, dosage, frequency, days) →
  `dose_item_records` generated by `DosageScheduleGenerator` (per-day Morning/Afternoon/
  Evening/Night slots, administration status) — the legacy dosing model, rebuilt as a tested
  service instead of model-hook side effects.
- `treatment_records` + `treatment_record_items` — procedures with multi-photo attachments
  (JSON array of images), full patient treatment timeline. (Specialty charting such as the
  legacy dental tooth-map becomes a category-specific `meta` JSON schema, not separate tables.)
- `lab_orders` + `lab_order_items` + `lab_results` (specimen, values, flags, result PDF,
  attachments); `radiology_orders` + `radiology_results` (same pattern).

**Inpatient (IPD):** `wards`, `beds`, `admissions` (status machine: admitted → transferred →
discharged / deceased / absconded), `bed_transfers`, `nursing_notes`, `vital_records`
(rounds), `medication_administrations`, daily bed charges → billing.

**Pharmacy/Inventory:** `stock_item_categories` (measuring units), `stock_items`
(original_quantity, current_quantity, cost_price, sale_price, current_stock_value, reorder
level, batch/expiry), `stock_movements` (append-only in/out ledger — replaces legacy
boot-hook auto-recalc with `StockDeductionService` in transactions), `stock_out_records` /
dispensations against prescriptions, delete-protection on items with movement history,
**low-stock and expiry alerts** (legacy's empty `InventoryAlerts` widget, actually built).

**Billing & finance:** `service_items` (per-hospital price list), `invoices` +
`invoice_items` (auto-generated from consultations, services, labs, drugs, bed-days; PDF via
DomPDF), `payments` (methods: Cash, Card, Mobile Money/Flutterwave, Insurance; statuses:
Pending/Success/Failed; append-only), `gateway_logs` (Flutterwave transaction log + server-side
verification callback), `insurance_providers`, `patient_insurance`, `insurance_claims`
(lifecycle state machine), `financial_years` (accounting periods with close — stubbed in
legacy, real here), refunds as explicit reversing entries.

**Organization & ops:** `events` (priority, dates, outcomes, reminders, participant
notification), `meetings`, `targets`, `tasks` (assignment + status) — kept lightweight;
projects/clients from the legacy PM module are **out of scope** for an HMS unless requested.

**System:** `notifications` (DB + mail/SMS/push channels; `device_tokens` for push),
`settings` k/v (reused), `report_definitions` (saved reports), activity log (Spatie) +
per-domain history tables. Geofencing/`device_locations` (legacy orphan): **out of scope**,
recorded as a conscious decision. `images`/media via existing upload infra.

**Conventions:** uuid + soft deletes on clinical records; every status transition writes a
history row + activity log; all money `decimal(12,2)`; every tenant table has `hospital_id` +
composite indexes; `$casts` complete on every model.

---

## 5. Roles & permissions (per tenant, Spatie)

Super Admin (SaaS central) · Hospital Admin · Doctor · Nurse · Receptionist · Pharmacist ·
Lab Technician · Radiologist · Accountant/Cashier · (optional) Records Officer.

Permission naming `module.action` (`patients.create`, `consultations.diagnose`,
`billing.refund`, `pharmacy.dispense`, `stock.adjust`, `reports.financial`…), seeded per role;
enforced identically in Blade admin **and** API Policies (C13); field-level rules for clinical
vs financial data (C12).

---

## 6. API surface (v1)

```
POST /api/v1/auth/login|logout|refresh      Sanctum; rate-limited; 2FA where enabled
GET  /api/v1/manifest  /manifest/public     bootstrap payload (D17), POST /manifest/clear-cache
GET  /api/v1/options/{resource}?q=          single dropdown mechanism (D20, allow-listed)
CRUD /api/v1/patients, /appointments, /consultations, /medical-services(+items),
     /prescriptions, /dose-records, /lab-orders, /admissions, /stock-items,
     /invoices, /payments …                 typed FormRequests per resource; Policies per route
POST /api/v1/payments/card                  card-credit debit
POST /api/v1/payments/gateway + /verify     Flutterwave (server-side verification)
POST /api/v1/profile, /password-change, /account-delete, /media-upload
GET  /health
```

Envelope, pagination, versioning, OpenAPI docs, compression per §3.D.

---

## 7. Phased implementation

### Phase 0 — Foundation & guardrails (~1 week)
1. Branch `hms-restructure`; delete registry domain to `_legacy` (A3).
2. CI pipeline first: tests + Pint + PHPStan + secrets scan + empty-stub-file check +
   `migrate:fresh` (C11, A4, B10). Verify `.gitignore` covers `.env`; rotate anything exposed.
3. Tenancy core: `hospitals/plans/subscriptions` migrations, `BelongsToHospital` trait +
   global scope, `EnsureSubscribed` + `ResolveHospital` middleware, `users.hospital_id`,
   first tenancy-isolation tests.
4. Auth hardening: Sanctum-only API, remove seeded `4321`, invite-flow + forced reset (C14),
   CORS config per env (C15), rate limiting, response envelope + exception handler + enums.
5. HMS roles/permissions seed; admin shell re-skinned with HMS sidebar; super-admin panel
   (hospitals, plans, subscriptions, record payments); marketing/landing + login.
6. Record scope decisions: offline sync deferred (D19), geofencing out, projects module out.

### Phase 1 — Core clinical (~2–3 weeks)
7. ✅ **Patients**: registration, generated patient no, QR ID card PDF, search/filter, documents,
   dependents/guardians, patient cards + card ledger (encrypted). *(done — commits b0fd8cf, 9d7f335)*
8. ✅ **Staff & org**: staff profiles, departments, rooms. *(done)*
9. ✅ **Scheduling**: doctor schedules, appointment booking with conflict detection, check-in
   queue, reminders (B7). *(done — reminders deferred to the notifications pass)*
10. ✅ **Consultations**: state machine, vitals + BMI, notes/diagnosis/remarks, prescription
    writing + `DosageScheduleGenerator`, inline new-patient intake via DTO (the legacy's most
    bug-ridden flow, rebuilt safely per §2.3), pipeline views over the single state machine.
    *(done — 10a encounter core `96fd341`; 10b prescriptions/dosing/intake. Medical service
    orders + priced catalogue moved to Step 11 where they're priced & invoiced.)*
11. ✅ **Billing v1**: per-hospital configurable currency/tax/fees, price list, medical service
    lines, auto-invoice, cash + prepaid-card payments in transactions (card ties to the Step 7
    ledger), balance/partial-payment tracking, receipt & invoice PDFs. Unit tests for every
    calculation. *(done — 11a config/catalogue/lines `5abd689`; 11b invoices/payments/PDFs.)*

### Phase 2 — Pharmacy & diagnostics (~2 weeks)
12. ✅ **Pharmacy/stock**: categories + measuring units, stock items with valuation, append-only
    movements, `StockService` deduction on dispensing (atomic deduct + bill), delete protection,
    low-stock + expiry alerts. *(done — 12a engine `18edfa1`; 12b dispensing.)*
13. ✅ **Lab** then **Radiology**: catalogues, ordering from consultation, results + PDFs +
    attachments, auto invoice items.
14. ✅ **Treatment records** with multi-photo support. *(done)*

### Phase 3 — Inpatient (~2 weeks)
15. ✅ Wards/beds/rooms, occupancy board; admissions state machine; bed transfers + daily bed
    charges → billing; nursing notes, vitals rounds, medication administration records;
    discharge summary PDF + final invoice consolidation. *(done — 15a admissions core `3b061ed`;
    15b nursing rounds + MAR.)*

### Phase 4 — Money, comms & insight (~2 weeks)
16. ✅ **Gateway payments**: Flutterwave adapter behind `PaymentGateway` interface, server-side
    verification, gateway log, idempotent settle (callback + signed webhook), secrets in env only.
    *(done)*
17. ✅ **Insurance**: providers, patient coverage, claims lifecycle (Paid claim settles the invoice). *(done)*
18. ✅ **Financial years**: accounting periods, period close (posting boundary), per-period reporting. *(done)*
19. ✅ **Notifications**: appointment reminders, lab-result ready, low stock — database/SMS via
    queued channels + `device_tokens`, SMS behind the swappable VerificationChannel. *(done)*
20. ✅ **Reports & dashboards**: daily revenue, doctor performance/productivity, patient
    demographics/statistics, service usage & revenue, stock valuation, ward occupancy;
    per-hospital dashboard + super-admin SaaS metrics; events/meetings/tasks module.
21. ✅ **SaaS polish**: self-signup + trial, plan limit enforcement, paid-plan checkout (Flutterwave), onboarding wizard, subscription emails. *(complete)*

### Phase 5 — Hardening & observability (ongoing gate before production)
22. Coverage targets met (>80% on financial/clinical logic); full tenancy-isolation suite;
    load test (target ~1000 req/s capable path, p95 < 200ms API); 2FA for admins; security
    audit pass (OWASP A-grade goal); Sentry + health checks + structured logging + uptime
    alerts wired; backup & retention strategy; seeded demo hospital; OpenAPI docs published.

### Future (explicitly deferred, per D19/audit F)
Offline sync for field use (designed intentionally when needed), WebSockets/real-time status
boards, patient portal login, multi-branch sub-tenancy, AI/analytics (no-show prediction,
revenue forecasting), biometric auth on mobile.

---

## 7b. API v1 status (cross-cutting §6 / C13)

✅ Sanctum-authenticated JSON API shipped: `auth/*`, `patients`, `appointments` (+transition),
`consultations` (+vitals/clinical/transition), and read-only `stock-items` / `lab-orders` /
`invoices` — all reusing the same Services + Policies as the panel, one ApiResponse envelope,
tenant-scoped. Remaining API work (OpenAPI docs, more write resources) tracked under Step 22.

## 8. Success metrics (from the GlobalHealth audit, adopted as targets)

| Metric | Target |
|---|---|
| API response time (p95) | < 200 ms |
| DB query time (hot paths) | < 100 ms |
| Error rate | < 0.1% |
| Test coverage (financial/clinical logic) | > 80% |
| Security grade (OWASP) | A |
| Load handling | ~1000 req/s |
| Uptime | > 99.95% |

---

## 9. Do-not-repeat register (anti-patterns from both audits — checked in code review)

1. `.env`/secrets in git; hardcoded gateway keys in code.
2. Default passwords (`4321`, `patient123`); plaintext card/bank numbers.
3. Patients as flagged rows on the staff/users table; two patient models.
4. Two tenancy keys; empty tenant model; manual per-query tenant filtering.
5. Three coexisting auth mechanisms; substring-based auth bypass; ~1-year token TTL;
   unsigned client-trusted headers; open CORS.
6. Generic reflection writes for arbitrary models; catch-all `api/{model}` route shadowing
   specific routes; no model allow-list.
7. Cross-entity orchestration in model boot hooks; transient attributes on models; missing
   `$fillable`/`$casts`; strings into integer columns; unvalidated `$request->all()` updates.
8. Financial ops without transactions; balance/stock races; magic status strings.
9. 0-byte scaffold files shipped as "features"; duplicate old/new controllers left in tree;
   dead middleware named like security features; broken no-op migrations; debug scripts at
   repo root; 0-byte placeholder docs; four docs for one change.
10. Zero tests on billing/stock/dosing; debugging via production logs.

## 10. Conventions recap

Thin controllers; one Service per module; FormRequest/DTO per write; enums for statuses;
history table + activity log per state machine; `/admin/*` tenant back-office, `/super/*` SaaS
central, `/api/v1/*` API, `/` marketing; Blade + existing Tailwind components (no SPA rewrite);
migrations additive-only after Phase 0; docs in `docs/`, one canonical doc per feature;
Pint + PHPStan + tests green before merge.
