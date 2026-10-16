# Phase 0 report: trust and contact

**Dates:** 16 to 17 October 2026. **Repos:** server (`true-doctor`), app (`true-doctor-mobo`). **Plan of record:** `docs/roadmap/10-delivery-plan.md` §1, Phase 0.

## What shipped

| Item | What it is now | Where |
|---|---|---|
| Support contact everywhere (03) | `SupportContact` is the only reader of WhatsApp, phone, email and hours (Settings → Site, env fallbacks). On the public site (header button, floating button, footer, contact page first, Organization schema), sign-in, reset and sign-up, the back office Help menu, onboarding, the subscription and lapsed pages, plan-limit messages, error pages (with a reference), every vendor email, the licence portal, the standalone installer and guide (stamped in at build time), the API (`meta`, `public/plans`, `public/demo`) and the app (landing, sign-in, Get help card, diagnostics to WhatsApp, update, subscription, web-only and demo screens). A CI scan and an app test fail if the number is typed anywhere else. | `app/Support/SupportContact.php`, `config/support.php`, `lang/en/support.php` |
| A real contact inbox (02 §8) | Enquiries go to `MAIL_ENQUIRIES_TO`, else the support email, never a no-reply box; every "email us" on the public pages is the support inbox. | `MarketingController`, marketing views |
| Demo on in production (02 §6) | The app's demo door (role lobby, missions) is live on true-doctor.online, and the web demo has the same guards. | done before this phase; deployed |
| WhatsApp at sign-up (02 §6) | Required on the web form, optional over the API for installed apps, stored as the owner's phone. | `RegistrationRequest`, app trial form |
| Docs made true (07 §7) | Password policy, encryption at rest, audit coverage, SMS status, exchange-rate key and value, Pesapal for subscriptions; `CAPABILITIES.md` recounted. A test ties the doc's password minimum to the code. | `docs/CAPABILITIES.md`, `HMS_PLAN.md`, `docs/*.md` |
| A scheduler that cannot miss a job (07 §1) | Every daily, weekly and monthly job runs at the first tick after its time and catches up a missed day once; bed charges were already idempotent per night. A test fails if any job is pinned to a minute a 19-minute cron skips. | `app/Support/Scheduling/ScheduledRun.php`, `routes/console.php` |
| SaaS backups and the restore drill (07 §1) | Nightly encrypted (libsodium) backup sent to S3-compatible storage, 30 daily and 12 monthly kept; monthly restore into a scratch database compared with live. Verified by a real backup and restore of the local MySQL (99 tables). | `td:backup`, `td:backup-drill`, `config/backup.php` |
| Health, errors, status (07 §1) | `td:check` (14 checks) and public `/health` (statuses only, 503 on failure); errors grouped, scrubbed and listed in Super → Errors with the reference people quote, forwarded to Sentry/GlitchTip when a DSN is set; the app sends its crash log; the status page link appears when `STATUS_PAGE_URL` is set. | `app/Support/Health`, `app/Support/Errors`, `docs/runbooks/monitoring.md` |
| Passwords, two-step sign-in, sessions (07 §2) | 8 characters with letters and numbers and a breach check; TOTP two-step sign-in required for owners, administrators and the super admin, with recovery codes, trusted browsers and "sign out everywhere", on the web and in the app; encrypted sessions, secure cookie on https. | `PasswordPolicy`, `TwoFactorService`, `/account/security`, app `two_factor_pages.dart` |
| CI unblocked and sharded (07 §1) | PHPStan from 82 findings to none, Pint clean, the suite in shards that fit 512 MB, run in parallel in the workflow. | `scripts/ci/test-shard.sh`, `.github/workflows/ci.yml`, `docs/ci.md` |
| App release basics (08 §1, §2) | Version from the platform (`package_info_plus`) with a test tying the fallback to `pubspec.yaml`; Get help card; Play listing and Data safety answers written from what the app does. | app `docs/PLAY_LISTING.md` |
| Allergy and alert banner (04 §10) | Allergies, long-term conditions, deceased, and "allergies not recorded" at the top of the visit, admission, lab, imaging and dispensation pages on the web, and the visit, lab results and dispense screens in the app; every API payload naming a patient carries `patient.alerts`. | `PatientAlerts`, `x-clinical.alerts`, app `ClinicalAlertBanner` |
| Trust signals (02 §8) | Company facts (SOLAVIA GROUP LIMITED; registration, address, PDPO number when set) in the footer and the Organization schema; the security page says only what is true, including what is not yet built. | `config/company.php`, `marketing/security.blade.php` |

## Tests added

Server: `SupportContactTest`, `SupportEverywhereTest`, `PasswordPolicyTest`, `TotpTest`, `TwoFactorTest`, `ScheduledRunTest`, `S3SignatureTest`, `BackupTest`, `HealthCheckTest`, `ErrorTrackingTest`, `ClinicalAlertsTest`, `StandaloneSupportTest`, `DemoGuardTest`, plus the existing tests brought up to the new rules. App: `support_test`, `two_factor_test`, `crash_reporter_test`, `clinical_alerts_test`, `version_test`, the landing tests.

## What was reused

The standalone backup archive and `DatabaseBackup` (now restorable into another connection), `HospitalBrand`'s per-request caching pattern, `PatientSummaryService`'s alert idea, the existing error pages and emails, `Diagnostics`/`Redactor` in the app, the demo door, Budget Pro's patterns for checks and contact facts.

## What could not be done, and why

- **The app ID** for both stores is the owner's decision (roadmap 10 §4.2). Recommendation: `online.truedoctor.app`. The steps are in the app's `docs/PLAY_LISTING.md`.
- **Play internal testing** needs the Play Console; the listing and Data safety answers are ready.
- **Off-site storage, the scratch database, the uptime monitor and status page, an error tracker DSN** are accounts the owner opens (roadmap 10 §4.5). The code runs without them and `td:check` says what is missing.
- **The WhatsApp/SMS one-time code** as a fallback for two-step sign-in waits for the SMS channel (Phase 1).
- **A self-hosted CI runner** needs a small server; the workflow is ready for it.

## Decisions the owner needs to make

1. The app ID (above).
2. The S3-compatible store and bucket for backups, and where to keep `BACKUP_ENCRYPTION_KEY` offline.
3. The uptime monitor and status page (Better Stack, UptimeRobot, …), and whether to run GlitchTip.
4. Whether two-step sign-in should be enforced on the live service at once (`TWO_FACTOR_ENFORCE`, on by default) or announced first; and for Divine Dental's next update.
5. The company facts to publish: registration number, address, P.O. Box, TIN.
6. Prices and the SMS account and sender ID, needed in Phase 1.

## Not deployed

Nothing in this phase is on the live server or in the app stores. Deploying needs the owner's go-ahead.
