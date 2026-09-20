# TRUE-DOCTOR — MASTER BUILD PROMPT FOR THE AI AGENT

> **Paste this entire file to the AI coding agent as its mission brief.**
> It transforms the existing Laravel application (currently *Cryptocoinex*, a trading/education
> platform) into **True-Doctor**, a national public-verification registry for Uganda's health
> sector. Read every section before writing a single line of code.

---

## 0. HOW TO USE THIS DOCUMENT (read first)

You are a **senior software architect + full-stack Laravel engineer**. Your job is to *re-program*
an existing, working Laravel 12 codebase into a new product **without reinventing the wheel** —
reuse the auth, admin panel, roles/permissions, activity log, file-upload, theming, and
document-verification (KYC) machinery that already exists.

**Golden rules:**

1. **Understand before you touch.** Before writing code, produce `PLAN.md` (a living task board — see §12). Do not skip this. Wait for nothing; write the plan first, then execute task-by-task, checking items off as you go.
2. **Reuse aggressively.** The existing app already contains ~90% of the plumbing you need. Map old → new (see §3) instead of deleting and rebuilding.
3. **Never break a working boot.** After every phase, the app must still `php artisan serve` cleanly and the home page must load. Commit after each green phase.
4. **Uganda-first.** This is a Ugandan public service. Phone `+256`, districts, local regulators, low-bandwidth, mobile-first, English + Luganda (+ Swahili) — see §9.
5. **Trust is the product.** Every verification and every profile edit must be logged immutably. A wrong "Verified" badge is a safety incident, not a bug. Design defensively.
6. **Ask nothing you can infer; assume sensible defaults and document them** in `PLAN.md` under "Assumptions".

---

## 1. PRODUCT VISION

**True-Doctor** is Uganda's public "is-this-real?" registry for anything in human health.
A patient, employer, pharmacy, or regulator can **scan a QR code or type a short verification
number** and instantly learn whether a doctor, nurse, clinic, pharmacy, medicine, specialist,
student, or institution is genuinely registered, currently in good standing, and who vouches for it.

Two audiences, one system:

- **Admins / Registrars (private, authenticated):** create, profile, verify, suspend, renew and audit records. This reuses the existing admin panel.
- **The public (no login, no friction):** a single, no-scroll, JavaScript-driven screen — *scan or type a code → see the truth.* Nothing else on screen unless they open the menu to "learn more".

**Tagline ideas** (pick one, put in config): *"Scan. Verify. Trust."* / *"Know before you trust your health."*

### What can be registered & verified (categories)
Make categories **data-driven** (a `categories` table), seeded with at least:

- **Medical practitioners** — doctors, dentists, nurses, midwives, clinical officers, pharmacists, lab technologists, radiographers, physiotherapists, nutritionists, community health workers.
- **Medical specialists** — cardiologist, surgeon, pediatrician, gynecologist, psychiatrist, etc. (specialist is a practitioner with a specialty + higher credential tier).
- **Health facilities** — hospitals, clinics ("clicks" in the brief = **clinics**), health centres (HC II/III/IV), maternity homes, diagnostic labs, imaging centres.
- **Pharmacies & drug shops** — licensed pharmacies, registered drug shops.
- **Medicines / drugs** — registered products (verify a drug is NDA-registered and not counterfeit; support batch/serial verification).
- **Medical students / trainees** — enrolled students at recognised institutions.
- **Institutions** — medical schools, nursing schools, training colleges.
- **Suppliers / equipment** — medical device & supply vendors (optional, phase 2).

Every registered record is a **verifiable entity** with a unique human-readable **verification code**
and a **QR code**, a **status**, an **issuing/regulatory body**, an **issue & expiry date**, a
**photo/logo**, attached **credential documents**, and a **public verification page**.

---

## 2. CURRENT SYSTEM — WHAT YOU ARE STARTING FROM (verified facts)

This is a real, running Laravel app. Confirmed stack and assets you MUST reuse:

- **Framework:** Laravel 12, PHP 8.2.
- **Front-end:** Blade + **Alpine.js** + **Tailwind CSS** + **Vite**. (`resources/js`, `resources/css`, `vite.config.js`.)
- **Packages already installed** (`composer.json`): `laravel/sanctum` (API tokens), `laravel/horizon` (queues), `laravel/tinker`, `spatie/laravel-permission` (roles/permissions), `spatie/laravel-activitylog` (audit trail — **critical, reuse for verification logs**), `intervention/image` (image processing/uploads), `predis/predis` (Redis). Dev: telescope, debugbar, breeze, pail, pint, sail.
- **Auth:** Laravel Breeze-style controllers in `app/Http/Controllers/Auth/*`, `routes/auth.php`, login at `/admin/login`, an `admin` middleware guarding `/admin/*`.
- **Admin panel:** `app/Http/Controllers/Admin/*`, blade views under `resources/views/admin/*`, layout `resources/views/layouts/admin.blade.php`, a `DashboardController`, full `UserController` CRUD, and an `Admin/ApiController` powering a self-profile drawer (name/avatar/password via JSON). **Reuse this admin shell wholesale.**
- **Existing document-verification flow (KYC):** `app/Http/Controllers/Trading/KycController.php` + `app/Http/Controllers/Admin/Trading/KycController.php` + `App\Models\KycSubmission` + `resources/views/trading/kyc.blade.php` + admin review views with **approve / decline / redo** actions and document viewing. **This is your template for medical-credential verification — reuse the pattern, rename the domain.**
- **Theme toggle already exists:** there is a `trade.theme` route (`ProfileController@theme`) — light/dark is already a concept in the app. Extend it app-wide (see §7).
- **Roles/permissions:** Spatie is installed; the `admin` middleware exists. Reuse to build the new role matrix (§6).
- **Public marketing layout:** `resources/views/layouts/public.blade.php` + `resources/views/public/*` (home, features, how, faq, contact, academy, privacy, terms) driven by `App\Http\Controllers\PublicController`. **Reuse `public.blade.php` as the "learn more" shell; replace `public/home.blade.php` with the new scan-first home.**
- **Email with credentials:** `resources/views/emails/welcome-credentials.blade.php` — reuse to send registrants their verification code + QR.
- **Config (`.env` today):** `APP_NAME="Cryptocoinex"`, `DB_CONNECTION=mysql`, `DB_DATABASE=cryptocoinex`, `DB_USERNAME=root`, `DB_PASSWORD=root`, MAMP socket `/Applications/MAMP/tmp/mysql/mysql.sock`, `APP_URL=http://localhost:8888/cryptocoinex`, Redis cache/queue, SMTP mail configured.

### Domain to RETIRE (trading/education — not needed for True-Doctor)
The bulk of `app/Models/Trading/*`, `app/Http/Controllers/Trading/*`, `app/Http/Controllers/Admin/Trading/*`, and their views/migrations belong to the old trading game (wallets, trades, tournaments, leaderboards, live deposits/withdrawals, assets, education articles, courses, students-as-traders). **Do NOT hard-delete blindly.** Follow the retirement procedure in §5. Keep anything genuinely reusable (KYC pattern, user model, roles, activity log, admin shell, image upload, mail).

---

## 3. REUSE MAP (benchmark old → new)

| Existing asset | New True-Doctor role |
|---|---|
| `User` model + Breeze auth + `admin` middleware | Admin/registrar/verifier accounts (staff login). Public needs **no** account. |
| Spatie **roles & permissions** | New role matrix: Super Admin, Registrar/Profiler, Verifier, Auditor, Institution Admin, Data Clerk (§6). |
| Spatie **activitylog** | **Immutable audit trail** for every profile create/edit/status-change and (optionally) every public verification. |
| **KYC** models/controllers/views (`KycSubmission`, approve/decline/redo, doc viewing) | **Credential & document verification** workflow for each registration (upload license/certificate → registrar reviews → approve/suspend/revoke). |
| `Admin/*` controllers + `layouts/admin.blade.php` + `Admin/ApiController` profile drawer | The whole **registry back-office** shell — dashboard, tables, CRUD, self-profile. |
| `intervention/image` uploads | Profile photos, facility logos, scanned certificates, drug label images. |
| `emails/welcome-credentials.blade.php` + mailer | Send a registrant their verification code + QR + verification URL. |
| `layouts/public.blade.php` + `public/*` pages | The **"Learn more"** section behind the home-screen menu (About, How it works, For institutions, Report fraud, FAQ, Privacy, Terms). |
| Existing **theme toggle** (`trade.theme`) | App-wide light/dark toggle (§7). |
| `PublicController@home` + `public/home.blade.php` | Replace with the **scan-first, no-scroll** home (§8). |
| Horizon + Redis queues | Async jobs: QR generation, email/SMS dispatch, expiry sweeps, bulk imports. |
| Sanctum | Token auth for the **public verification API** and institution portal. |

**Delete/park:** trading wallets, trades, tournaments, leaderboards, live money, assets, education articles/courses, newsletter — unless trivially repurposed.

---

## 4. DATABASE — CREATE `true_doctor` AND MIGRATE

### 4.1 Requirement
Create a **new MySQL database `true_doctor`** and move the app onto it. Preserve the reusable
auth/roles/activity-log/telescope/horizon tables; build the new medical schema fresh.

### 4.2 Exact procedure (do in this order, document each step in PLAN.md)
1. **Back up first.** `mysqldump` the current `cryptocoinex` DB to `storage/backups/cryptocoinex_YYYYMMDD.sql`. Never proceed without a backup.
2. **Create DB:** `CREATE DATABASE true_doctor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. **Point the app at it.** Update `.env`:
   - `APP_NAME="True-Doctor"`
   - `DB_DATABASE=true_doctor`
   - `APP_URL=http://localhost:8888/true-doctor` (keep MAMP host/port/socket; keep `root`/`root`; keep the MAMP mysql socket).
   - Keep Redis, mail, etc. Also update `APP_FOLDER` if referenced.
   - Update `.env.example` to match (with secrets blanked).
4. **Reuse the framework tables.** Keep migrations for: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`/`job_batches`/`failed_jobs`, Sanctum `personal_access_tokens`, Spatie `permission` tables, Spatie `activity_log`, Telescope, Horizon. Re-run them against `true_doctor`.
5. **Retire trading migrations** per §5 (move to an `archive/` folder or wrap so they don't run on the fresh DB). The fresh `true_doctor` DB should **not** contain trading tables.
6. **Add the new schema** (§4.3) as fresh migrations.
7. **Run** `php artisan migrate:fresh --seed` against `true_doctor`, then verify with `php artisan migrate:status`.
8. **Data migration note:** the old trading data (wallets/trades) has no meaning in True-Doctor, so it is **not** copied over. If any real people exist in `users` worth keeping, optionally write a one-off seeder/command to carry over admin accounts only. Document this decision in PLAN.md.

> If the environment blocks creating databases, stop and surface the exact SQL/command for the human to run — never silently continue on the old DB.

### 4.3 New schema (fresh migrations)

Design for clarity + extensibility. Core idea: one **registrations** table (the verifiable entity)
+ category-specific detail via a typed `meta` JSON column and dedicated support tables.

**`regulatory_bodies`** — the authorities that back a registration.
`id, name, short_name (e.g. UMDPC, UNMC, NDA, AHPC), description, website, logo, contact, created_at, updated_at`.
Seed Uganda's real bodies (§9).

**`categories`** — configurable entity types.
`id, parent_id (nullable, for hierarchy e.g. Specialist→Doctor), name, slug, group (enum: practitioner, facility, pharmacy, drug, student, institution, supplier), code_prefix (e.g. DR, NR, PH, FAC, DRG, STU, INST), default_validity_months, default_regulatory_body_id, icon, requires_photo (bool), schema_json (defines the extra fields this category needs), is_active, sort_order`.

**`districts`** — Uganda districts (seed full list) `id, name, region, subregion`.

**`registrations`** — THE verifiable record (heart of the system).
```
id
uuid                       // internal
verification_code          // UNIQUE, human-readable, e.g. TD-DR-2026-000123 (see §4.4)
qr_token                   // UNIQUE random signed token embedded in the QR (not the code itself)
category_id  -> categories
regulatory_body_id -> regulatory_bodies (nullable)
institution_id -> registrations (nullable, self-ref: a person/facility can belong to an institution)
district_id -> districts (nullable)
status                     // enum: draft, pending, verified, suspended, revoked, expired  (see §4.5)
full_name / entity_name    // display name (person OR facility OR drug)
slug
photo_path                 // profile photo / facility logo / drug image (Intervention)
phone, email, address, location_lat, location_lng
gender, dob                // for practitioners/students (nullable)
license_number             // the professional/registration number from the source body (nullable)
specialty                  // nullable
qualifications             // short text/JSON list
issued_at, expires_at      // validity window (drives auto-expiry)
verified_at, verified_by   // user id of registrar who verified
profiled_by                // user id who created the profile
meta                       // JSON — category-specific fields per categories.schema_json
public_notes               // safe-to-show text
internal_notes             // staff-only
verification_count         // denormalised counter of public lookups (anti-fraud signal)
last_verified_scan_at
is_searchable              // whether it appears in public directory search
created_at, updated_at, deleted_at (soft deletes)
```
Indexes: unique(`verification_code`), unique(`qr_token`), index(`status`), index(`category_id`), index(`district_id`), fulltext(`full_name`,`entity_name`,`license_number`).

**`credentials`** (reuse the KYC pattern) — documents proving a registration.
`id, registration_id, type (license, degree, national_id, practising_cert, facility_licence, nda_certificate, other), file_path, original_name, mime, status (pending/approved/rejected), reviewed_by, reviewed_at, review_notes, expires_at, created_at`.

**`verification_logs`** — every public scan/lookup (audit + analytics + anti-fraud).
`id, registration_id (nullable if code not found), method (qr/code/api/ussd/sms), input_code, result (found_verified/found_suspended/found_revoked/found_expired/not_found), ip, user_agent, country, city, referrer, created_at`. Partition/prune strategy noted for scale.

**`fraud_reports`** — public "report this" submissions.
`id, registration_id (nullable), reporter_name, reporter_phone, reporter_email, reason (enum), details, evidence_path (nullable), status (new/reviewing/actioned/dismissed), handled_by, handled_at, created_at`.

**`renewals`** — history of validity extensions.
`id, registration_id, previous_expiry, new_expiry, renewed_by, note, created_at`.

**`status_histories`** — immutable log of status transitions (belt-and-braces alongside activitylog).
`id, registration_id, from_status, to_status, reason, changed_by, created_at`.

**`institution_members`** (optional, for institution self-service) — links staff registrations to an institution and grants an institution-admin login to manage their roster.

**`settings`** — key/value app config (site name, tagline, default theme, languages enabled, contact info, verification rate-limit, QR base URL). Reuse if a settings table already exists; otherwise add a simple one.

Add **factories + seeders**: regulatory bodies, categories, districts, a Super Admin user, roles/permissions, and ~30 realistic demo registrations across categories (verified/suspended/expired mix) so the UI is populated on first run.

### 4.4 Verification code format
Human-readable, unambiguous, tamper-resistant:
`TD-<CATEGORY_PREFIX>-<YEAR>-<SEQUENCE><CHECK>`
e.g. `TD-DR-2026-000482K`. Use a check character (Luhn-mod-N or similar) so typos are caught
client-side. Avoid ambiguous chars (no O/0, I/1). The **QR encodes a URL** to the public
verify page using the signed `qr_token` (e.g. `https://truedoctor.ug/v/{qr_token}`), NOT the raw
code, so codes can't be trivially enumerated. Manual entry uses the short `verification_code`.

### 4.5 Status model (safety-critical)
`draft` → not public. `pending` → profiled but credentials not yet approved (public sees "Registration pending — not yet verified"). `verified` → green, in good standing. `suspended` → amber/red, temporarily not allowed to practise (show prominently). `revoked` → red, struck off (show prominently, permanent). `expired` → grey, needs renewal (auto-set by a scheduled job when `expires_at` passes). Every transition writes to `status_histories` + activitylog and (optionally) notifies the registrant.

---

## 5. RETIREMENT PROCEDURE FOR TRADING DOMAIN (do safely)

1. In PLAN.md, list every trading file (models/controllers/views/migrations/routes/JS) with a keep/park/delete decision.
2. Create `app/Legacy/` (or a git branch `legacy-trading`) and **move** — don't delete — the trading code so history is recoverable.
3. Strip trading routes from `routes/web.php` and `routes/api.php`; keep auth + admin shell routes.
4. Remove trading nav items from `layouts/admin.blade.php` and the public menu.
5. Exclude trading migrations from the fresh `true_doctor` build (move to `database/migrations/archive/`).
6. Confirm `php artisan route:list`, `migrate:fresh --seed`, and a home-page load all succeed before continuing.

---

## 6. ROLES & PERMISSIONS (Spatie — reuse)

Seed roles:
- **Super Admin** — everything, incl. settings, user management, category/regulatory-body config, audit export.
- **Registrar / Profiler** — create & edit registrations, upload credentials, submit for verification.
- **Verifier** — approve/verify, suspend, revoke, renew; review credentials & fraud reports.
- **Auditor** — read-only across everything + audit-log and verification-log access & export.
- **Institution Admin** — manage only their institution's members/roster (scoped).
- **Data Clerk** — bulk import, data entry into `draft`, cannot verify.

Enforce with policies (`RegistrationPolicy`, `CredentialPolicy`, etc.) and Spatie middleware.
The public has **no role** and hits only unauthenticated verify endpoints.

---

## 7. THEMING — LIGHT & DARK, BLUE-CENTRIC (design system)

Deliver a proper, tokenised theme, not ad-hoc colours.

- **Primary = medical blue.** Suggested palette (put in `tailwind.config.js` + CSS variables):
  - Primary `#0A6EBD` / `#1E88E5` scale (50→900), with a deeper `#084C8D` for headers.
  - Accent teal/cyan `#00B5CC` (blends with blue), success green `#16A34A`, warning amber `#F59E0B`, danger red `#DC2626`, neutral slate greys, clean white `#FFFFFF` surfaces.
- **Light mode:** white/very-light-blue surfaces, blue primary, dark slate text.
- **Dark mode:** deep navy/slate surfaces (`#0B1220`, `#111A2E`), lighter blue primaries, high-contrast text. Must pass **WCAG AA** contrast in both modes.
- Implement with Tailwind `darkMode: 'class'`, a `<html class="dark">` toggle, CSS custom properties for the blue scale, and persist the choice (localStorage + optional server-side for logged-in staff, reusing the existing `theme` route). Respect `prefers-color-scheme` on first visit.
- Provide reusable Blade/Tailwind components: buttons, cards, status badges (colour-coded by status), inputs, the verification result card, tables. Define them once (design tokens) so the whole app is consistent.
- Motion: subtle, fast, respect `prefers-reduced-motion`. Mobile-first, thumb-reachable controls, large tap targets (Uganda = mostly phones).

---

## 8. THE PUBLIC HOME PAGE (the hero of the product)

A **single, no-scroll, JavaScript-driven** screen. Reuse Alpine.js (already present). Replace
`resources/views/public/home.blade.php`.

**Layout (full viewport, centred, no scrolling):**
- Small brand mark top-left ("True-Doctor" + blue check icon). Top-right: **light/dark toggle**, **language switcher (EN/LG/SW)**, and a **hamburger menu**.
- **Centre stage:** one big prompt — *"Verify anyone or anything in Uganda's health system."* Below it, a large **verification-code input** (auto-uppercase, formats as the user types, validates the check character client-side) with a prominent **"Verify"** button, and a big **"Scan QR"** button.
- **Scan QR:** opens the device camera in-page (use `html5-qrcode` or `jsQR` via `getUserMedia`) inside a modal/overlay; on decode, immediately resolve and show the result. Graceful fallback + permissions messaging if no camera.
- **Result:** AJAX (fetch) to the verify API → animated **result card** overlays the screen:
  - **Verified:** big green check, photo/logo, name, category, regulatory body, license #, status, issued/expires, district, "Verified ✓ — in good standing", verification count, and a link to the full public profile. Offer **"Download certificate (PDF)"** and **"Report a problem"**.
  - **Suspended/Revoked/Expired:** clear amber/red state with plain-language explanation ("This licence is currently suspended — do not rely on it").
  - **Not found:** red, "No record matches this code — this may be fraudulent," plus a **"Report suspected fraud"** CTA.
- **Hamburger menu** ("learn more"): About True-Doctor, How verification works, For hospitals & institutions (self-service portal), Report fraud, FAQ, Contact, Privacy, Terms, Language. These reuse `layouts/public.blade.php`.
- **Accessibility & i18n:** everything keyboard-navigable, screen-reader labelled, translatable strings via Laravel localization (`lang/en`, `lang/lg`, `lang/sw`).
- **Performance:** page must be tiny and load fast on 3G. Inline critical CSS, lazy-load the camera library only when "Scan QR" is tapped, no heavy frameworks.

---

## 9. UGANDA CONTEXT (bake this in)

- **Regulatory bodies to seed:** Uganda Medical and Dental Practitioners Council (**UMDPC**), Uganda Nurses and Midwives Council (**UNMC**), Pharmacy Council / Pharmaceutical Society of Uganda, **National Drug Authority (NDA)** for medicines, Allied Health Professionals Council (**AHPC**), Uganda Medical Council, and the Ministry of Health. *(Verify exact current names during build; store as data so they're editable.)*
- **Phone format:** `+256` normalisation & validation everywhere. Support MTN/Airtel numbers.
- **Districts:** seed the full list of Ugandan districts + regions for facility/practitioner location.
- **Languages:** English (default), **Luganda**, Swahili — full i18n scaffolding even if translations start partial.
- **Low-bandwidth & feature phones:** ship a **USSD** and/or **SMS** verification path (e.g. text a code to a shortcode, get status back) — integrate a Uganda-friendly gateway such as **Africa's Talking** (abstract behind a `VerificationChannel` interface so the provider is swappable). Queue via Horizon.
- **Offline-friendliness:** verification result pages cacheable; certificate PDFs downloadable for offline proof.
- **Currency (if any paid features):** UGX; if payments needed later, plan for **mobile money** (MTN MoMo / Airtel Money) — but core public verification is **free**.

---

## 10. EXTRA FEATURES TO ADD (make it powerful — beyond the brief)

Implement the ones marked **[core]** now; scaffold the **[phase 2]** ones behind flags.

- **[core] QR code generation** per registration (`simplesoftwareio/simple-qrcode` or `endroid/qr-code`), stored + downloadable as PNG/SVG, printable ID-card layout.
- **[core] Verification certificate PDF** (branded, with QR, watermark, issue/expiry) — reuse the mail/PDF stack; generate on demand and queue heavy renders.
- **[core] Public verification API** (Sanctum-protected for partners; open rate-limited endpoint for the home page). Versioned (`/api/v1/verify/{code}`). Return safe fields only.
- **[core] Immutable audit trail** via activitylog + `status_histories` — who did what, when, from where. Exportable (Auditor role).
- **[core] Anti-fraud signals:** verification counter, "report fraud" flow, rate limiting + throttling on public verify, code enumeration protection (signed `qr_token`), and an admin fraud-review queue (reuse KYC review UI).
- **[core] Auto-expiry & renewal:** scheduled command flips `verified`→`expired` past `expires_at`; renewal action writes to `renewals`; email/SMS reminders before expiry (queued).
- **[core] Bulk import (CSV/Excel)** for onboarding existing registries; validation + dry-run + error report. (Reuse existing spreadsheet handling patterns if present.)
- **[core] Analytics dashboard** (admin): verifications/day, by category, by district, top-scanned, fraud reports, expiring-soon, growth. Charts.
- **[phase 2] Institution self-service portal:** hospitals/schools log in (Institution Admin role) to manage their staff/student roster and see who's expiring.
- **[phase 2] Map view** of verified facilities (Leaflet + district data).
- **[phase 2] Drug batch/serial verification** with counterfeit reporting hotline integration.
- **[phase 2] Public directory search** (opt-in `is_searchable`) — find a verified specialist near you.
- **[phase 2] Webhooks/notifications** to employers when a staff member's status changes.
- **[phase 2] Digital signature / verifiable credential** (signed JSON / QR you can validate offline).
- **[phase 2] SMS/USSD** channel live (scaffold interface in core).

---

## 11. ARCHITECTURE, QUALITY & SECURITY REQUIREMENTS

- **Layered:** thin controllers → **Service classes** (`RegistrationService`, `VerificationService`, `CredentialService`, `QrService`, `CertificateService`, `FraudService`) → Eloquent models. Business rules live in services, not controllers.
- **Form Requests** for all validation; **Policies** for authorization; **Events/Listeners** for side-effects (on verify → generate QR + email; on status change → log + notify).
- **Queues (Horizon)** for QR/PDF/email/SMS/import. **Scheduled tasks** for expiry sweeps & reminders (`routes/console.php` / `app/Console`).
- **Security:** rate-limit public verify (per IP), CAPTCHA/honeypot on fraud-report form, signed URLs for certificate downloads, no PII leakage in public responses (show only what's safe), CSRF on all forms, mass-assignment guards, validate & re-encode all uploaded images (Intervention), store docs outside webroot, escape everything, enforce HTTPS in prod, secure headers.
- **Privacy:** public profile shows professional info only (name, category, status, body, license #, photo, general location) — never national ID numbers, home address, or raw uploaded documents.
- **Testing:** feature tests for verify (found/suspended/revoked/expired/not-found), status transitions, permissions per role, code+check-digit generation/validation, QR token resolution, rate limiting. Pest/PHPUnit already available. Aim for meaningful coverage on the verification core.
- **Code style:** run **Pint**. Follow Laravel conventions. Meaningful names, small methods, no dead trading code left behind.
- **Docs:** update `README.md` (setup, seeded Super Admin creds, how to run), keep `PLAN.md` current, add an `ARCHITECTURE.md` (schema + service map + status state-machine diagram).
- **i18n:** no hard-coded user-facing strings — use `__()` and lang files.
- **Config, not code:** categories, regulatory bodies, validity periods, code prefixes, enabled languages, site text → DB/settings, editable by Super Admin.

---

## 12. EXECUTION PLAN — WRITE `PLAN.md` FIRST, THEN BUILD IN PHASES

Before coding, create **`PLAN.md`** in the project root containing: the phase checklist below (as tickable tasks), an "Assumptions" section, a "Reuse decisions" table, and a "Risks/rollback" note. Update it as you complete each item. Commit after every phase.

**Phase 0 — Discovery & safety**
- [ ] Read the whole codebase; fill the reuse map. Back up `cryptocoinex` DB. Create `legacy-trading` branch.

**Phase 1 — Rebrand & DB switch**
- [ ] Create `true_doctor` DB; update `.env`/`.env.example` (APP_NAME, DB, URL). Rebrand app name, logo placeholder, mail from-name, page titles.

**Phase 2 — Retire trading domain** (§5) — park code, strip routes/nav, exclude old migrations.

**Phase 3 — Data model** (§4.3) — migrations, models, relationships, factories, seeders (bodies, categories, districts, roles, Super Admin, demo data). `migrate:fresh --seed` green.

**Phase 4 — Roles & permissions** (§6) — seed roles, policies, middleware.

**Phase 5 — Theming/design system** (§7) — Tailwind tokens, dark mode, status badges, shared components.

**Phase 6 — Admin back-office** — reuse admin shell: registrations CRUD, credential review (from KYC), verify/suspend/revoke/renew actions, fraud queue, categories & bodies config, analytics dashboard, bulk import.

**Phase 7 — QR + certificates + codes** (§4.4, §10) — code generator w/ check digit, signed `qr_token`, QR images, PDF certificates, ID-card layout.

**Phase 8 — Public verification** (§8) — scan-first no-scroll home, camera QR scan, code entry, result card, public profile page, verify API, rate limiting, fraud-report form, i18n scaffolding.

**Phase 9 — Automation** — expiry sweep + reminders (scheduler), queued emails/SMS, SMS/USSD channel interface.

**Phase 10 — Hardening & tests** — feature tests, security review, Pint, docs (`README`, `ARCHITECTURE.md`), demo walkthrough. Final green boot.

**Definition of done:** fresh clone → `.env` set → `composer install && npm install && npm run build` → `php artisan migrate:fresh --seed` → visit `/` → scan or type a seeded code → correct result card (verified/suspended/revoked/expired/not-found) → admin can log in, profile a new entity, verify it, and its QR/code resolve publicly. Light & dark both look clean and blue. All tests pass. No trading code reachable.

---

## 13. TONE, OUTPUT & WORKING STYLE FOR THE AGENT

- Work **incrementally and verifiably**; after each phase, show what changed and prove the app still boots.
- Prefer **editing existing files** over creating parallel ones; reuse components; keep the diff legible.
- When a decision is ambiguous, choose the **safest, most conventional Laravel option**, record it in `PLAN.md` under Assumptions, and continue — don't stall.
- Never leave the app in a broken state between commits.
- Comment the *why*, not the obvious *what*. Keep it clean enough for a junior Ugandan dev team to maintain.

**Now begin with Phase 0: read the codebase and write `PLAN.md`.**
