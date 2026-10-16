# 06 · Testing and review — what "done" means

General conventions: [`../testing.md`](../testing.md), [`../OFFLINE_TESTING.md`](../OFFLINE_TESTING.md). Operating rules here.

## Web

- PHP: `/Applications/MAMP/bin/php/php8.3.9/bin/php` (Homebrew's is broken).
- Whole suite exceeds the 512 MB limit in one process (PDF/Cpdf). Run in chunks: `tests/Unit`, `tests/Feature/Api`, `tests/Feature/Livewire`, `tests/Feature/Offline`, `tests/Feature/Finance`, remaining top-level files. Unit tests must not touch the DB.
- After any change in `resources/css` or `resources/js`: `npm run build`, then `tests/Unit/DesignSystemCssTest.php` (it fails if built assets are older than sources). Commit `public/build`.
- Pint (`vendor/bin/pint --test`) and PHPStan stay green (`composer ci`).
- Fixtures never hard-code dates; compute them. Use `Tests\Concerns\InteractsWithTenant`.

### Every new thing gets
- **Livewire screen:** renders for an allowed role · 403 for a denied role · tenant B can't see tenant A · each action's effect via the Service · validation errors · redirect/flash.
- **API endpoint:** 401/403/404/422 envelope · isolation · idempotent replay if it writes · response shape matches the doc.
- **Printable:** added to `GeneratedDocumentTest` (and it must pass the split-layout and size tests).
- **Insight rule / suggestion:** triggers, doesn't trigger, is role-aware.
- **Migration:** runs on SQLite and MySQL.

## App (Flutter)

- `flutter analyze` must be clean; `flutter test` must pass (≈ 274 tests, ~30 s). No network, keystore or device needed: `test/support/fake_http.dart` scripts the API, `fake_sync_server.dart` fakes protocol 1, SQLite runs in memory via FFI.
- Widget tests with sqflite/dio timers need settle loops (`pump(Duration(seconds: 25))`) or they leave pending timers.
- Real-device/integration tests (`integration_test/`) need an unlocked, awake Mac; write-tests refuse `true-doctor.online`. One e2e run per feature, not repeated.
- Look at screenshots (`TD_SHOTS`) for UI work.

## Don't waste time

Don't re-run a slow suite hoping it passes. Read the failure, diagnose, fix, run the narrowest test that proves it, then run the folder once. State what you ran and what you didn't.

## Independent review

For offline, sync, money, tenancy or security changes, **have someone who did not write it review the diff** (a fresh agent with the code and no conclusion from you). Two such rounds on this project found real bugs the author had missed: PIN lock hidden under open screens, a card limit being wiped by a terms edit, "keep mine" wiping a lab flag, a backoff that never re-armed, a ghost patient on re-save after a refusal, a payment form closing mid-request. Brief reviewers with file paths and the question "what can go wrong when…", not with your answer. Verify each finding against the code before fixing.

## Before you say "done"

1. Tests above ran and passed (say which).
2. Docs updated: the module doc, `docs/api/*.md`, handbook if the approach changed, `docs/decisions.md` for non-obvious choices.
3. `git status` clean of your strays; you added explicit paths (never `git add -A` while other agents edit, or over someone's uncommitted files).
4. A short summary to the owner: what changed, what you verified, what you could **not** verify, what is unpushed/undeployed, and the one decision you need from them (if any).
