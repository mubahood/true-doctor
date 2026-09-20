# PLAN.md — True-Doctor Build (living task board)

> Transform the existing Laravel 12 app (currently *Cryptocoinex*, a trading/education
> platform previously also *Onyx Legal*) into **True-Doctor**, Uganda's public health-verification
> registry. Reuse auth, admin shell, roles/permissions, activity log, KYC pattern, image upload,
> mail, theming. Keep the app bootable after every phase.

Last updated: 2026-07-01 (Phase 0 complete)

---

## Assumptions (decisions made without asking)

1. **No git repo.** `git status` → *not a git repository*. The brief's "create `legacy-trading`
   branch" is impossible. **Substitute:** move retired trading/legal/education code into
   `app/Legacy/` and old migrations into `database/migrations/archive/`; a full SQL backup of the
   old DB exists at `storage/backups/cryptocoinex_20260701.sql` (134M, 73 tables) so nothing is lost.
2. **MySQL access.** Reachable via MAMP socket `/Applications/MAMP/tmp/mysql/mysql.sock` as
   `root`/`root`. Client used: `/opt/anaconda3/bin/mysql` (+ matching `mysqldump`). `true_doctor` DB
   does not yet exist and will be created.
3. **Keep MAMP infra** (host/port/socket/redis/mail) exactly; only change `APP_NAME`, `DB_DATABASE`,
   `APP_URL`, `APP_FOLDER`, `MAIL_FROM_NAME`.
4. **No production data to preserve.** Old trading/legal data has no meaning in True-Doctor and is
   NOT migrated. A fresh Super Admin is seeded (creds in README).
5. **Tagline:** *"Scan. Verify. Trust."* (stored in `settings`).
6. **QR base URL:** derived from `APP_URL` → `/v/{qr_token}`. Public manual entry uses `verification_code`.
7. **Verification code check char:** Luhn-mod-N over an unambiguous alphabet (no O/0, I/1).
8. **SMS/USSD (Africa's Talking):** interface + null/log driver only in core; live provider is phase 2.
9. **Languages:** `en` (complete), `lg` + `sw` scaffolded (keys present, translations partial).
10. **QR/PDF libs:** `simplesoftwareio/simple-qrcode` (QR) + `barryvdh/laravel-dompdf` (certificates).
    If composer install is blocked offline, fall back to `endroid/qr-code` already-available or a
    pure-SVG generator; documented here when chosen.

---

## Verified facts (Phase 0 discovery)

- **Stack:** Laravel 12, PHP 8.2, Blade + Alpine + Tailwind + Vite. Packages present: sanctum,
  horizon, permission (Spatie), activitylog (Spatie), intervention/image v3, predis. Dev: telescope,
  debugbar, breeze, pint, pail, sail.
- **Auth:** Breeze controllers in `app/Http/Controllers/Auth/*`, `routes/auth.php`. Login at
  `/admin/login`. `admin` middleware = `App\Http\Middleware\IsAdmin` (checks `can('access-admin')`
  or `canAccessAdmin()`; supports role args).
- **Admin shell:** `Admin/DashboardController`, `Admin/UserController` (resource CRUD),
  `Admin/ApiController` (self-profile drawer JSON). Layout `layouts/admin.blade.php` (349 lines).
- **KYC pattern (TEMPLATE):** `Trading/KycController` (user submit) + `Admin/Trading/KycController`
  (review: approve/decline/redo, private-disk doc streaming) + `App\Models\KycSubmission` +
  `App\Services\Trading\KycService`. Docs stored on `local` (private) disk, streamed via admin route.
  Migration `2026_06_17_100000_create_kyc_tables.php`.
- **Services already layered:** `app/Services/*` (AvatarService, Trading/*). Follow this convention.
- **Public shell:** `PublicController` + `layouts/public.blade.php` (227 lines) + `public/*` (home,
  features, how, academy, faq, contact, privacy, terms).
- **Theme:** `Trading/ProfileController@theme` (route `trade.theme`), `users.theme` column, Tailwind
  `darkMode` support. Extend app-wide.
- **User model:** rich fillable incl. `role`, `is_admin`, `theme`, `avatar`, `kyc_status`; uses
  `HasRoles`, `HasApiTokens`. RBAC seeded by `RbacSeeder`.
- **Extra legacy domains found beyond the brief:** *Onyx Legal* (clients, legal_cases, case_officers,
  documents, accounts, transactions) and *Education/Academy* (courses, instructors, enrollments,
  education_*). All retired alongside trading.
- **Migrations:** 64 files. **Seeders:** RBAC, Trading, AdminUser, Cryptocoineux, Achievement,
  Notification, Leaderboard, Tournament, Education. **Factories:** UserFactory, Trading/*.

---

## Reuse decisions (old → new)

| Existing asset | Decision | New role |
|---|---|---|
| `User` + Breeze auth + `IsAdmin` | **KEEP** | Staff (admin/registrar/verifier…) accounts; public needs none |
| Spatie roles/permissions + `RbacSeeder` | **KEEP, extend** | New role matrix (§6) |
| Spatie activitylog | **KEEP** | Immutable audit trail |
| KYC models/controllers/views/service | **REUSE as pattern** | `credentials` review workflow |
| `Admin/*` + `layouts/admin.blade.php` + `Admin/ApiController` | **KEEP** | Registry back-office shell |
| intervention/image + `AvatarService` | **KEEP** | Photos, logos, certs, drug images |
| `emails/welcome-credentials.blade.php` + mailer | **REUSE** | Send verification code + QR |
| `layouts/public.blade.php` + `public/*` | **KEEP shell** | "Learn more" section |
| Theme toggle (`trade.theme`, `users.theme`) | **KEEP, generalise** | App-wide light/dark |
| `PublicController@home` + `public/home.blade.php` | **REPLACE** | Scan-first no-scroll home |
| Horizon + Redis | **KEEP** | QR/PDF/email/SMS/import/expiry jobs |
| Sanctum | **KEEP** | Public verify API + partner tokens |
| Trading/Legal/Education models, controllers, views, migrations, routes | **PARK** → `app/Legacy/` + `database/migrations/archive/` | retired |

---

## Risks / rollback

- **DB creation blocked** → surface exact SQL, stop (don't run on old DB). *(Mitigated: access confirmed.)*
- **Breaking boot** → after each phase run `php artisan route:list` + load `/`; commit-equivalent
  checkpoint (tag in PLAN.md) before next phase. Rollback = restore from `storage/backups/*.sql` and
  git-less file moves are reversible from `app/Legacy/`.
- **Wrong "Verified" badge = safety incident** → every status change double-logged
  (`status_histories` + activitylog); signed `qr_token`, not enumerable codes; rate-limited public verify.
- **Offline composer** → fallbacks noted in Assumptions #10.

---

## Phase checklist

### Phase 0 — Discovery & safety  ✅ DONE
- [x] Read codebase, verify facts, fill reuse map.
- [x] Back up `cryptocoinex` DB → `storage/backups/cryptocoinex_20260701.sql`.
- [x] (git branch N/A — no repo) established `app/Legacy/` + `migrations/archive/` retirement path.
- [x] Write this `PLAN.md`.

### Phase 1 — Rebrand & DB switch  ✅ DONE
- [x] Create `true_doctor` DB (utf8mb4). Update `.env` + `.env.example` (APP_NAME, DB_DATABASE, APP_URL, APP_FOLDER).
- [x] Rebrand app name, mail from-name, page titles (config/app.php already uses env). App boots as "True-Doctor".

### Phase 2 — Retire trading/legal/education domain (§5)  ✅ DONE
- [x] Inventory every legacy file. Parked code → `_legacy/` (outside autoload; git-less substitute for the branch).
- [x] Strip legacy routes from `web.php`/`api.php`/`console.php`; keep auth + admin shell.
- [x] Rewrote legacy nav in `layouts/admin.blade.php` (Route::has guards) + public menu → True-Doctor.
- [x] Moved 56 legacy migrations → `database/migrations/archive/`; added `users` columns migration. Trimmed `DatabaseSeeder`.
- [x] Cleaned User model (removed trading/KYC), DashboardController, PublicController, AuthenticatedSessionController.
- [x] `route:list` clean; public pages + `/admin/login` + `/admin` + `/admin/users` all 200.

### Phase 3 — Data model (§4.3)  ✅ DONE
- [x] 11 migrations: settings, regulatory_bodies, districts, categories, registrations, credentials,
      verification_logs, fraud_reports, renewals, status_histories, institution_members.
- [x] 11 models + relationships + soft deletes + activitylog on Registration. RegistrationFactory.
- [x] Seeders: 7 bodies, 19 categories, 58 districts, 26 demo registrations (all statuses), credentials,
      status history, verification logs, a fraud report. `App\Support\VerificationCode` (Luhn-style check char + qr_token).
- [x] `migrate:fresh --seed` green (19 migrations). Verification result keys validated (found_verified/expired/…).

### Phase 4 — Roles & permissions (§6)  ✅ DONE
- [x] 6 roles + 14 permissions (RbacSeeder); Spatie `role`/`permission` middleware aliases registered.
- [x] Policies (Registration/Credential/FraudReport) with super_admin bypass; AdminUserSeeder syncs roles.

### Phase 5 — Theming / design system (§7)  ✅ DONE
- [x] Retinted brand tokens gold → medical blue (admin.css, public layout, brand marks, CTAs).
- [x] Reusable `admin.partials.status-badge` (colour per status); theme persisted via `/admin/theme`.

### Phase 6 — Admin back-office  ✅ DONE
- [x] Registrations CRUD + verify/suspend/revoke/renew/submit; credential review (upload + approve/reject
      + private-disk streaming); fraud queue; categories & regulatory-bodies config; settings; analytics
      dashboard (Chart.js); bulk CSV import (dry-run + error report + template).

### Phase 7 — QR + certificates + codes (§4.4)  ✅ DONE
- [x] `VerificationCode` (check char + qr_token); `QrService` (SVG, cached); `CertificateService`
      (branded DomPDF cert with QR); QR shown on the admin record page + downloadable.

### Phase 8 — Public verification (§8)  ✅ DONE
- [x] Scan-first no-scroll home; lazy-loaded camera QR scan; code entry + client check-digit; result
      cards for all 6 states; public profile; verify API `/api/v1/verify/{code}`; per-IP rate limiting;
      fraud-report form (honeypot); i18n scaffolding (`lang/en|lg|sw`).

### Phase 9 — Automation  ✅ DONE
- [x] `registry:sweep-expiry` + `registry:send-expiry-reminders` (scheduled); swappable
      `VerificationChannel` (LogChannel default, AfricasTalkingChannel stub for phase 2).

### Phase 10 — Hardening & tests  ✅ DONE
- [x] 13 feature tests pass (verify states, transitions + double-log, role perms, check-digit, QR token,
      scan logging, expiry sweep, payload safety). Pint clean.
- [x] `README.md` (setup + seeded creds) + `ARCHITECTURE.md` (schema + service map + status machine).
- [x] Final clean `migrate:fresh --seed` boot green; create→verify→public-resolve verified end-to-end.

---

## Final polish pass (done)
- Disabled the inherited **Tawk.to chat widget** (was on by default with the previous owner's account
  id) — no third-party script loads now (`TAWK_ENABLED` default false, id cleared).
- **Parked all orphaned legacy views** (welcome, auth/register, about, partners, trade/trading/frontend
  layouts, frontend components, final-cta) → `_legacy/`. Zero dead `route()` refs in active views;
  `view:cache` compiles every template.
- **i18n wired end-to-end:** `SetLocale` middleware (web + api) honours `?lang=en|lg|sw`; verification
  result messages resolve through `lang/{en,lg,sw}/verification.php` (English fallback). Verified over HTTP.
- Pint clean across `app/`; full suite green (13 pass, 16 pre-existing Breeze scaffold skips).

## Remaining refinements (documented, non-blocking)
- Full **light-mode admin skin** (admin.css is a polished dark-blue; public tokens.css has a light
  variant; toggle infra + blue palette are in place).
- Live **SMS/USSD inbound** endpoints and the **institution self-service portal** are scaffolded (phase 2).
- `lang/lg` + `lang/sw` cover the core verification strings; broader UI copy still English (fallback works).
- Internal admin JS namespace is still `ONYX.*` / `ONYX_CONFIG` (coupled to `public/js/admin.js`) —
  invisible to users; left as-is to avoid churn.

---

## Definition of done
Fresh setup → `migrate:fresh --seed` → visit `/` → scan/type a seeded code → correct result card for
every status → admin logs in, profiles + verifies an entity, its QR/code resolve publicly. Light &
dark clean and blue. Tests pass. No legacy code reachable.
