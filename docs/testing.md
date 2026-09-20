# Testing

```bash
composer ci                  # Pint --test, PHPStan, empty-file + secrets scans, test suite
php artisan test --compact   # suite only (SQLite in-memory, ~2 min)
php artisan test --filter=Livewire
```

On this Mac the Homebrew `php` is broken; use `/Applications/MAMP/bin/php/php8.3.9/bin/php`.

## Conventions
- Feature tests live in `tests/Feature`, Livewire component tests in
  `tests/Feature/Livewire`. Unit tests in `tests/Unit` must not touch the database.
- Use `Tests\Concerns\InteractsWithTenant` (`actingAsRole('doctor', $hospital)`,
  `actingAsSuperAdmin()`, `withHospital()`) instead of hand-rolling users and the
  `CurrentHospital` context.
- `tests/TestCase.php` calls `withoutVite()`; CI builds assets separately.
- Fixtures never hardcode dates that can fall into the past — compute them
  (`Carbon::now()->next(Carbon::MONDAY)`).

## What every Livewire component test covers
1. Renders for an allowed role (`assertOk` / `assertSee`).
2. `assertForbidden()` for a denied role.
3. Tenant isolation: tenant B's record is not visible / `ModelNotFoundException` on edit.
4. Each action's side-effect through the Service (database assertions, events).
5. Redirect + flash where the action leaves the page.
6. Validation errors surface (`assertHasErrors`).

## Regression guards
- `SpaNavigationHtmlTest` — every in-app link is `wire:navigate`; no hard navigation
  or native dialogs in Livewire views.
- `SubscriptionGateTest` — the subscription gate is applied and persists onto Livewire.
- `RoleEscalationTest` — tenant admins cannot mint super-admins.
- `IntegrityHardeningTest` — sequences, one invoice per visit, closed
  periods, slugs, headers, API login throttle, archive-restore.

## Known gaps (tracked in the plan, Part IV §5.3)
Row-lock behaviour is only proven on MySQL — CI runs the suite on SQLite; a
MySQL-backed job and browser (Dusk/Playwright) smoke tests are the next additions.
