# Standalone edition: execution plan

This is the implementation checklist for [standalone-edition.md](standalone-edition.md).
The acceptance criteria are R1–R11 and S1–S3 in that document. A checked box
means the work was implemented and its stated verification passed. Do not treat
an unchecked phase as delivered. Work in this repository only; do not create
an edition fork, a copied application, or an edition branch.

## 0. Baseline and boundaries

- [x] Read the proposal, tenancy, hosting, finance, practice, navigation, payments,
  onboarding, testing, and Livewire conventions; identify affected code paths.
- [x] Record baseline SaaS test, route, asset, and migration results.
- [x] Map every platform only HTTP route, API route, command, scheduled task,
  navigation entry, and registration path (`edition:saas` middleware, Navigation
  `edition` key, edition guards in commands and schedules).
- [x] Signing key: Ed25519 secret only in the SaaS `.env` (+ one offline copy);
  public keys travel in each package as `RELEASE_KEYS.json`. Packages are built from
  an allow-list and the build fails on a secret-looking path.

## 1. Edition boundary (R1–R3, R2, S2–S3)

- [x] Validated `APP_EDITION` and `App\Support\Edition`.
- [x] Public site, signup, trial, subscriptions, checkout, super panel, callbacks,
  demo, commands, schedules and navigation gated by edition.
- [x] Hospital and API routes preserved; subscription gate bypassed for standalone;
  edition and version in `/api/v1/meta`.
- [x] Single hospital enforced in the model, a unique slot column and DB triggers.
- [x] `StandaloneSmokeRoutesTest`: every admin page, every role's menu and the API in
  standalone; SaaS `SmokeRoutesTest` asserts standalone-only pages are absent.

## 2. Licensing foundation (R3, R7–R8, S3)

- [x] Canonical signed licence, key ids for rotation, offline verification.
- [x] Licences limit only updates and optional staff seats; never care (tests).
- [x] Valid, altered, expired, wrong host, wrong key and absent licences tested.

## 3. Buyer installation (R4, R6–R7)

- [x] Pre-Laravel `install.php` (CSRF, lock, encrypted draft, browser-bound token).
- [x] Requirements, licence, database, organisation, owner, mail, cron line.
- [x] Migrations in short browser steps, reference seeders, chart of accounts,
  hand-off to the practice wizard; `standalone:install` uses the same engine.
- [x] `resources/standalone/INSTALL.md` rewritten; rendered on the buyer portal.

## 4. Browser managed releases (R5–R7, R9–R11, S1–S2)

- [x] Super → Standalone releases: prefilled version and notes (since the last
  release), readiness checklist, progress, publish, withdraw, retry, download.
- [x] Built from `main` with `git archive`, committed assets and the deployed no-dev
  `vendor/` (Composer only when the lock differs) — no Node needed on the host.
- [x] Signed manifest inside the archive (`PACKAGE.json`).
- [x] Clean install of the packaged ZIP on MySQL before publish; a dedicated proof
  database is supported for cPanel, never the application database.

## 5. Safe updates and backups (R4, R7, S3)

- [x] Settings → Licence & updates and `standalone:update`; signature, version and
  entitlement checked before anything changes; work runs via the scheduler.
- [x] Maintenance, backup (database, `.env`, licence, touched code), manifest swap,
  migrations/seed/backfill/health in a fresh process, automatic rollback (tests).
- [x] Nightly `standalone:backup` (seven kept, downloadable, age warning).

## 6. Sale, fulfilment, and product controls (R8–R11)

- [x] `standalone_products` managed in Super → Standalone products (renewal price).
- [x] Pesapal purchase, verified idempotent settlement, licence issue, email,
  passwordless portal.
- [x] Portal: newest entitled release, licence file, checksum, notes, the full guide,
  earlier releases, address change, online renewal; manual licences emailed.
- [x] Duplicate settlement, underpayment, renewals, entitlement and portal tested.

## 7. Full acceptance and handoff

- [x] Full suite, PHPStan (app/), Pint, empty-file and secrets scans green.
- [x] Decisions recorded in `docs/decisions.md`.
- [x] Live: deployed; signing key `2026-a` in the live `.env` (offline copy in
  `~/.true-doctor-signing/`); dedicated proof database `schooics_tdproof`; releases
  built by the queued worker on the live host in ~20 s each.
- [x] Clean-room drill with the real ZIP from live: web installer over HTTP from a
  nested cPanel-style web root into empty MySQL (103 migrations, 21 steps), sign-in,
  setup wizard, one-organisation triggers, online pages absent; a real-key-signed
  update 1.0.1 → 1.0.2 through `standalone:update` with data intact; backups restored
  both by the app and by the plain `mysql` client.

## Progress log

- 2026-10-15: Proposal reread. Current checkout is `public-release` at
  `7de5199`; `docs/README.md` and `docs/proposals/` already have user edits.
  No branch or copied project was created.
- 2026-10-15: Fast forwarded the existing local `main` pointer to `7de5199`
  and switched this checkout to `main` with the worktree intact. Added the
  edition boundary, first hospital unique slot, no-subscription access,
  standalone reference seeder, and offline licence verifier. Focused edition
  and licence tests passed (8 tests, 35 assertions), as did 48 existing SaaS
  regression tests (718 assertions). The baseline full suite was interrupted
  before code changes because it runs much longer; full dual-edition acceptance
  remains open. PHPStan exposed three pre-existing `VisitPhrases` type errors;
  the redundant fallbacks were removed and recheck is pending.
- 2026-10-15 (second agent): Resumed from the edition boundary. Fixed the release
  build for the real host (no Node; Composer at ~/bin; dedicated proof database),
  added updates with rollback, backups, the Licence & updates page, the update feed,
  online renewals, address changes, notification emails (a `match(...)->` syntax
  error had silently broken them), house-style super-admin pages with titles, a
  rewritten guide, the standalone smoke walk, and fixed a pre-existing DemoSeeder
  crash when the second demo tenant is off. Full suite: 2189 passed before the
  final style fixes, which were re-verified file by file.
- 2026-10-15 (second agent, live): 1.0.0 built on live, then withdrawn unpublished —
  the clean-room drill found its installer refused every licence (it loaded
  config/edition.php before Laravel existed). Fixed with a regression guard; the
  drill also led to the app being found from nested web roots and Licence & updates
  being reachable during setup. 1.0.1 published from abc6e39 and drilled end to end.
