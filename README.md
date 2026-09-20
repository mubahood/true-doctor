# True-Doctor

**Multi-tenant hospital management SaaS** — patients, appointments, consultations,
pharmacy, lab, radiology, inpatient, billing, insurance and reporting; one
subscription, one login, per hospital. Built on Laravel 12 (PHP 8.2+) with a
Livewire 3 single-page back-office.

## Where things stand

- Every clinical, billing, pharmacy, diagnostic, inpatient and SaaS module from
  `HMS_PLAN.md` phases 1–6 is live, with a Sanctum API and role dashboards.
- The admin UI is a PJAX-style SPA: `wire:navigate` everywhere, a persisted shell,
  Livewire tables and slide-over editors, Vite-built assets. Detail pages are being
  converted module by module — status and remaining work are tracked in
  [`docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md`](docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md).
- Gates: `composer ci` (Pint, PHPStan, empty-file + secrets scans, ~420 tests).

## Requirements

PHP 8.2+ with `bcmath gd intl mbstring pdo_mysql redis` · Composer 2 · MySQL 8 ·
Redis (cache, sessions, Horizon queues) · Node 20.19+ (`.nvmrc`) for asset builds.

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate     # then set DB_*, REDIS_*
mysql -uroot -e "CREATE DATABASE true_doctor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate:fresh --seed                      # roles, super-admin, districts, plans
php artisan storage:link
npm ci && npm run build                               # or `npm run dev` while working on the UI
php artisan serve
```

Visit `/` for the marketing site, `/admin/login` for staff sign-in, `/super/*` as the
super-admin. The seeded super-admin (`admin@gmail.com`) gets a random temporary
password printed once by `AdminUserSeeder` and must change it at first login.

Run `php artisan horizon` and `php artisan schedule:work` alongside the web server.
Production deployment (document root = `public/`) is described in
[`docs/deployment.md`](docs/deployment.md).

## Working on it

- Read [`docs/livewire-conventions.md`](docs/livewire-conventions.md) before adding a
  screen, [`docs/design-system.md`](docs/design-system.md) before writing markup, and
  [`docs/testing.md`](docs/testing.md) before writing tests.
- Non-obvious decisions go in [`docs/decisions.md`](docs/decisions.md) (append-only).
- Full index: [`docs/README.md`](docs/README.md).

## Tests

```bash
composer ci          # everything CI runs
php artisan test     # suite only (SQLite in-memory)
```
