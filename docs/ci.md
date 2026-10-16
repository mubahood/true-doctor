# CI pipeline

Established in Phase 0 Step 2 (HMS_PLAN.md §7, §3.E). Runs on every push/PR via
`.github/workflows/ci.yml`, and locally via `composer ci`:

1. **Secrets scan** (`composer secrets-scan`, `scripts/ci/secrets-scan.sh`) — fails if any
   `.env` variant is git-tracked, or a tracked source file matches a known live-credential
   pattern (AWS keys, private key blocks, payment-gateway live secret keys, Slack tokens).
   No `gitleaks`/`trufflehog` available in this environment, so this is a small,
   dependency-free, deliberately conservative scanner — not a replacement for a proper
   secrets-scanning tool if one becomes available later.
2. **Empty/stub file check** (`composer check-empty-files`, `scripts/ci/check-empty-files.php`)
   — fails on 0-byte PHP files or classes/interfaces/traits/enums with a wholly empty body
   (excludes intentionally-empty `abstract class` customization points, e.g. `tests/TestCase.php`,
   and `database/migrations/archive/`). Enforces constraint A4.
3. **Pint** (`vendor/bin/pint --test`) — code style. Config: `pint.json` (excludes the git-ignored
   `divine-dental-info/`, `storage/`, and `public/install.php`, the pre-boot installer, which is a
   self-contained page in PHP's alternative template syntax and must not be restyled).
4. **PHPStan/Larastan** (`composer stan`, level 5) — static analysis. Config: `phpstan.neon`
   (scans `app/`, `database/`, `routes/`; excludes `database/migrations/archive/`; `_legacy/`
   is outside the scanned paths entirely, same as autoload).
5. **`migrate:fresh --seed`** against a real MySQL service container — proves migrations
   aren't silently broken (the legacy audit's `create_departments_table` had a `return true;`
   before `Schema::create` — a no-op that never surfaced because nothing ran migrations in CI).
6. **Test suite** — SQLite in-memory per `phpunit.xml`, **in shards**
   (`scripts/ci/test-shard.sh unit|api|livewire|offline|feature|all`): the whole suite does not fit
   in one 512 MB process (the PDF tests), so each folder (and each root file) runs in its own
   process. The workflow's `tests` job runs the five shards in parallel; `composer test` runs them
   in turn locally. Every part runs even after a failure, so one failure never hides another.
7. **Support number scan** (`composer support-number-scan`) — fails if the support WhatsApp number
   is typed anywhere but `config/support.php` and `.env.example` (read it from `SupportContact`).

**The runner.** GitHub-hosted runners were blocked by the account's billing (2026-10). The workflow
runs unchanged on a self-hosted runner (`runs-on: self-hosted` on a small VPS, the owner's decision)
or on any CI that runs GitHub Actions syntax. Until then `composer ci` locally is the gate.

`_legacy/` is excluded from every one of these (autoload, Pint, PHPStan) — nothing there is
built, linted, or type-checked, matching it not being autoloaded or routed (README.md).
