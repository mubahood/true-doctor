# 01. System review: where True-Doctor stands

A factual snapshot taken on 17 October 2026. Paths are relative to the repo root unless they start with `mobo/` (the app repo, `Desktop/github/true-doctor-mobo`).

## 1. Size

| Item | Count |
|---|---|
| Migrations | 113 |
| Models | 74 |
| Livewire components | 113 |
| Service files | 95 |
| API controllers | 73 (about 253 API routes, 144 web routes) |
| Server tests | 242 files, about 2,566 test methods |
| PDF templates | 13 |
| App (Flutter) | 166 Dart files, about 44,600 lines, about 700 tests |

## 2. What is strong (keep and build on it)

- **The visit spine.** Ongoing → Billing → Payment → Completed, with gates and append-only history (`docs/visits.md`). A visit closes itself when the balance reaches zero.
- **Money done properly.**
  - bcmath strings, never floats;
  - a double-entry ledger with financial statements (`app/Services/Finance/*`);
  - closed financial years;
  - an idempotency middleware on money routes (`app/Http/Middleware/IdempotentRequests.php`).
- **Tenancy.** A global hospital scope, resolved before route binding (`ResolveHospital`), with isolation tests.
- **Two editions from one codebase:**
  - signed licences, releases proved by a clean install;
  - an installer, safe updates and backups on the client's server (`docs/editions.md`).
  - The first client, Divine Dental, is live.
- **Practice packs and sectors.** 16 packs and 12 sectors; a dental clinic's "Pharmacy" becomes "Drugs inventory" (`app/Support/Practice/*`).
- **Insights.** 22 rules: stock risk, long waits, no-shows, overdue invoices, claims, long stays and more (`app/Support/Insights/Rules/*`). The same rules feed next actions and the agenda.
- **Offline.**
  - A device sync engine with three-way merge on the server (`app/Services/Sync/*`).
  - In the app: an outbox, idempotency keys that survive a restart, read-through copies, "Make ready for offline", PIN lock, privacy cover and a clock check.
- **A public site already built to sound rules:** no JavaScript needed, one H1 per page, currency by region, 36 `PublicSiteTest` tests, and an in-house traffic recorder with Google Ads offline conversions (`TrafficRecorder`, `/super/traffic`).

## 3. Gaps and risks, by area

### Selling and reach

| Gap | Where | Tag |
|---|---|---|
| No WhatsApp, phone or `tel:` link anywhere public, in the back office or in the app. The `contact_phone` setting exists but is never read | `app/Livewire/Settings/Site.php`, layouts, `MetaController` | P0 |
| The public contact email resolves to `noreply@true-doctor.online` in production, and enquiries go there (`MAIL_ENQUIRIES_TO` unset) | `layouts/marketing.blade.php`, `.env.production` | P0 |
| The demo is off in production, while the ad sitelink says "See a Live Demo" | `DEMO_MODE`, `MarketingController::demoAvailable()` | P0 |
| Sign-up collects no phone number, so no follow-up is possible | `RegistrationController`, `auth/register.blade.php` | P0 |
| No real screenshots: the hero is a drawn dashboard with `$` figures | `components/site/product-shot` | P1 |
| No specialty, city, comparison or guide pages; no blog | `routes/web.php` | P1 |
| SEO details:<br>• relative `Sitemap:` in `robots.txt`<br>• sitemap without `lastmod` and missing `/standalone`<br>• `AggregateOffer` with no price<br>• no Organization or WebSite schema<br>• one OG image for all pages<br>• `/my-licence` and `/s/*` indexable<br>• `/register` described as "Staff sign-in" | `public/robots.txt`, `MarketingController::sitemap`, layout | P1 |
| 74 KB of Font Awesome CSS plus 280 KB of web fonts, and 6 Inter weights from Google Fonts, all render-blocking on the public site | `layouts/marketing.blade.php` | P1 |
| Prices may undervalue the product: UGX 10,000 / 50,000 / 100,000 a month (`PlanSeeder`), next to a UGX 3,000,000 one-off sale to a dental clinic | `database/seeders/PlanSeeder.php` | P1 (owner decision) |
| No support inside the back office, though the contact page promises it | admin shell | P0 |

### Clinical depth and standards

| Gap | Where | Tag |
|---|---|---|
| Diagnosis is free text: no ICD-10/11, so no HMIS 105/108 and no DHIS2 | `consultations.diagnosis` (text) | P1 |
| Dental pack is a price list only: no tooth chart (the treatment-record `meta` JSON was meant for it and is unused) | `Packs/Dental.php`, `treatment_records` | P0 for the dental client |
| Maternity has no pregnancy record (LMP, EDD, ANC visits, partograph); paediatrics has no immunisation register or growth charts | `Packs/Maternity.php`, `Packs/Paediatrics.php` | P1 |
| No lab codes (LOINC), no FHIR export, no electronic insurance claim format | lab, insurance | P2 |
| No EFRIS e-invoicing for VAT-registered facilities | billing | P1 |
| No refunds or credit notes; no suppliers, purchase orders or goods received | billing, pharmacy | P1 |

### Patients

| Gap | Where | Tag |
|---|---|---|
| Live SMS is not wired: the channel is bound to `LogChannel`, and the Africa's Talking driver has no config | `AppServiceProvider.php:67`, `app/Services/Channels/*` | P0 |
| No WhatsApp Business API; patients only get messages a staff member sends from their own phone | `InvoiceShareService`, `mobo/lib/features/billing/billing_share.dart` | P1 |
| The only automatic patient message is the day-before appointment reminder | `SendAppointmentReminders` | P1 |
| No online booking, no clinic public page, no results link, and no QR verification on documents | share links cover invoices and receipts only | P1 |
| Consent is one generic flag, with no per-channel opt-in | `patients.consent_given` | P1 |

### Robustness, security, compliance

| Gap | Where | Tag |
|---|---|---|
| The live cron runs every 19 minutes, so jobs set at 00:05, 03:30 and 03:40 never run. The nightly bed charges are lost money | `routes/console.php`, `docs/decisions.md` 2026-10-15 | P0 |
| No SaaS backup job and no restore drill | standalone has one, SaaS has none | P0 |
| No error tracking, uptime monitoring or status page | `/up` only | P0 |
| Passwords need only 6 characters; no 2FA; sessions not encrypted | `AppServiceProvider.php:150`, `config/session.php` | P0 |
| The audit log covers only 4 to 5 fields of Patient and Visit; reads are not logged; no clinical field is encrypted | `Patient.php:80`, `Visit.php:97` | P1 |
| No full data export or erasure; Uganda's Data Protection and Privacy Act 2019 is not mentioned | none | P1 |
| CI is blocked by GitHub billing; the full suite exceeds 512 MB | `docs/agents/08-lessons.md` | P0 |
| Docs disagree with code: `CAPABILITIES.md` claims an 8-character password policy and encrypted clinical fields; `HMS_PLAN` says SMS is done; the exchange rate key differs between docs | docs | P1 |

### The app

| Gap | Where | Tag |
|---|---|---|
| No support channel in the app | `mobo/lib/features/account/account_page.dart` | P0 |
| No push notifications (FCM waits on a Firebase project) and no deep links | `mobo/lib/core/notify/*`, `AndroidManifest.xml` | P1 |
| Brand colour parsed but unused; no white-label build | `mobo/lib/core/meta/app_meta.dart`, `theme/td_theme.dart` | P1 |
| One server at a time, with no QR provisioning for a client's own server | `mobo/lib/core/session/secure_session.dart` | P1 |
| Local database and form drafts unencrypted | `mobo/lib/core/db/local_db.dart`, `features/common/form_draft.dart` | P1 |
| No localisation, no remote crash reports | `pubspec.yaml` | P1 |
| Not released:<br>• CI blocked, 32 commits unpushed;<br>• no store listing;<br>• app IDs differ between Android and iOS | `android/app/build.gradle.kts`, iOS project | P0 |

## 4. What this means

The product is ready to sell to more clinics as soon as the trust and contact gaps (P0) are closed. The clinical gaps decide whether it wins the bigger customers: hospitals that report to the Ministry, take insurance and are VAT registered. The patient gaps decide whether clinics feel the system every day or only at the counter.
