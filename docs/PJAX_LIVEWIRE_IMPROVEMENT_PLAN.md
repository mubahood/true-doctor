# True-Doctor — Full-Project Audit & PJAX/SPA Perfection Plan (Livewire 3)

**Status:** Audit complete, plan ready for execution · **Date:** 2026-09-13 · **Branch audited:** `hms-restructure` @ `65dccce` (working tree clean)
**Stack:** Laravel 12.62 · PHP 8.2 target · Livewire 3.8.2 (Alpine bundled) · MySQL 8 · Redis/Horizon · hand-written `td-admin.css`
**Scope:** every file in the repository — 334 PHP files under `app/`, 205 Blade views, 29 Livewire components, 65 migrations, 402 tests, `public/`, `docs/`, CI, config, deploy.

> **How to read this document.** Part I is the verdict and the target architecture (what "perfect PJAX with Livewire" concretely means for this codebase). Part II is the exhaustive findings register, grouped by area, every item with `file:line`, severity and the fix. Part III is the module-by-module conversion matrix. Part IV is the execution roadmap with definition-of-done per step. Part V is the house rules that keep it perfect afterwards. Part VI is the acceptance checklist. Nothing in here is speculative: every claim was verified against the code, the installed Livewire source, or by running the tool (route list, test suite, PHPStan, Pint).

**Severity legend:** 🔴 P0 blocker (security/data/correctness) · 🟠 P1 breaks the SPA promise or will break in production · 🟡 P2 quality/consistency/performance · 🔵 P3 polish/hygiene.

---

## Table of contents

- **Part I — Verdict & target architecture**
  - 1. Executive summary
  - 2. Where the project actually stands (facts, not the README)
  - 3. The target: what "perfect PJAX with Livewire 3" means here
  - 4. Target architecture — shell, navigation, page shapes, feedback, state, real-time, security
- **Part II — Findings register (everything that needs improvement)**
  - A. Admin shell & layout (`layouts/admin.blade.php` and partials)
  - B. Navigation coverage (`wire:navigate`)
  - C. Livewire components (`app/Livewire`, `resources/views/livewire`)
  - D. Pages still rendered by controllers (classic Blade)
  - E. Forms, flash messages & feedback
  - F. Pagination
  - G. Design system, CSS & markup consistency
  - H. Assets, build pipeline & front-end dependencies
  - I. Security & tenancy (backend)
  - J. Authorization
  - K. Data integrity & concurrency
  - L. Performance
  - M. Config, environment & deployment
  - N. Dead code, legacy residue & repo hygiene
  - O. Documentation
  - P. Tests & CI
  - Q. Accessibility & mobile
- **Part III — Module-by-module conversion matrix**
- **Part IV — Execution roadmap (phased, with definition of done)**
- **Part V — House rules: the Livewire/PJAX constitution**
- **Part VI — Acceptance checklist ("perfect" defined)**
- **Appendix — Verification commands and counts**

---

# Part I — Verdict & target architecture

## 1. Executive summary

The domain layer is strong (services, policies, enums, bcmath money, append-only ledgers, a real tenancy scope, 402 tests). The **SPA layer is half-built**: every list page is a Livewire table with a modal, but every detail page, every action form and most of the app's links still do full page loads, and the shell itself has lifecycle bugs under `wire:navigate`. Around it sits a large amount of residue from three previous products, a fragile subdirectory deploy hack, and several severe backend gaps that are missing *wiring* rather than missing *design*.

**The 15 findings that matter most (all verified):**

| # | Sev | Finding | Where |
|---|---|---|---|
| 1 | 🔴 | A **134 MB production SQL dump of a previous product** (users + bcrypt hashes, KYC, clients, chats) is tracked in git and is 97 % of the 158 MB `.git`. | `storage/backups/cryptocoinex_20260701.sql` |
| 2 | 🔴 | Root `.htaccess` + root `index.php` make the **entire repository web-servable** when the document root is the project root (`.env`, `composer.lock`, the dump above, `storage/logs`). | `/.htaccess:13-19`, `/index.php`, `public/.htaccess:25` |
| 3 | 🔴 | The `subscribed` middleware (subscription gate) is implemented, aliased, and **applied to zero routes**. Lapsed tenants keep full access forever. | `bootstrap/app.php:69` vs `routes/web.php` |
| 4 | 🔴 | A hospital admin can assign (or self-assign) the `super_admin` role; every policy's `before()` then allows everything. | `app/Models/User.php:127-130`, `app/Livewire/Users/Index.php:113`, `app/Http/Controllers/Admin/UserController.php:56`, `app/Policies/*::before` |
| 5 | 🔴 | The custom Livewire update route registered for subdirectory hosting has **no `web` middleware** (no session, no CSRF, no `ResolveHospital`). It works today only because Laravel strips the base path and the default route wins. | `app/Providers/AppServiceProvider.php:117-119`; confirmed via `route:list` |
| 6 | 🔴 | **CI has been red since 2026-08-03**: 8 tests hardcode a Monday that is now in the past. | `tests/Feature/AppointmentBookingHttpTest.php:20`, `tests/Feature/Api/AppointmentApiTest.php:21` |
| 7 | 🔴 | Horizon (queue payloads incl. PHI across tenants) is open to every staff role; Telescope defaults to enabled. | `app/Providers/HorizonServiceProvider.php:30-34`, `config/horizon.php:86`, `config/telescope.php:19` |
| 8 | 🟠 | **233 of 255 links in classic Blade views lack `wire:navigate`**, and 9 Livewire index tables use `onclick="window.location=…"` on rows — the primary click on every list is a full reload. | Part II §B |
| 9 | 🟠 | **113 classic POST forms** remain (patients/show has 12, visits/show has 12); every submit reloads. | Part II §D, §E |
| 10 | 🟠 | Shell lifecycle bugs under `wire:navigate`: `livewire:navigated` listener piles up per navigation, footer `setInterval` leaks per navigation, the `sw-kill` head script re-executes on every navigation because it embeds `now()->timestamp`, `chart.min.js` (173 KB, zero consumers) re-executes each navigation. | `layouts/admin.blade.php:128-131,173-215`, `partials/sw-kill.blade.php:17` |
| 11 | 🟠 | `->title()` on all 29 Livewire pages is inert (layout reads a Blade *section*, Livewire passes a *variable*); every Livewire page ships `<title>Dashboard · True-Doctor</title>` and an Alpine scraper patches it after paint. | `layouts/admin.blade.php:9,65-77` |
| 12 | 🟠 | Livewire tables render Livewire's **Tailwind pagination view**, but Tailwind is not loaded anywhere; the CSS patch styles `a`/`span` while the view uses `<button>`. Pagination is unstyled and rendered twice (mobile + desktop blocks). | `vendor/livewire/.../SupportPagination.php:60`, `public/css/td-admin.css:343-347` |
| 13 | 🟠 | Unbounded option lists (every patient, every stock item) are queried on **every render** of six components, including every search keystroke, and rendered into `<select>`s on detail pages. | Part II §L |
| 14 | 🟠 | Four `count()+1` sequence generators (invoice/visit/patient/claim numbers) are non-sargable and racy; appointment double-booking is reachable; duplicate invoices per visit are reachable. | `BillingService.php:241`, `AppointmentService.php:163-169`, `BillingService.php:125-142` |
| 15 | 🟡 | ~4.4 MB of dead assets, 3 dead layouts (one extends itself), 13 dead Breeze components, 28 orphaned index views, ~34 unreachable controller methods, 4 stale product plans at the repo root, `docs/IMPROVEMENT_PLAN.md` describes a crypto trading simulator. | Part II §N, §O |

**Gate status at audit time:** Pint ✅ · PHPStan level 5 ✅ (0 errors) · `php artisan test` ❌ **8 failed / 16 skipped / 386 passed** · `route:cache` ❌ impossible (7 closures in `routes/web.php`).

## 2. Where the project actually stands

`README.md:43-51` says "Phase 0 is complete". Reality, verified from the code and git log:

| Layer | Shipped | Not shipped |
|---|---|---|
| Domain | 23 services, 21 policies, 49 FormRequests, 27 enums, 54 models (49 tenant-scoped), Flutterwave gateway, plan limits, onboarding, self-registration, notifications, reports, role dashboards | Reverb/Echo, 2FA, PHI read-audit, `/health` probes, Sentry, structured logging |
| API | Sanctum v1 with envelope, 8 controllers, OpenAPI stub | token abilities/expiry, login throttle, spec drift check |
| Livewire | 29 components: 26 index tables (`WithTable`), 24 slide-over modals, patient full-page form, sample-import trait, toast host, `wire:navigate` on sidebar + dashboard | detail pages, action forms, queue/board/reports/notifications/settings, `@persist`, `#[Lazy]`, `#[Computed]`, `Form` objects, `wire:poll`, `@script`/`@assets`, prefetch, offline, Livewire config |
| Front-end pipeline | hand-written `td-admin.css` served via `asset()` + `filemtime()` | Vite is configured but unused; Tailwind 3 + Tailwind 4 plugin both declared; `public/build` absent |
| Tests | 402 tests (80 Livewire), tenancy isolation suites, money service suites | browser tests, MySQL-backed lock tests, policy unit tests, coverage |
| Deploy | MAMP subdirectory hack committed | real deploy doc, docroot=public, trusted proxies, security headers |

Roadmap docs (`docs/frontend-execution-roadmap.md`) are honest that detail pages, forms-on-detail-pages, reports, settings, notifications and real-time were deferred. This plan finishes them.

## 3. The target: what "perfect PJAX with Livewire 3" means here

"PJAX" (pushState + AJAX) in Livewire 3 terms is `wire:navigate`: the browser never does a full document load after login; each navigation fetches the next page's HTML, swaps `<body>`, merges `<head>`, preserves persisted regions, and updates history. Around it, Livewire actions replace every form POST with an in-place round-trip that morphs only the changed DOM. Done properly, the app must satisfy all of the following, always:

1. **Zero full-page loads after sign-in**, except sign-out, PDF/receipt downloads and the external payment redirect. Every `<a>` is `wire:navigate`; no `window.location`, no `location.reload()`, no `<form method="POST">` except those three cases.
2. **A persistent shell.** Sidebar, topbar, toast host and footer are `@persist`-ed: no flicker, no accordion replay, no lost scroll, no lost toast, no re-run of shell scripts.
3. **Correct document semantics.** Server-rendered `<title>`, one `<h1>` per page, history entries labelled correctly, back/forward restores scroll and (for tables) filter state from the URL.
4. **Every mutation is a Livewire action** with a `wire:loading` state, `wire:confirm` on destructive actions, pessimistic behaviour for money/clinical writes, inline validation errors, and one toast on success. Zero `alert()`/`confirm()`.
5. **Every page is one of four shapes** — List, Editor (slide-over or full page), Detail (workspace with lazy sections), Board (polled) — built from one component kit. No bespoke markup per screen.
6. **Scripts are Livewire-aware.** Shell script runs once (`data-navigate-once`), component JS uses `@script`/`@assets`, no `DOMContentLoaded`, no `@push('scripts')` for Livewire-rendered pages, no leaked timers/listeners.
7. **Security is structural, not per-component.** Livewire's update route carries the same middleware as the page (`web` + persistent `admin`/`super`/`subscribed`/`permission`), every component authorizes in `mount()` *and* `render()`, every public prop that identifies a record is `#[Locked]`, every user-editable list prop is validated, `perPage`/`search` are clamped.
8. **Performance is bounded.** Option lists are `#[Computed]` and searchable, never whole tables; render queries are eager-loaded; the layout runs no per-request COUNT; heavy detail sections are `#[Lazy]` with skeletons; worklists poll with `wire:poll.visible`.
9. **Feedback has one pipeline.** Server flash → toast bridge → `dispatch('toast')`; the layout banner and the 29 per-view `session('error')` blocks are gone.
10. **It is testable.** `Livewire::test()` per component (render, filter, RBAC, tenancy, side-effects, redirect), an HTML regression test that greps for `window.location`/`method="POST"` in Livewire views, and a small browser suite for the navigate lifecycle.

## 4. Target architecture

### 4.1 The shell (`layouts/admin.blade.php` rewritten)

```blade
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  {{-- Livewire passes $title as a variable; controller pages yield a section. Handle both. --}}
  <title>{{ $title ?? trim($__env->yieldContent('title')) ?: 'Dashboard' }} · True-Doctor</title>
  <link rel="icon" href="{{ asset('images/favicon.png') }}" sizes="48x48">
  @vite(['resources/css/td-admin.css', 'resources/js/admin.js'])   {{-- hashed, minified, one request each --}}
  <style>[x-cloak]{display:none!important}</style>                  {{-- must be in <head>, before body paints --}}
  @livewireStyles
  @stack('styles')
</head>
<body>
  {{-- Persisted chrome: survives every wire:navigate, never re-hydrates --}}
  @persist('sidebar')
    <aside class="tb-sidebar" x-data="tdSidebar()">@include('partials.admin-nav')</aside>
  @endpersist

  <div class="tb-main">
    @persist('topbar')
      <header class="tb-topbar" x-data="tdTopbar()">
        …hamburger, page-title mirror (reads document.title), <livewire:shell.notification-bell />, user menu…
      </header>
    @endpersist

    <main class="tb-content">
      @yield('content'){{ $slot ?? '' }}
    </main>

    @persist('footer')<footer class="tb-footer" x-data="tdClock()">…</footer>@endpersist
  </div>

  @persist('toasts')@include('partials.toast-host')@endpersist
  <livewire:shell.profile-modal />      {{-- replaces the fetch()+location.reload() profile/password modals --}}

  @livewireScripts
  @stack('scripts')
</body>
</html>
```

Rules embodied above:

- `<title>` handles both render modes (fix for finding 11); the Alpine h1-scraper is deleted.
- `[x-cloak]` moves to `<head>` (today it is at the end of `<body>`, so the whole `x-cloak`-ed body flashes on every full load).
- All shell JavaScript moves into `resources/js/admin.js` (built by Vite, loaded in `<head>`, so it runs exactly once). It registers `Alpine.data('tdSidebar' | 'tdTopbar' | 'tdClock' | 'tdToasts')` on `alpine:init` and hooks `livewire:navigated` **once**. The inline `tdAdmin()` body script, the flash `setTimeout`, and the raw `setInterval` clock are removed.
- `chart.min.js` is removed from the shell (zero consumers). If a chart is ever needed, it is loaded by the component that needs it via `@assets`.
- The self-profile/password modals become a Livewire component (`Shell\ProfileModal`) so saving no longer does `location.reload()` and no longer caches a CSRF token that can go stale.
- The notification bell becomes `Shell\NotificationBell` with `wire:poll.60s.visible` so the layout no longer runs `unreadNotifications()->count()` on every render.
- `partials/sw-kill` is deleted (the retired service worker is unregistered by `public/sw.js` itself); if a one-shot kill switch is still wanted, it is a static file with `data-navigate-once` and no timestamp.

### 4.2 Livewire configuration (currently unpublished)

Publish `config/livewire.php` (`php artisan livewire:publish --config`) and set:

```php
'layout' => 'layouts.admin',
'navigate' => ['show_progress_bar' => true, 'progress_bar_color' => '#0a6ebd'],
'lazy_placeholder' => 'livewire.partials.skeleton',
'pagination_theme' => 'tb',            // resolved by WithTable::paginationView() (see §4.6)
'temporary_file_upload' => ['rules' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'], 'max_upload_time' => 5],
'inject_morph_markers' => true,
```

Publish and version the assets (`php artisan livewire:publish --assets`) so `livewire.js` is served statically with long-lived caching rather than through a route, and remove the `livewire.asset_url` override once the app is served from a domain root.

### 4.3 Middleware on the Livewire wire

Two changes, both mandatory:

```php
// AppServiceProvider::fixLivewireSubdirectoryUrls() — only until the deploy moves to docroot=public/
Livewire::setUpdateRoute(fn ($handle) => Route::post($base.'/livewire/update', $handle)->middleware('web'));

// AppServiceProvider::boot() — route middleware that must re-run on every Livewire request
Livewire::addPersistentMiddleware([
    \App\Http\Middleware\IsAdmin::class,
    \App\Http\Middleware\IsSuperAdmin::class,
    \App\Http\Middleware\EnsureSubscribed::class,
    \App\Http\Middleware\RequireOnboarding::class,      // already self-exempts X-Livewire requests; harmless
    \Spatie\Permission\Middleware\PermissionMiddleware::class,
    \Spatie\Permission\Middleware\RoleMiddleware::class,
]);
```

Livewire 3.8's default persistent list (`vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php:16-23`) contains only `Authenticate`, `Authorize`, `SubstituteBindings`, Sanctum stateful, and `RedirectIfAuthenticated`. Today `admin`, `super`, `permission:*` and `subscribed` are enforced on the initial GET only; subsequent Livewire requests rely entirely on each component re-authorizing by hand. Adding them to the persistent list makes the guarantee structural.

### 4.4 The four page shapes and the component kit

Every admin screen becomes one of:

| Shape | Built from | Livewire features | Example |
|---|---|---|---|
| **List** | `<x-ui.page>` + `<x-ui.toolbar>` (search, filters, actions) + `<x-ui.table>` + `<x-ui.pagination>` + `<x-ui.empty>` | `WithTable` v2 (URL-bound state, clamped `perPage`, generic `resetPage`, real sorting or none), `wire:loading.class.delay` with explicit `wire:target`, `wire:navigate` row links | Patients, Invoices, Lab orders |
| **Editor** | `<x-ui.modal>` (centred, focus-trapped, ESC with dirty-guard) or a full page + `<x-ui.field>` | `Livewire\Form` object per model reusing FormRequest `rules()`, `#[Validate]` where trivial, `wire:model.blur` for inline validation, `wire:submit`, `WithFileUploads` with progress | Department modal, Patient form, Stock item |
| **Detail / workspace** | `<x-ui.page-header>` + `<x-ui.detail-cols>` + child components per panel, each `#[Lazy]` with a skeleton | actions are `wire:click` + `wire:confirm`; panels dispatch `$refresh`/events to siblings; money actions pessimistic | Visit workspace, Patient record, Invoice, Admission |
| **Board / worklist** | `<x-ui.page>` + cards/rows | `wire:poll.30s.visible` (Reverb later), `wire:transition` | Check-in queue, Occupancy board, Stock alerts, Notifications, Dashboard sections |

The kit (`resources/views/components/ui/*`), which the roadmap deferred, is now required: `page`, `page-header`, `breadcrumb`, `toolbar`, `table`, `th-sort`, `pagination`, `empty`, `skeleton`, `field`, `select-search` (async typeahead), `badge` (driven by enum `badge()`), `money`, `modal`, `confirm`, `link` (always `wire:navigate`), `icon-button` (always `aria-label`), `detail-cols`, `stat`, `section`. Existing `x-dash.*` components fold into the same namespace.

### 4.5 Forms, validation and services (one source of truth)

```php
// app/Livewire/Forms/DepartmentForm.php
class DepartmentForm extends \Livewire\Form
{
    public ?Department $department = null;   // #[Locked] set via setDepartment()
    public string $name = ''; public ?string $code = null; public ?int $head_user_id = null; …

    protected function rules(): array { return DepartmentRequest::rulesFor($this->department?->id); } // shared static

    public function store(): Department { $this->validate(); return Department::create($this->normalized()); }
    public function update(): void      { $this->validate(); $this->department->update($this->normalized()); }
}
```

- Every FormRequest exposes `public static function rulesFor(?int $ignoreId, ?int $hospitalId = null): array` and its own `rules()` delegates to it. The 18 Livewire components that currently duplicate rules verbatim delegate too. Drift becomes impossible.
- Components with a Form object shrink to: `create()`, `edit($id)`, `save()`, `delete($id)`, `render()`. The `~90 %-identical` `save()`/`edit()` bodies across 15 components collapse into a `CrudModal` trait with `modelClass()`, `form`, `labels()`.
- Domain exceptions are caught narrowly (`DomainException` subclasses, `PlanLimitExceededException`, `SlotUnavailableException`, `InsufficientStockException`, `ClosedPeriodException`); anything else is `report()`ed and shown as a generic toast. No `catch (\Throwable)` shown verbatim to users.

### 4.6 Pagination that is both themed and PJAX

`WithTable` declares `public function paginationView(): string { return 'livewire.partials.pagination'; }`. That view is a copy of Livewire's `tailwind.blade.php` rewritten with `tb-*` classes, `<button wire:click="gotoPage(…)">` (AJAX, no reload), no duplicated mobile/desktop blocks, no `scrollIntoView` jump, and a proper `aria-label`/`aria-current`. The Blade `vendor/pagination/simple-default` stays for the one classic page left (none, once notifications is converted).

### 4.7 Feedback pipeline

One path only: components `dispatch('toast', …)`; controllers that must redirect use `->with('toast', ['type' => …, 'message' => …])`; the persisted toast host reads the session bridge once per full load and listens for the window event. The layout banner (`layouts/admin.blade.php:103-110`) and every per-view `@if(session('error'))` block are deleted. Components that `redirect(navigate: true)` flash instead of dispatching (or rely on the persisted host — with `@persist('toasts')` a dispatched toast survives navigation; both are acceptable, but pick one: flash).

### 4.8 State, URLs and history

- Table state (`q`, filters, page, sort) stays in the URL via `#[Url(history: true)]` so back/forward and refresh restore it. `sortField`/`sortDir` are removed from URLs on tables that do not sort.
- Slide-over open state is **not** in the URL (a refresh closes it); the full-page patient editor stays deep-linkable.
- `Cache-Control: no-store` on authenticated HTML responses so Livewire's navigate cache and bfcache never show an authenticated page after logout.

### 4.9 Real-time

`wire:poll.30s.visible` on the queue, occupancy board, stock alerts, notification bell, dashboard sections and lab/radiology worklists. Reverb + Echo on private `hospital.{id}` channels is the phase-after (`#[On('echo-private:hospital.{id},AppointmentChanged')]`), gated by an ops decision, not needed for correctness.

### 4.10 Scripts and islands

- Shell JS in `resources/js/admin.js` (Vite, `<head>`, runs once).
- Component-local JS only via `@script` (re-runs after every morph) and third-party libraries via `@assets` (deduped, `data-navigate-once`).
- Islands (chart, signature pad, barcode scan) live inside `wire:ignore` roots with `x-data` and are initialised from `@script`; never from `@push('scripts')`.
- Anything that adds a `document`/`window` listener or a timer must remove it on `livewire:navigating` or use Alpine `$cleanup`-style teardown (`x-init` return function).

### 4.11 Security posture on the wire

- `#[Locked]` on every `editingId`, `$patient`, `$visit`, `$invoice` style prop. The current pattern (client can change `editingId`; server re-authorizes) is safe but must be explicit.
- Public array props that reach persistence (`ImportsSamples::$samples`) are validated with a rule set before use; `toggleAllSamples()` authorizes.
- `perPage` whitelisted to `[10, 20, 50, 100]`; `search` capped at 100 chars; `#[Url]` props validated on `updating*`.
- Livewire update requests carry the persistent middleware (4.3), so a suspended tenant or demoted user is cut off on the next action, not the next page load.

---

# Part II — Findings register

Each row: **ID · Severity · Where · What · Fix**. IDs are stable so the roadmap (Part IV) can reference them.

## A. Admin shell & layout

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| A1 | 🟠 | `resources/views/layouts/admin.blade.php:180-182` | Body `<script>` registers `document.addEventListener('livewire:navigated', …)`; Livewire re-executes body scripts on every navigation → one extra permanent listener per navigation. After 30 navigations the scroll-unlock handler runs 30×. | Move shell JS to a built `<head>` bundle (4.1) or add `data-navigate-once` + an `if (!window.__tbNavHooked)` guard. |
| A2 | 🟠 | `layouts/admin.blade.php:128-131` | Footer clock `setInterval(f, 15000)` created in `x-init`, never cleared; Alpine's destroy on body swap does not clear raw intervals → one leaked timer per navigation holding a detached node. | `x-init="const id = setInterval(…); return () => clearInterval(id)"` or `@persist('footer')`. |
| A3 | 🟠 | `resources/views/partials/sw-kill.blade.php:17` | Head script embeds `built={{ now()->timestamp }}`, so its `outerHTML` differs on every render; Livewire's head merge treats it as new and **re-injects + re-executes it on every navigation**: enumerates/unregisters service workers, deletes all Cache Storage, attaches another `load` listener, can `location.reload()`. Also logs `CX-DIAG` (Cryptocoinex) to every visitor's console and sets `<meta name="color-scheme" content="dark light">` on a light-only app. | Delete the partial. `public/sw.js` already self-unregisters. |
| A4 | 🟠 | `layouts/admin.blade.php:214` | `<script defer src="vendor/js/chart.min.js">` (173 KB) in `<body>` with no `data-navigate-once` → re-fetched/re-executed each navigation. `grep "new Chart" resources` = 0: **nothing uses it** (dashboard charts are CSS/SVG). | Delete the tag and the file. |
| A5 | 🟠 | `layouts/admin.blade.php:9,65-77` | `<title>@yield('title')…` — Livewire's `->title()` merges into layout *params* (`$title`), not a section (`vendor/livewire/.../SupportPageComponents.php:39-43`), so all 29 `->title()` calls are inert; server HTML says "Dashboard" on every Livewire page; an Alpine scraper reads `.tb-content h1` after `$nextTick` to patch `document.title` (history entries are recorded before that). | `<title>{{ $title ?? trim($__env->yieldContent('title')) ?: 'Dashboard' }} · True-Doctor</title>`; delete the scraper; keep the topbar mirror reading `document.title`. |
| A6 | 🟠 | `layouts/admin.blade.php:21,211` | `<body x-data="tdAdmin()" x-cloak>` while the `[x-cloak]{display:none}` rule is emitted at the **end of the body** → guaranteed flash of unstyled/hidden content on every full load; the whole app is hidden until `@livewireScripts` at the bottom boots Alpine. | Rule into `<head>`; drop `x-cloak` from `<body>` (cloak only the bits that need it). |
| A7 | 🟠 | `layouts/admin.blade.php:184-208` | `tdAdmin()` defined in a body script and consumed by `<body x-data>`; works only by load order. `saveProfile()` does `location.reload()`; `savePassword()` uses `alert()`; `_token` cached once (stale after session regeneration → 419 with a useless alert). | Replace with `Shell\ProfileModal` Livewire component; register Alpine data on `alpine:init` from the head bundle. |
| A8 | 🟡 | `layouts/admin.blade.php:25-26` | `$u->unreadNotifications()->count()` + lazy `$u->hospital->name` = 2 extra queries on every render, no index on `read_at`. | `Shell\NotificationBell` with `wire:poll.60s.visible` + short-TTL cache; eager-load hospital on the auth user. |
| A9 | 🟡 | `layouts/admin.blade.php:17` | `?v={{ filemtime(public_path('css/td-admin.css')) }}` — `stat()` per request; warns and returns `false` if the file is missing; not CDN-fingerprintable. | Serve via `@vite` (hashed filename). |
| A10 | 🟡 | `layouts/admin.blade.php:13-15`, `auth.blade.php:13`, `marketing.blade.php:21` | Inter loaded from `fonts.googleapis.com` (third-party, render-blocking, privacy-relevant for a PHI app) while `public/vendor/fonts/inter.woff2` exists unused; three layouts declare three different weight sets. | Self-host via `@font-face` in the built CSS with `font-display: swap`. |
| A11 | 🟡 | `layouts/admin.blade.php:16` | Full FontAwesome (1.0 MB incl. webfonts) for 130 used icons. | Subset (FA kit or SVG sprite) — ~900 KB saved. |
| A12 | 🟡 | `layouts/admin.blade.php:103-110` + `partials/toast-host.blade.php:15-19` | Two renderers of `session('success'|'error')` → every controller redirect shows the message **twice** (banner + toast); `$errors->any()` also rendered here **and** in 11 `_form` partials. | Delete the banner; single session bridge in the persisted toast host. |
| A13 | 🟡 | `layouts/admin.blade.php:32-52,59-100,112-133` | No `@persist` anywhere → sidebar scroll position lost, accordion `x-collapse` replays on every page, toast array destroyed mid-toast, `admin-nav` re-evaluates 10 groups × permissions each navigation. | `@persist('sidebar'|'topbar'|'toasts'|'footer')` per 4.1. |
| A14 | 🟡 | `partials/admin-nav.blade.php:94-95` | `x-data="{ open: $persist(…).as('td_nav_open') }"` then `x-init` unconditionally overwrites it with the active group → user's manual collapse never survives navigation. | Only set when `open === ''` or when the active group changed. |
| A15 | 🟡 | `partials/admin-nav.blade.php:111` | Sidebar links lack `wire:navigate.hover` (prefetch). | Add `.hover` on sidebar + dashboard quick actions. |
| A16 | 🟡 | `layouts/admin.blade.php:47-51,95-97` | Two identical sign-out `<form>`s in one document; the dropdown one is always in the DOM. | One form, referenced by both triggers (or `wire:click="logout"` on a shell component with a real redirect). |
| A17 | 🟡 | `layouts/admin.blade.php:33-40,84,90-91,105-108` | a11y: `<button>` nested inside the brand `<a>`; user-menu trigger is a `<div @click>` (not focusable); "My profile"/"Change password" are `<a href="#">`; alert-dismiss buttons have no accessible name; nav group buttons have `aria-controls` but no `aria-expanded`. | Use `<button>`s with `aria-label`/`aria-expanded`; un-nest the close button. |
| A18 | 🟡 | `layouts/admin.blade.php:3` + `partials/sw-kill.blade.php:3-4` | `data-theme="light"` hardcoded while `ThemeController`, `POST admin/theme`, `users.theme`, `admin/settings/index.blade.php:44-51` still offer a Light/Dark preference nothing applies; the meta says `dark light`. | Delete the theme feature end-to-end (route, controller, column via migration, settings UI, `public/js/theme.js`). |
| A19 | 🔵 | `layouts/admin.blade.php:215` | `@stack('scripts')` in `<body>` — pushed scripts re-run on navigate but not on Livewire updates; a top-level `const` in any pushed script would throw "already declared" on the second navigation. Only `admin/users/edit` uses it (an IIFE). | Document "no `@push('scripts')` on Livewire-rendered pages"; remove the stack once `users/edit` is retired. |
| A20 | 🔵 | `public/css/td-admin.css:404` | `.tb-scroll-lock{overflow:hidden}` does not preserve scroll offset → iOS Safari jumps to top when a slide-over opens. | `position:fixed; top:-Ypx; width:100%` pattern, restore on unlock. |
| A21 | 🔵 | `partials/toast-host.blade.php:3` | Toast host is inside the swapped region; toasts dispatched immediately before a `redirect(navigate:true)` are lost (see E-findings). | `@persist('toasts')`. |

## B. Navigation coverage (`wire:navigate`)

Counts (verified by grep): **41** `wire:navigate` in the whole `resources/views` tree; **237** `href="{{ route(…) }}"` without it, of which 233 are in classic Blade views (the other 4 are auth/email links, correctly plain). Inside `resources/views/livewire/**` every `<a>` carries `wire:navigate` ✅ — but 9 tables also carry a row-level hard navigation.

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| B1 | 🟠 | `livewire/appointments/index.blade.php:33`, `visits/index.blade.php:26`, `invoices/index.blade.php:21`, `stock/index.blade.php:29`, `lab-orders/index.blade.php:20`, `radiology-orders/index.blade.php:20`, `admissions/index.blade.php:25`, `insurance-claims/index.blade.php:22`, `financial-years/index.blade.php:13` | `<tr onclick="window.location='…show…'">` — the primary click on nine list pages is a **full page load**, while the eye icon in the same row is `wire:navigate`. Rows are also not keyboard-reachable (no `tabindex`/`role`). | Remove `onclick`; make the first cell a `<x-ui.link>`; optionally `x-on:click="$el.querySelector('a[wire\\:navigate]').click()"` on the row with `tabindex="0"` + Enter handler. Add an HTML regression test asserting no `window.location` in `resources/views/livewire`. |
| B2 | 🟠 | every `resources/views/admin/**` page (233 links) — worst: `patients/show` (7), `visits/show` (4), `onboarding/index` (4), `invoices/show` (3), `board` (3), `users/edit` (3) | Breadcrumbs, action buttons, related-record links, "Cancel" buttons: all full reloads. Dashboard → module is SPA (`x-dash.*` carry `wire:navigate`) and then dead-ends on the next click. | Introduce `<x-ui.link>` and `<x-ui.breadcrumb>` (always `wire:navigate`); convert every link in one sweep; exclude PDF/receipt/download/`target=_blank` routes explicitly (`admin.invoices.pdf`, `payments.receipt`, `patients.id-card`, `patients.documents.download`, `lab-orders.pdf`, `radiology-orders.pdf`, `admissions.summary`, `patients.treatments.photo`). |
| B3 | 🟠 | `admin/stock/alerts.blade.php:12,27` and the 10 dead index views | Same `onclick="window.location"` row hack in classic views. | Same as B1 (alerts page becomes a polled board). |
| B4 | 🟡 | `layouts/admin.blade.php:200` | `location.reload()` after profile save. | A7. |
| B5 | 🟡 | `resources/views/gateway/result.blade.php:11` | Standalone page links back to dashboard without `wire:navigate` (fine — it is outside the shell), but it is a full HTML doc with inline styles and no layout. | Render within `layouts.admin` when authenticated; keep plain otherwise. |
| B6 | 🟡 | — | No prefetch anywhere (`wire:navigate.hover` = 0). | A15. |
| B7 | 🟡 | — | No `Cache-Control: no-store` on authenticated HTML → after sign-out, Back can show a cached authenticated page (Livewire navigate cache / bfcache) before the server 302 catches up. | Middleware on the `admin`/`super` groups setting `no-store`. |
| B8 | 🔵 | `resources/views/auth/forgot-password.blade.php:28`, `reset-password.blade.php:47` | `url('/admin/login')` hardcoded instead of `route('admin.login')`. | Use `route()`. |

## C. Livewire components (`app/Livewire/**`, `resources/views/livewire/**`)

### C.1 Inventory

| Component | Route | Modal | Paginated | Search | Filters | Tests | Notes |
|---|---|---|---|---|---|---|---|
| `Patients\Index` | `admin.patients.index` | ✅ | ✅ | ✅ | `statusFilter` | `PatientsIndexTest` (9) | only component with real sorting |
| `Patients\Form` | `admin.patients.create/edit` | — full page | — | — | — | `PatientFormTest` (5) | no `authorize()` in `render()`; `$patient` not `#[Locked]` |
| `Users\Index` | `admin.users.index` | ✅ (+avatar upload) | ✅ | ✅ | — | `UserModalTest` (6) | own scoping logic duplicated from `UserController` |
| `Departments\Index` | `admin.departments.index` | ✅ + samples | ✅ | ✅ | — | 3 files | |
| `Rooms\Index` | `admin.rooms.index` | ✅ | ✅ | ✅ | — | **none** | |
| `StaffProfiles\Index` | `admin.staff.index` | ✅ | ✅ | ✅ | — | `OrgModalTest` | |
| `Schedules\Index` | `admin.schedules.index` | ✅ | ✅ | ✅ | — | `OrgModalTest` | |
| `Appointments\Index` | `admin.appointments.index` | ✅ (`@if`) | ✅ | ✅ | date/doctor/status | `AppointmentsIndexTest` (5) | row `onclick` (B1) |
| `Visits\Index` | `admin.visits.index` | ✅ (`@if`) | ✅ | ✅ | status | `VisitsIndexTest` (4) | row `onclick` |
| `Services\Index` | `admin.services.index` | ✅ + samples | ✅ | ✅ | — | 3 files | |
| `Invoices\Index` | `admin.invoices.index` | — | ✅ | ✅ | status | `BillingInsuranceIndexTest` | row `onclick` |
| `Stock\Index` | `admin.stock.index` | ✅ | ✅ | ✅ | `filter` | 2 files | row `onclick` |
| `StockCategories\Index` | `admin.stock-categories.index` | ✅ + samples | ✅ | ✅ | — | `CatalogIndexTest` | "No categorys found." |
| `LabTests\Index` | `admin.lab-tests.index` | ✅ + samples | ✅ | ✅ | — | `CatalogIndexTest` | |
| `LabOrders\Index` | `admin.lab-orders.index` | — | ✅ | ✅ | status | `PharmacyDiagnosticsIndexTest` | N+1 `items()->count()`; row `onclick` |
| `RadiologyStudies\Index` | `admin.radiology-studies.index` | ✅ + samples | ✅ | ✅ | — | `CatalogIndexTest` | "No studys found." |
| `RadiologyOrders\Index` | `admin.radiology-orders.index` | — | ✅ | ✅ | status | `PharmacyDiagnosticsIndexTest` | N+1; row `onclick` |
| `Wards\Index` | `admin.wards.index` | ✅ + samples | ✅ | ✅ | — | 2 files | |
| `Beds\Index` | `admin.beds.index` | ✅ | ✅ | ✅ | — | 2 files | |
| `Admissions\Index` | `admin.admissions.index` | ✅ (`@if`) | ✅ | ✅ | status | 2 files | row `onclick` |
| `InsuranceProviders\Index` | `admin.insurance-providers.index` | ✅ | ✅ | ✅ | — | `BillingInsuranceIndexTest` | |
| `InsuranceClaims\Index` | `admin.insurance-claims.index` | ✅ (`@if`) | ✅ | ✅ | status | 2 files | row `onclick` |
| `FinancialYears\Index` | `admin.financial-years.index` | ✅ | ❌ `get()` all | ❌ | — | 2 files | no `WithTable`; Reopen lacks `wire:confirm` |
| `Super\Hospitals\Index` | `super.hospitals.index` | ✅ | ✅ | ✅ | — | `SuperModalTest` | tenant-scoped when super-admin "switched in" |
| `Super\Plans\Index` | `super.plans.index` | ✅ | ✅ | ✅ | — | `SuperModalTest` | writes `max_users` (plan-limit reads `max_staff`) |
| `Super\Subscriptions\Index` | `super.subscriptions.index` | ✅ (`@if`) | ✅ | ✅ | — | `SuperModalTest` | must confirm "Record payment" exists here (only lived in orphaned `super/subscriptions/edit.blade.php:94`) |

Traits: `Concerns\WithTable` (26 users), `Concerns\ImportsSamples` (6), `Concerns\InteractsWithPatientForm` (2). Not used anywhere: `Livewire\Form`, `#[Validate]`, `#[Computed]`, `#[Lazy]`, `#[Locked]`, `#[On]`, `@script`, `@assets`, `@persist`, `wire:poll`, `wire:offline`, `wire:transition`, `wire:model.blur`, `wire:navigate.hover`.

### C.2 Findings

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| C1 | 🔴 | `app/Providers/AppServiceProvider.php:117-119` | `Livewire::setUpdateRoute(fn ($handle) => Route::post($base.'/livewire/update', $handle))` — **no `->middleware('web')`** (Livewire's default adds it, `HandleRequests.php:21-25`; `setUpdateRoute` adds none, `:81-88`). `route:list` confirms: `POST true-doctor/livewire/update` has no middleware. Today Laravel computes `baseUrl=/true-doctor`, `path=livewire/update`, so the *default* route matches and the app works by accident; on any host where the base is not stripped, every Livewire request runs with no session, no CSRF, no `ResolveHospital` (→ `HospitalScope` skips the tenant filter). | Add `->middleware('web')`; add a test asserting both update routes carry `web`. Remove the override entirely once deployed at a domain root. |
| C2 | 🔴 | `bootstrap/app.php`, `AppServiceProvider` | No `Livewire::addPersistentMiddleware()`; `admin`, `super`, `permission:manage-users`, `subscribed`, `onboarding` never run on Livewire update requests. Coverage today exists only because every component re-checks by hand (`render()` authorize, `abort_unless(isSuperAdmin)`). | 4.3. |
| C3 | 🟠 | `app/Livewire/Concerns/WithTable.php:26-27` | `#[Url] public int $perPage = 20` bound to `paginate()` in 26 components with no ceiling and **no UI control anywhere** → `?perPage=1000000`. `search` has no max length (1 MB LIKE + 1 MB URL). | Whitelist `perPage` in `updatingPerPage()` (`[10,20,50,100]`) or `#[Locked]`; validate `search` `max:100`; add the missing per-page selector to the toolbar. |
| C4 | 🟠 | `app/Livewire/Appointments/Index.php:154`, `Visits/Index.php:123`, `Admissions/Index.php:122`, `InsuranceClaims/Index.php:120,122`, `Patients/Index.php:106`, `Patients/Form.php:65`, `Beds:128`, `Rooms:131`, `Schedules:138`, `Stock:154`, `Super/Subscriptions:116-117` | Option lists (**every patient**, every open invoice, every bed, every district…) queried in `render()` on **every request including each search keystroke**; only 5 views skip *rendering* them when the modal is closed, none skip the query. | `#[Computed]` properties touched only inside `@if($showForm)`; `<x-ui.select-search>` (async typeahead via a small Livewire child) for patients/invoices/stock. |
| C5 | 🟠 | `resources/views/livewire/lab-orders/index.blade.php:21`, `radiology-orders/index.blade.php:21` | `{{ $o->items()->count() }}` per row → N+1 (20 extra queries per page). | `->withCount('items')`, render `items_count`. |
| C6 | 🟠 | `app/Livewire/Appointments/Index.php:127-128`, `Visits/Index.php:97-98`, `Admissions/Index.php:98-99`, `InsuranceClaims/Index.php:95-96` | `dispatch('toast')` immediately followed by `redirect(…, navigate: true)` → body swap destroys the toast; user sees nothing. `Patients/Form.php:55-59` does it right with `session()->flash()`. | Flash (or `@persist('toasts')`, A21). |
| C7 | 🟠 | `app/Livewire/Concerns/ImportsSamples.php:18,53-58` + `Services/Index.php:130-136`, `LabTests:138-150`, `RadiologyStudies:130-141`, `StockCategories:126-132`, `Departments:133-139` | `public array $samples` is client-editable and `importSamples()` persists it directly (`price`, `tax_exempt`, `unit`) with no validation beyond `max(0,(float))`. `toggleAllSamples()` is a public unauthorised action. | Validate `$samples.*` with a rule set (or intersect names against `sampleSource()`), authorize `toggleAllSamples()`. |
| C8 | 🟠 | `app/Livewire/Patients/Form.php:27,62-70` | `public ?Patient $patient` is neither `#[Locked]` nor re-authorized in `render()`. | `#[Locked]` + `$this->authorize()` in `render()`. |
| C9 | 🟡 | `Rooms/Index.php:80-118`, `Wards:70-109`, `LabTests:86-125`, `RadiologyStudies:78-117`, `Services:78-117`, `StockCategories:74-113`, `InsuranceProviders:80-118`, `Beds:76-114`, `StaffProfiles:89-128`, `Schedules:88-123`, `Departments:85-120`, `Stock:108-132`, `Super/*` | `save()` bodies ~90 % identical; `findOrFail($editingId)` executed twice per save (authorize + update); `edit()` hand-copies properties in 13 components; blank-to-null loops that would corrupt NOT NULL enum columns if a rule relaxed (`Rooms:87-91`, `Beds:83-87`). | `CrudModal` trait + `Livewire\Form` objects (4.5). |
| C10 | 🟡 | `Departments:68`, `Rooms:66`, `StaffProfiles:71`, `Schedules:73`, `Appointments:93`, `Visits:64`, `Services:65`, `Stock:90`, `StockCategories:62`, `LabTests:71`, `RadiologyStudies:65`, `Wards:59`, `Beds:63`, `InsuranceProviders:66`, `InsuranceClaims:64`, `FinancialYears:44`, `Users:108`, `Super/*` | `rules()` duplicated verbatim from the matching FormRequest (e.g. `Departments/Index.php:68-81` ≡ `DepartmentRequest.php:21-36`), violating roadmap decision #6. Only `InteractsWithPatientForm.php:163` reuses `PatientRequest::rules()`. | `rulesFor()` statics on FormRequests (4.5). |
| C11 | 🟡 | `LabOrders/Index.php:27,37`, `RadiologyOrders:27,37`, `Users:61,76,219`, `Super/*` (15 sites) | Three authorization idioms (`authorize()` via policy; `abort_unless(can('x'))`; `abort_unless(isSuperAdmin())`); 6 components lack `AuthorizesRequests` so their views cannot `@can` and hard-code buttons with no per-row gate. | Policies for `User`, `Hospital`, `Plan`, `Subscription`, `LabOrder`, `RadiologyOrder`; `AuthorizesRequests` everywhere. |
| C12 | 🟡 | `WithTable.php:20-24,39-67` | `sortField`/`sortDir` are `#[Url(history:true)]` on all 26 tables but only `Patients\Index` implements `sortableFields()`; 25 tables carry inert sort params in every URL/history entry. `resetPage()` hooks copy-pasted per filter (`updatingStatus`, `updatingDoctor`, …); forgetting one silently breaks pagination. | Either wire sorting into every table via `<x-ui.th-sort>` or drop the props; add a generic `updating($name)` that calls `resetPage()` for any property listed in `protected array $resetsPage`. |
| C13 | 🟡 | `Appointments:40`, `Visits:31`, `Invoices:24`, `LabOrders:23`, `RadiologyOrders:23`, `Admissions:32`, `InsuranceClaims:31` vs `Rooms:33`, `Beds:33`, `Super/Hospitals:37`, `Super/Subscriptions:32`, `InteractsWithPatientForm:30` | `$status` means a *filter* in 7 components and a *form field* in 5; `Patients\Index` works around it with `$statusFilter` + a comment. `Stock` calls its filter `$filter`. Row variables: `$rows` vs `$patients`/`$users`/`$items`/`$orders`/`$years`…; `wire:key` prefixes ad-hoc. | Naming convention: filters `$filterX`, form fields on the Form object, rows always `$rows`, keys `{{ $model->getTable() }}-{{ $id }}`. |
| C14 | 🟡 | `resources/views/livewire/patients/index.blade.php:64-77`, `users:57-126`, `departments:45-83`, `rooms:38-80`, `staff:39-84`, `schedules:40-87`, `services:43-76`, `stock:48-111`, `stock-categories:40-70`, `lab-tests:42-87`, `radiology-studies:41-76`, `wards:40-65`, `beds:36-71`, `insurance-providers:36-76`, `financial-years:29-58`, `super/hospitals:40-84`, `super/plans:38-85` | 17 modals render their full form + option lists in every response (only 5 are `@if($showForm)`-guarded). | Guard all; render form only when open. |
| C15 | 🟡 | `financial-years/index.blade.php:10`, `super/*:16`, `users:16`, `patients/form:12` (no `wire:target`); 7 tables without `.delay` (`patients:24`, `visits:21`, `invoices:16`, `stock:24`, `lab-orders:16`, `radiology-orders:16`, `admissions:22`, `insurance-claims:19`) | Loading dim fires on every request (incl. modal saves) or flickers on fast responses; `users/index.blade.php:120-122` button targets `save,avatar` but spans target `save` only. | Standard: `wire:loading.class.delay="tb-loading" wire:target="search,filterStatus,…,gotoPage,previousPage,nextPage"` from the table component. |
| C16 | 🟡 | `Appointments:120`, `Visits:90`, `Admissions:91`, `InsuranceClaims:88`, `FinancialYears:63,81` | `catch (\Throwable $e)` → toast with `$e->getMessage()`; a `QueryException` is shown verbatim and never logged. `FinancialYears::reopen()` has no try/catch while `close()` does. | Catch domain exceptions only; `report()` the rest; generic toast. |
| C17 | 🟡 | `Departments:153`, `StaffProfiles:142`, `Schedules:137`, `Appointments:152`, `Visits:124`, `Admissions:124` vs `Users:71` | `User::where('hospital_id', app(CurrentHospital::class)->id())` hand-rolled 6× (`User::currentHospital()` scope exists); `Users\Index` uses `$actor->hospital_id` instead and short-circuits super-admins to `User::query()` even when "switched into" a hospital. | `User::currentHospital()` everywhere; `User::scopeManageableBy($actor)` shared with `UserController`. |
| C18 | 🟡 | `Super/Subscriptions/Index.php:109`, `Super/Hospitals/Index.php:111` | `Subscription` is tenant-scoped; when a super-admin has `viewing_hospital_id` in session the platform lists silently shrink to one hospital. | `withoutGlobalScope(HospitalScope::class)` in `Super\*` or clear the session key on entering `/super`. |
| C19 | 🟡 | `app/Livewire/FinancialYears/Index.php:99` | `FinancialYear::orderByDesc('starts_on')->get()` — no pagination/search; only index not on `WithTable`. | Adopt `WithTable`. |
| C20 | 🟡 | `resources/views/livewire/users/index.blade.php:89-93` | `wire:model="avatar"` (deferred) → the temporary-URL preview block and "Uploading…" indicator are unreachable until submit; no upload progress handler; old avatar deleted before `update()` succeeds (`Users/Index.php:176-183`). | `wire:model.live="avatar"`, `x-on:livewire-upload-progress`, delete old file after successful update. |
| C21 | 🟡 | `resources/views/components/ui/slideover.blade.php:10-27` | Dialog has `role="dialog" aria-modal` but **no focus trap** (`x-trap` is bundled with Livewire), no initial focus, no focus restore, `aria-label` instead of `aria-labelledby`; ESC/backdrop deliberately disabled with no "discard changes?" alternative. | `x-trap.inert.noscroll="open"`, autofocus first field, restore focus to trigger, ESC → dirty-guard confirm (`$wire.$isDirty` via a `wire:dirty` flag). |
| C22 | 🟡 | `resources/views/components/ui/sample-import.blade.php:39,45` | `wire:model.live` per checkbox → one round-trip per tick (40 ticks = 40 requests); `wire:model` (deferred) on the editable cells of the same row; "Select all" re-renders the whole table. | Client-side Alpine mirror for selection; `.live` only on bulk buttons. |
| C23 | 🟡 | `financial-years/index.blade.php:22` | **Reopen** a closed accounting period has no `wire:confirm` (Close does). Confirm wording says "Delete" on `wards/beds/stock-categories` which are soft-deleted ("Archive" elsewhere). | Add confirm; unify wording via the confirm component. |
| C24 | 🟡 | `wards:27`, `radiology-studies:28`, `departments:31`, `lab-tests:29`, `schedules:27`, `insurance-providers:23`, `staff:26`, `services:30`, `stock-categories:27` | `badge-success` used in 9 views, **not defined** in `td-admin.css` (defined: `badge-active|info|warn|danger|neutral`). `tb-loading-wrap` (`patients/form:12`) undefined. | `<x-ui.badge :tone>` driven by enum `badge()`; delete undefined classes. |
| C25 | 🔵 | `stock-categories/index.blade.php:34`, `radiology-studies/index.blade.php:35` | "No **categorys** found." / "No **studys** found." Filter-aware empty state exists only on patients. | `<x-ui.empty :filtered>` with proper nouns + "Clear filters". |
| C26 | 🔵 | `patients/index.blade.php:7,29` | `type="text"` on the one search box (others `type="search"`); `@php($th = fn($f,$l) => $f)` dead. | Cleanup. |
| C27 | 🔵 | `Super/Hospitals/Index.php:111` | `with(['subscriptions' => fn ($q) => $q->latest()->limit(1)])` + `@php($sub = $h->subscriptions->first())` | `latestSubscription()` via `latestOfMany()`. |
| C28 | 🔵 | `Stock/Index.php:125` | UUID generated in the component (`Str::uuid()`); elsewhere in services; nowhere in a model concern. | `HasUuid` model concern. |
| C29 | 🔵 | all 26 index views | Stray blank/whitespace lines 3, 10, 13 from generation; `@endcan` misindented in 6 sample-import views. | Pint-for-Blade (`tighten/duster` or `blade-formatter`). |

## D. Pages still rendered by controllers (classic Blade)

### D.1 Live classic pages — the conversion backlog

| Route | View | POST forms | Links w/o navigate | Inline styles | Target shape |
|---|---|---|---|---|---|
| `admin.dashboard` | `admin/dashboard/index` + `roles/*` | 0 | 0 | 9 | Board — each `x-dash.section` → `#[Lazy]` + `wire:poll.60s.visible` child |
| `admin.onboarding` | `admin/onboarding/index` | 0 | 4 | 10 | List (static) — links only |
| `admin.patients.show` | `admin/patients/show` (326 L) | **12** | 7 | **93** | Detail workspace: `Patients\Show` + lazy children `Cards`, `Documents`, `Dependents`, `Insurances`, `Treatments` |
| `admin.patients.treatments.show` | `admin/treatments/show` | 1 | 2 | 5 | Detail |
| `admin.appointments.queue` | `admin/appointments/queue` | 1 × N × M | 2 | 4 | Board (`wire:poll.15s.visible`), transitions `wire:click` |
| `admin.appointments.create/edit` | `appointments/create|edit` + `_form` | 1 | 2 | 11 | **Delete** — modal exists; `edit` → reschedule action on `Appointments\Show` |
| `admin.appointments.show` | `admin/appointments/show` | 1 × N | 2 | 13 | Detail |
| `admin.visits.create` | `admin/visits/create` | 2 (open + intake) | 2 | 19 | **Delete** create; intake becomes a second tab in the open-visit modal |
| `admin.visits.show` | `admin/visits/show` (306 L) | **12** | 4 | **92** | Detail workspace: `Visits\Show` + children `Vitals`, `Clinical`, `Charges`, `LabOrders`, `RadiologyOrders`, `Dispense`, `Prescriptions` |
| `admin.invoices.show` | `admin/invoices/show` | 2 | 3 | 35 | Detail: `Invoices\Show` + `TakePayment` slide-over (pessimistic); gateway button stays a real POST |
| `admin.lab-orders.show` | `admin/lab-orders/show` | 2 × N | 2 | 12 | Detail: inline result entry `wire:model.blur` per item |
| `admin.radiology-orders.show` | `admin/radiology-orders/show` | 2 | 2 | 14 | Detail |
| `admin.dispensations.show` | `admin/dispensations/show` | 0 | 1 | 6 | Detail (read-only) |
| `admin.stock.show` | `admin/stock/show` | 3 | 2 | 25 | Detail: receive/adjust slide-overs |
| `admin.stock.create/edit` | `stock/create|edit` + `_form` | 1 | 2 | 12 | **Delete** — modal exists (`Stock\Index`) |
| `admin.stock.alerts` | `admin/stock/alerts` | 0 | 1 | 12 | Board (`wire:poll.60s.visible`) |
| `admin.admissions.board` | `admin/admissions/board` | 0 | 3 | 8 | Board (`wire:poll.30s.visible`) |
| `admin.admissions.create` | `admin/admissions/create` | 1 | 2 | 7 | **Delete** — modal exists |
| `admin.admissions.show` | `admin/admissions/show` | **5** | 2 | 27 | Detail: `Transfer`, `Discharge` (confirm, pessimistic), `VitalRounds`, `Medications`, `NursingNotes` children |
| `admin.insurance-claims.create` | `insurance-claims/create` | 1 | 2 | 9 | **Delete** — modal exists |
| `admin.insurance-claims.show` | `insurance-claims/show` | 1 × N | 2 | 8 | Detail |
| `admin.financial-years.create` | `financial-years/create` | 1 | 2 | 7 | **Delete** — modal exists |
| `admin.financial-years.show` | `financial-years/show` | 0 | 1 | 12 | Detail (report) — reuse `x-ui.stat` |
| `admin.notifications.index` | `admin/notifications/index` | 2 | 1 | 7 | List (`wire:poll.60s.visible`), mark-read `wire:click` (optimistic OK) |
| `admin.reports.index` | `admin/reports/index` | 0 (1 GET) | 1 | 25 | Board with `#[Url]` date range, `#[Computed]` cached sections |
| `admin.subscription.index` | `admin/subscription/index` | 1 × N plans | 1 | 15 | Keep checkout POST (gateway redirect); page itself Livewire |
| `admin.settings.index` | `admin/settings/index` | 1 | 1 | 1 | Editor (full page) |
| `admin.settings.billing` | `admin/settings/billing` | 1 (PUT) | 1 | 14 | Editor with live currency-format preview |
| `admin.users.create/edit/show` | `admin/users/*` | 2 | 7 | 50 | **Delete** create/edit (modal exists; port avatar drag-drop); `show` → Detail or drop |
| `super.*.create/edit` | `super/*/create|edit` | 6 | — | — | **Delete** — modals exist (verify "Record payment", C.1) |

Totals for classic views: **123 `<form>` / 113 `@csrf`**, **255 route links (22 with navigate)**, **907 inline `style=`**, **127 hardcoded hex colours**, **21 native `confirm()`**, **12 `onclick="window.location"`**, **137 `redirect()->with(...)`** in controllers.

### D.2 Findings

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| D1 | 🟠 | `admin/visits/show.blade.php:18-273` (12 forms), `admin/patients/show.blade.php:16-315` (12 forms) | The two highest-traffic clinical pages are pure classic forms: every vitals save, note save, charge, order, dispense, prescription, dose administration, card credit/debit, document upload reloads the page and loses scroll/position. | Workspace conversion per D.1. |
| D2 | 🟠 | `admin/visits/show.blade.php:204-211,232-246` | Dispense and prescription forms hardcode `items[0][…]` — array notation implies multi-row, UI submits one line. | Livewire repeater rows in the `Dispense`/`Prescriptions` children. |
| D3 | 🟠 | `admin/visits/show.blade.php:209`, `visits/create:12`, `admissions/create:9`, `insurance-claims/create:7`, `VisitController::show:105-112` | `<select>` over **every stock item** / **every patient** rendered into HTML (multi-MB on real data); `VisitController::show` loads 9 relations + 4 full catalogues + a billing roll-up in one method. | `<x-ui.select-search>`; lazy children load their own data. |
| D4 | 🟠 | `admin/admissions/show.blade.php:47` | Discharge (irreversible, bills the stay) confirmed via `onclick="return confirm()"` on the **button**, not the form → does not reliably cancel submission. | `wire:confirm` on the Livewire action. |
| D5 | 🟡 | `admin/patients/show.blade.php:86-120` | `@canany(['manageCards','view'])` shows card balances/credit limits to any `patients.view` role; forms gated by `manageCards`. | Policy review; gate the whole panel on `manageCards`. |
| D6 | 🟡 | `admin/patients/show.blade.php:91,214-218` | Reveal-by-DOM-mutation `onclick="…style.display='block'"`; dependents form asks the user to **paste a raw UUID**. | Alpine/Livewire state; patient typeahead. |
| D7 | 🟡 | `admin/reports/index.blade.php:5` | Date range is a GET form (full reload per filter change); no `action`, so it re-posts the current query string. | `#[Url] $from/$to` on a `Reports\Index` component. |
| D8 | 🟡 | `admin/users/edit.blade.php:232-317` | The only `@push('scripts')` user: IIFE with `getElementById(...).addEventListener` that throws on any page lacking those ids once the layout becomes an SPA; inline `onmouseover`/`onclick="togglePwd()"` handlers; hardcoded colour array in JS. | Retire the page; port drag-drop avatar into the Users modal with `@script`. |
| D9 | 🟡 | `routes/web.php:17-19,30,33,49-53` | 7 route closures (pricing query in a closure, login view, onboarding skip) → `route:cache` impossible; `onboarding/skip` mutates session over **GET** (CSRF-bypassable via `<img>`). | Controllers; `skip` → POST (or a Livewire action). |
| D10 | 🟡 | `resources/views/admin/dashboard/index.blade.php:8` | `@include('admin.dashboard.roles.'.$role)` dynamic include; safe because `$role` comes from a constant map, but one refactor away from view-path injection. Dashboard = ~20 aggregate queries per load, no cache, no polling; `fallback.blade.php:25-26` calls `lowStockCount()` twice. | `Dashboard\Index` + lazy polled sections; `DashboardService` results cached 30–60 s per tenant/role. |
| D11 | 🔵 | `resources/views/gateway/result.blade.php`, `auth/login.blade.php:72-95` | Standalone HTML doc; `<style>` and `<script>` emitted inside the auth card `<div>`. | Move to `@push('styles')`/head. |

## E. Forms, flash messages & feedback

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| E1 | 🟠 | 113 `@csrf` forms (Part II D.1) classified: **A** slide-over on an existing list (≈35), **B** `wire:click`+`wire:confirm` action (≈40), **C** new Livewire section component (≈25), **D** keep classic (logout ×2, gateway checkout/pay ×2, auth ×9) | Every submit is a full round-trip + reload; validation errors lose scroll and modal state. | Convert per class; D stays. |
| E2 | 🟠 | 137 `redirect()->with('success'|'error'|…)` across 37 controllers | Flash is baked into the *next* page render; under `wire:navigate` it appears only after navigation, and twice (A12). | Controllers that survive (store/update/destroy reached from Livewire actions) return JSON/redirect with `with('toast', …)`; the rest are deleted with their forms. |
| E3 | 🟠 | 29 views with `@if(session('error'))<div class="tb-alert tb-alert-danger">` (e.g. `patients/show:24`, `visits/show:10`, `invoices/show:12`, `stock/show:16`, `admissions/show:13`, `appointments/show:16`, `lab-orders/show:13`, `radiology-orders/show:13`, `insurance-claims/show:5`, `financial-years/index:5`, `subscription/index:5`) | A **fourth** flash renderer; `.tb-alert-danger` is **undefined** in `td-admin.css` (29 uses, 0 definitions) → unstyled. | Delete blocks; one toast pipeline (4.7). |
| E4 | 🟠 | 21 native `confirm()` (`onsubmit="return confirm"` ×20, `onclick` ×1) + 2 `alert()` | Unstyled, unbranded, blocking; `wire:confirm` already exists on 10 Livewire actions. | `wire:confirm` (or `<x-ui.confirm>` for rich dialogs). |
| E5 | 🟡 | `Patients/Index.php:80-85`, `Patients/Form.php:48-53` | `PlanLimitExceededException` attached to the `first_name` field (`addError('first_name', …)`) — misleading. | Toast + a non-field error slot in the slide-over. |
| E6 | 🟡 | 11 `_form` partials + 4 pages | `@if($errors->any())` list rendered in the form **and** in the layout → double on every validation failure. | Delete with the partials. |
| E7 | 🟡 | — | No `wire:dirty` guard anywhere: navigating away from a half-filled slide-over/full-page form loses input silently (the slide-over refuses ESC/backdrop instead). | `wire:dirty` flag + `livewire:navigating` beforeunload-style prompt in the shell bundle. |
| E8 | 🟡 | — | No `wire:offline` indicator: on a LAN drop `wire:model.live` requests fail silently. | Shell `wire:offline` banner. |

## F. Pagination

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| F1 | 🟠 | `AppServiceProvider.php:65-66` vs `vendor/livewire/.../SupportPagination.php:52-61,143-159`; no `config/livewire.php`; no `paginationView()` anywhere | Livewire's `WithPagination` **overrides** `Paginator::defaultView` with `livewire::tailwind` at component boot. Tailwind is not loaded in the admin layout. Result on all 26 index pages: unstyled markup, mobile and desktop blocks both visible (`sm:hidden`/`hidden` inert), `<button>` controls unstyled by the CSS patch (`td-admin.css:343-347` targets `a`/`span`), `scrollIntoView('body')` jump on each click. | 4.6: `WithTable::paginationView()` → `livewire.partials.pagination` (tb-themed, `wire:click`, single block, no jump). |
| F2 | 🟡 | `resources/views/vendor/pagination/simple-default.blade.php` | Uses `.pg-link`/`.pg-gap` — **undefined** in `td-admin.css` (exist only in dead `admin.css`/`tokens.css`); the one live classic paginated page (`notifications/index:23`) is unstyled; the view emits plain `href`s (full reload if ever used in a Livewire view). | Retire after notifications conversion; keep only the Livewire view. |
| F3 | 🔵 | 21 views | `<div style="margin-top:16px;">{{ $x->links() }}</div>` wrapper repeated. | Wrapper inside the pagination view. |

## G. Design system, CSS & markup consistency

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| G1 | 🟡 | `public/css/td-admin.css` (440 L, 32 KB) | Append-only layering: `.tb-user-dropdown`, `.tb-nav-gh`, `.dash-root`, `.dash-queue-row` re-declared 6× each; `.tb-table`, `.tb-sidebar-brand`, `.tb-nav-sub` 5×; 12 unused selectors (`tb-badge`, `tb-drawer`, `tb-nav-rule`, `tb-nav-section`, `tb-sidebar-avatar/role/uname/user/user-info`, `tb-tabs`, `tb-timeline`, `tb-tl-item`). | Move to `resources/css/td-admin/` split by layer (`tokens.css`, `base.css`, `shell.css`, `components/*.css`, `pages/*.css`), built by Vite with autoprefixer + minify; one declaration per selector; allow-list dynamic `tb-toast-*`. |
| G2 | 🟡 | 907 (classic) + ~140 (Livewire) inline `style=""`; worst `patients/show` 93, `visits/show` 92, `users/edit` 38, `invoices/show` 35 | Layout expressed inline; impossible to theme, audit, or CSP. | Components + utility classes (`.tb-detail-cols`, `.tb-grid-2`, `.tb-mt-4`, `.tb-text-right`…); target < 50 inline styles app-wide. |
| G3 | 🟡 | 127 hardcoded hex colours; e.g. `#c0392b` (danger) in `settings/billing:12-39`, `visits/create:10,39,40`, `admissions/create:8,10`, `insurance-claims/create:6-12`, `stock/show:22-30`, `stock/alerts:14,30`; `#0e6b63` teal (foreign palette) in `onboarding:14,21`, `patients/show:128`, `login:86-92`; `patients/show:117` references `var(--danger)` which does not exist (`--bad` does); `dash/donut.blade.php:7` and `users/edit:303` hardcode palettes | Tokens exist (`--bad`, `--warn`, `--br`…) but are bypassed. | Tokens only; add `--chart-1..7`; Stylelint rule `color-no-hex`. |
| G4 | 🟡 | 7 identical `@push('styles')<style>@media(max-width:900px){.pt-cols{grid-template-columns:1fr !important}}</style>` (`patients/show:325`, `visits/show:305`, `invoices/show:87`, `stock/show:85`, `admissions/show:119`, `appointments/show:69`, `reports/index:49`) + 6 copies of the inline detail-grid style | Page-level CSS duplicated per view. | `.tb-detail-cols` in the stylesheet. |
| G5 | 🟡 | ~60 views | `<div class="tb-page-header"><div><h1>…</h1><div class="tb-breadcrumb">…` hand-rolled; breadcrumbs as markup not data; sr-only `<h1>` convention followed by all Livewire views but only `dashboard/index` among classic views (and the topbar title depends on it). | `<x-ui.page-header :title :crumbs :actions>`; enforce one `<h1>` via test. |
| G6 | 🟡 | `x-ui.*` (2 components, Livewire-only), `x-dash.*` (7, dashboard-only), 13 dead Breeze `x-*`, raw `tb-*` everywhere else; `reports/index:13-16` and `financial-years/show:6-8` hand-roll what `x-dash.stat` already is; `$roleBadge` PHP block copied 3× (`users/index:48`, `users/show:28`, `livewire/users/index:32`) | Three parallel UI vocabularies. | One `x-ui.*` kit (4.4); `User::roleBadge()`; delete Breeze components. |
| G7 | 🟡 | `admin/stock/index.blade.php:29` | Two `style` attributes on one `<td>` (second silently dropped → low-stock red never renders). | Component. |
| G8 | 🔵 | `layouts/marketing.blade.php` (130 L inline `<style>`), `layouts/auth.blade.php` (48 L), 19 views with `<style>` | Un-cached, un-minified, blocks a future CSP. | Move into the Vite bundle(s): `marketing.css`, `auth.css`. |
| G9 | 🔵 | `td-admin.css:355` and 1 more `!important`; `layouts/marketing.blade.php:151` only reduced-motion guard | Admin transitions ignore `prefers-reduced-motion`. | Global `@media (prefers-reduced-motion: reduce)` block. |

## H. Assets, build pipeline & front-end dependencies

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| H1 | 🟠 | `vite.config.js`, `package.json`, `tailwind.config.js`, `postcss.config.js`, `resources/css/app.css`, `resources/js/app.js`, `.github/workflows/ci.yml` | Vite configured but **unused by any live layout**; `public/build` absent; `@vite` only in dead `layouts/guest.blade.php:15`; `package.json` declares Tailwind **3** and `@tailwindcss/vite` **4** simultaneously; `resources/js/app.js` starts a **second Alpine** (would double-boot against Livewire's bundle); CI never runs `npm ci && npm run build`; `vite ^7` needs Node ≥ 20.19 but README says 18 and there is no `.nvmrc`/`engines`. | Decide: **build**. `resources/css/td-admin.css` (split, G1) + `resources/js/admin.js` (shell) + `marketing.css` + `auth.css` via `@vite`; delete Tailwind/PostCSS/`app.js`/`bootstrap.js`/`courses.js`/`alpinejs`/`axios` deps; add `.nvmrc` (20), `engines`; CI builds; commit nothing under `public/build` (deploy builds). |
| H2 | 🟡 | `public/css/{admin,style,courses,tokens,admin-login,dashboard,programming_fundamentals,auth}.css` (72 KB, `auth.css` is 0 bytes), `public/js/{admin,main,dashboard,theme}.js` (76 KB, all ONYX/Academy/Cryptocoinex), `public/vendor/js/{jquery,apexcharts(528 KB),lightweight-charts,alpine,confetti,howler,dayjs,driver,sortable,sweetalert2}`, `public/vendor/{select2,flatpickr,dropzone,sweetalert2,css/driver.css,fonts}` | ~1.3 MB vendor + 148 KB css/js dead; `alpine.min.js` would crash Livewire if ever loaded. | Delete all; keep `vendor/fa` until subsetted. |
| H3 | 🟡 | `public/images/courses/*` (2.1 MB), `images/partners/*` (403 KB, `nita.svg` 175 KB), `images/{airtel-money,mtn-momo,visa}.png`, `logo.png` 433 KB, `logo-square.png` 198 KB, `logo-horizontal.png` 188 KB, `public/favicon.png` **198 KB**, `public/favicon.ico` **0 bytes** | ~2.5 MB dead images; a 198 KB favicon; an empty `.ico` served as 200. | Delete dead; regenerate favicons (ico 32×32, png ≤ 5 KB); resize logos; WebP for the marketing images. |
| H4 | 🟡 | `public/manifest.json` ("Cryptocoinex Trading Simulator"), `public/offline.html` (Cryptocoinex), `public/sw.js` (kill-switch, Cryptocoinex-labelled), `public/robots.txt:6` (advertises a non-existent `sitemap.xml`) | Previous product's brand publicly fetchable; permanent crawler 404. | Delete manifest/offline; keep a neutral `sw.js` kill-switch for one release then delete; add a sitemap or drop the line. |
| H5 | 🔵 | `resources/views/layouts/{app,guest,admin-guest}.blade.php`, `app/View/Components/{AppLayout,GuestLayout,AdminGuestLayout}.php`, 13 Breeze components, `resources/js/courses.js`, `laravel/breeze` dev dep | Dead; `layouts/app.blade.php:1` **extends itself** (infinite recursion if rendered) and contains "Total Students / Mary completed Programming"; `admin-guest-layout` loads Tailwind from CDN. | Delete. |

## I. Security & tenancy (backend)

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| I1 | 🔴 | `storage/backups/cryptocoinex_20260701.sql` (134 MB, tracked since `bfa2e72`) | Production dump of the previous product: 73 tables incl. `users` (62 bcrypt hashes), `password_reset_tokens`, `personal_access_tokens`, `kyc_submissions`, `clients`, `employees`, `chats`, `documents`, `deposit_requests`. `.gitignore` excludes only `/storage/*.key`, `/storage/pail`. `secrets-scan.sh` cannot detect it (pattern-based). Web-reachable under I2. | Move to encrypted cold storage; `git rm --cached`; `.gitignore` `/storage/backups`; purge history (`git filter-repo`), force-push, re-clone; rotate every credential in it; add "fail on tracked file > 5 MB or `*.sql|*.dump`" to `secrets-scan.sh`; treat as a disclosable incident. |
| I2 | 🔴 | `/.htaccess:13-19`, `/index.php`, `public/.htaccess:25` (`RewriteRule ^ ../index.php`) | Document root = project root; any real file is served verbatim: `.env`, `.env.production`, `ssh.txt`, `composer.lock`, `storage/logs/*.log`, `storage/framework/sessions/*`, every `*.md`, the dump above. `public/.htaccess` points **outside** the docroot, so a correct deploy (docroot=`public/`) needs a tracked-file edit. | Docroot → `public/`; delete root `index.php`/`.htaccess`; restore stock `public/.htaccess`; `APP_URL` without path (makes `fixLivewireSubdirectoryUrls()` a no-op); document in `docs/deployment.md`. Interim: `<FilesMatch "\.(env|sql|lock|json|md|neon|txt)$">Require all denied</FilesMatch>` at root. |
| I3 | 🔴 | `bootstrap/app.php:69` (alias) vs `routes/web.php:45`, `routes/api.php:28` | `EnsureSubscribed` is complete (grace period, `grantsAccess()`, `config/tenancy.php`) and **applied to zero routes**. | `middleware(['auth','admin','subscribed','onboarding'])` on the admin group; `subscribed` on the Sanctum group; persistent for Livewire (4.3); feature test: `past_due` → 403. Also fix I4. |
| I4 | 🟠 | `app/Http/Middleware/EnsureSubscribed.php:27-31` | Picks `->latest('starts_at')->first()` of **any** status (a later `cancelled` row blocks an `active` one; nullable `starts_at` sorts unpredictably). | Use `Hospital::activeSubscription()`. |
| I5 | 🔴 | `app/Models/User.php:127-130` (`STAFF_ROLES` includes `super_admin`), `UserController.php:56,123`, `Livewire/Users/Index.php:113,134,150-152`, `User::booted()` → `syncSpatieRole()`, `RbacSeeder.php:94` (`super_admin => '*'`), every policy `before()` (`hasRole('super_admin')`) | Any `manage-users` holder (every `hospital_admin`) can create or self-promote a `super_admin`; `is_admin` is set from the same string; policies then allow everything in-tenant. `/super/*` is still blocked only because `isSuperAdmin()` requires `hospital_id === null`. | Assignable roles = `STAFF_ROLES` minus `super_admin` unless `$actor->isSuperAdmin()`; policy `before()` uses `isSuperAdmin()`; one `StaffService` used by both surfaces; regression test. |
| I6 | 🔴 | `app/Providers/HorizonServiceProvider.php:30-34`, `config/horizon.php:44,86` | `viewHorizon` granted to anyone with `access-admin` (= every role); Horizon mounted at `admin/horizon` with `['web']` only → nurses can read queued notification payloads (PHI) across tenants. | Gate on `isSuperAdmin()`; `middleware => ['web','auth']`. |
| I7 | 🔴 | `config/telescope.php:19` | `enabled` defaults **true**; recording (request bodies, queries, PHI) runs wherever the package is installed even though the UI gate is closed. | Default `false`; `TELESCOPE_ENABLED` documented. |
| I8 | 🟠 | `.env:4` | `APP_DEBUG=true`, `APP_ENV=local` in the deploy tree; `bootstrap/app.php:143` leaks `$e->getMessage()` to API clients when debug is on; `ssh.txt` and `.env.production` (untracked, correctly ignored) still live in the tree and are web-reachable under I2. | Production `.env` with debug off; delete `ssh.txt`; secrets to a manager. |
| I9 | 🟠 | `app/Http/Controllers/Admin/ApiController.php:85` | Profile password endpoint: `'password' => 'required|string|min:4|confirmed'` — bypasses `Password::defaults()` (min 8, letters, numbers). | `Password::defaults()`; FormRequest. |
| I10 | 🟠 | `app/Models/DeviceToken.php`, `DeviceTokenController::store:20-23`, `routes/web.php:206` | No `hospital_id`, no authorization; `updateOrCreate(['token' => …], ['user_id' => …])` keyed on a **globally unique** token → submitting another user's token reassigns their device to the attacker, cross-tenant. No push sender exists anyway. | Delete the feature, or key on `(user_id, token)` + `hospital_id` + policy. |
| I11 | 🟠 | — (`grep` across `app/`, `.htaccess`) | No security headers: no CSP, HSTS, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`. | `SecurityHeaders` middleware on `web`; CSP with nonces once inline CSS/JS is gone (G8). |
| I12 | 🟠 | `Api/V1/AuthController.php:21-40`, `routes/api.php:25` | API login has no per-email throttle (only global 60/min per IP) and a user-enumeration timing oracle (no dummy hash on not-found). Sanctum tokens: no abilities, `expiration => null`, `token_prefix => ''`. | `RateLimiter` per `email|ip`; constant-time path; abilities + expiry + prefix. |
| I13 | 🟠 | `routes/web.php:257-258` | Gateway callback/webhook unthrottled; callback triggers outbound `verify()` HTTP on demand (amplification). Webhook returns 500 on `settle()` exceptions → provider retries a consumed log. | `throttle:30,1`; catch + log in `webhook()`. |
| I14 | 🟡 | `UserController.php:60,127`, `Livewire/Users/Index.php:117` | Avatar rule `'image|max:10240'` without `mimes:` (SVG on public disk = stored XSS); `AvatarService.php:39` falls back to `$file->store()` without re-validating MIME. | `mimes:jpg,jpeg,png,webp`; re-validate after processing. |
| I15 | 🟡 | `AppServiceProvider.php:77-84`, `bootstrap/app.php` | `URL::forceRootUrl()` + `forceScheme('https')` inferred from `APP_URL` string; no `trustProxies` → behind a proxy, `secure()` and per-IP throttles see the proxy IP. | `$middleware->trustProxies(...)`; drop `forceScheme`. |
| I16 | 🟡 | `TreatmentRecordController.php:53` | Private-disk photo served with `->response()` (inline) not `->download()`; no `nosniff`. | `attachment` + nosniff. |
| I17 | 🟡 | `app/Models/Visit.php:52`, `2026_06_13_091806_create_activity_log_table.php` | `LogsActivity` logs `diagnosis` (PHI) into `activity_log`, which has **no `hospital_id`** and no retention. | Add `hospital_id` + scope; retention command; or stop logging `diagnosis`. |
| I18 | 🟡 | `CardService.php:103` | `card_hash = hash_hmac('sha256', $number, config('app.key'))` — rotating `APP_KEY` orphans every card lookup; `APP_PREVIOUS_KEYS` unused. | Dedicated versioned pepper (`CARD_HASH_KEY`); document rotation. |
| I19 | 🟡 | `app/Http/Middleware/ResolveHospital.php:29-31`, `app/Models/Scopes/HospitalScope.php:19-23` | "No tenant" = "no filter" (documented and reasonable for console), but queued jobs/notifications have no tenant restoration except `GatewayPaymentService:91`; super-admin `viewing_hospital_id` leaks into `/super/*` (C18). | `TenantAware` job middleware restoring `CurrentHospital` from payload; clear `viewing_hospital_id` on `/super`; strict mode option for HTTP. |
| I20 | 🟡 | `config/filesystems.php` | No explicit `private` disk; `DocumentService::DISK='local'` relies on `local` → `storage/app/private` with `'serve' => true` registering a `/storage/{path}` route. | Explicit `private` disk with `serve => false`. |
| I21 | 🔵 | `config/session.php`, `.env` | `SESSION_DRIVER=file`, `encrypt=false`, `SESSION_SECURE_COOKIE` unset; file sessions live in `storage/framework/sessions` (web-reachable under I2, not horizontally scalable). | Redis sessions; `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true` in production. |

## J. Authorization

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| J1 | 🟠 | `routes/web.php` (only `:222-223` carry `permission:`) | Route-level permission coverage is essentially absent; `/super/*` uses only `super` although `manage-hospitals|plans|subscriptions` permissions are seeded (`RbacSeeder.php:32-34`) and gate nothing. | Route middleware per group + policies; Livewire persistent (4.3). |
| J2 | 🟠 | `routes/web.php:46,56,203-206,49-53` | No authorization on `DashboardController::index` (per-widget Blade gating only), `ThemeController`, `NotificationController`, `DeviceTokenController`, `onboarding/skip`. | Policies/abilities or delete (theme, device tokens). |
| J3 | 🟠 | `AppointmentPolicy` (no `transition`), `AdmissionPolicy` (no `transfer`/`discharge`), `VisitPolicy` (no order/dispense abilities); **no policies** for `Payment`, `Dispensation`, `LabOrder`, `RadiologyOrder`, `GatewayLog`, `PatientCard`, `PatientDocument`, `PatientInsurance`, `Subscription`, `Plan`, `Hospital`, `User`, `MedicalService` | A receptionist who may book can mark `Completed`; discharge (bills the stay) shares `manage`. | Add abilities + policies; Livewire components then `@can` per row. |
| J4 | 🟡 | `LabOrderController.php:29,43,52,61,70,84`, `RadiologyOrderController`, `DispensationController:25,44`, `MedicalServiceController:24,35`, `ReportController:19`, `SubscriptionCheckoutController:23,35`, `BillingSettingController:21,28`, `Livewire\RadiologyOrders\Index:27,37` | Raw `abort_unless($request->user()->can('string'))` idiom, inconsistent null-safety (`->can` vs `?->can`), typo-prone. | Policies everywhere. |
| J5 | 🟡 | `PatientPolicy.php:52-61`, `PatientDocumentController.php:38` | `manageDocuments`/`manageDependents` collapse to `patients.update`; document **download** authorizes `view` on the patient → pharmacists/lab techs can stream scanned IDs/consent forms. | `patients.documents.view|manage` permissions; authorize on the document. |
| J6 | 🟡 | `UserController::scoped():29-37` ≡ `Livewire\Users\Index::scopedQuery():65-72` | Security-critical scoping duplicated byte-for-byte. | `User::scopeManageableBy()`. |

## K. Data integrity & concurrency

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| K1 | 🟠 | `BillingService.php:241-256`, `VisitService.php:61-64`, `PatientService.php:54-57`, `InsuranceService.php:44` | Document numbers = `whereYear/whereDate(created_at)->lockForUpdate()->count() + 1`: non-sargable (full scan per insert), racy under READ COMMITTED (unique index + 3 retries = user-visible 500 on busy days), numbers reusable after force-delete/timezone drift. | `sequences(hospital_id, key, period, next)` row locked per allocation, or `INSERT … ON DUPLICATE KEY UPDATE next = LAST_INSERT_ID(next+1)`. |
| K2 | 🟠 | `AppointmentService.php:130-169` | Overlap check `->lockForUpdate()->exists()` on an empty range locks nothing under READ COMMITTED; if no schedule window exists, zero rows are locked → **double-booking reachable**. | DB guard (unique on `(hospital_id, doctor_user_id, scheduled_at)` for the common case) or `Cache::lock("appt:{h}:{doc}:{date}")` around booking. |
| K3 | 🟠 | `BillingService.php:125-142` | "One invoice per visit" `exists()` check runs **outside** the transaction; no unique index on `visit_id` → duplicate invoices reachable. | Move inside + unique partial index on non-void invoices. |
| K4 | 🟠 | `PlanController.php:48-51`, `Livewire/Super/Plans/Index.php:65-80` write `max_users`; `PlanLimit.php:22` reads `max_staff`; `PlanSeeder.php:18-19` seeds `max_staff` | Super-admin UI can never set the staff limit or `max_beds` → staff seats unlimited on every UI-edited plan. | Rename to `max_staff`, add `max_beds`, migration to rewrite existing JSON, test. |
| K5 | 🟠 | 11 catalogue tables with `softDeletes()` + plain composite `unique(['hospital_id','name'])` (`services`, `departments`, `rooms`, `wards`, `beds`, `lab_tests`, `radiology_studies`, `insurance_providers`, `stock_categories`, `stock_items`, `patient_cards`) | Archive "General consultation" → recreate → 1062, unrecoverable in the UI. | Include `deleted_at` (or a generated `active_key`) in the unique index. |
| K6 | 🟠 | most FKs `cascadeOnDelete()` incl. `payments`, `card_records`, `stock_movements`, `invoice_items`, `*_status_histories` | Dormant (parents soft-delete) until any `forceDelete()` — then the whole ledger cascades away. | `restrictOnDelete()` on ledger tables. |
| K7 | 🟠 | `Prescription`→`DoseItemRecord`, `Invoice`→`Payment`, `Admission`→`NursingNote/VitalRound/MedicationAdministration`, etc. | Parent soft-deletes, children hard → a discontinued prescription's doses still show as due in `DashboardService::pendingDoses():217` / `missedDoses():232` (clinical safety). | Cascade soft-delete or scope children through the parent. |
| K8 | 🟡 | `FinancialYearService.php:32-37,89-100`, `BillingService::recordPayment:184`, `CardService`, `StockService::post`, `InsuranceService`→Paid, `AdmissionService::discharge:136-141` | `assertPostingAllowed()` only guards `generateInvoice`; payments/stock/insurance/bed charges post into closed periods; FY overlap check is TOCTOU. | Guard every money write; unique/exclusion constraint on periods. |
| K9 | 🟡 | `AdmissionService.php:68-70`, `Bed.php:36-39`, `BedRequest` | Transfer locks both beds then re-reads the destination **unlocked**; `Bed.status` and `Admission.status` are two sources of truth (a bed can be set `available` under a live admission). | Use the locked rows; derive bed status or guard `BedRequest`. |
| K10 | 🟡 | `HospitalSettings.php:115,126,143`, `DispensationService.php:48`, `FlutterwaveGateway.php:74` | `(float)` round-trips before `bcmul`/`bccomp` on fee, tax rate, dispensed quantity, gateway amount. | Keep strings end-to-end; `bcscale`. |
| K11 | 🟡 | `SubscriptionCheckoutService.php:23,70-92` | `CURRENCY = 'USD'` hardcoded (gateway default UGX → every subscription charge `currency_mismatch`, silently); `activate()` overwrites the existing subscription row (history destroyed; payments point at a mutated row). | Config currency; new subscription row per period. |
| K12 | 🟡 | `GatewayPaymentService::initialize:31-38`, `gateway_logs` migration `:26` | `GatewayLog` created before the HTTP call, outside a transaction (timeouts leave `pending` forever); `provider_ref` unindexed/non-unique; no reconciliation command. | Unique `provider_ref`; `gateway:reconcile` command. |
| K13 | 🟡 | indexes | Missing: `invoices(hospital_id, created_at)`, `patients` FULLTEXT for `scopeSearch` (5 leading-wildcard LIKEs), `notifications(notifiable_type, notifiable_id, read_at)`, `payments(hospital_id, created_at)`, `medical_services(hospital_id, status)`, `users(hospital_id, role)`; `device_tokens.token unique varchar(512)` exceeds 767 bytes on older InnoDB. | Migration. |
| K14 | 🟡 | `2026_07_01_000001_add_true_doctor_columns_to_users_table.php:18` | `users.role` default `'data_clerk'` — not in `STAFF_ROLES`; such a user is silently locked out. | Default `null` + NOT NULL on create paths. |
| K15 | 🟡 | `User.php:137-145` | `saved` hook runs `syncSpatieRole()` (4 queries) on **every** user save incl. `last_active_at`; contradicts the project's own "no logic in boot hooks" rule. | Explicit call from `StaffService`; or `saved` only when `role` is dirty. |
| K16 | 🟡 | `Hospital.php:35-41`, `Plan.php:34-39` | Slug from name with no collision retry against a unique column; `Super\HospitalController::store` has no name-unique rule. | Suffix loop. |
| K17 | 🔵 | `AppointmentService::reschedule:86`, `Admission::nights()` vs `AdmissionService:117`, `Patient::fullName()`/`getFullNameAttribute()`, `Str::uuid()` in 9 services + 1 controller | No-op status-history rows; duplicated nights computation; duplicated accessor; scattered UUID generation. | Cleanup; `HasUuid`. |

## L. Performance

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| L1 | 🟠 | `VisitController::show:99-112`, `AdmissionController::formData:130-136`, `AppointmentController::formData:137-146`, `VisitController::formData:146-153`, `InsuranceClaimController:43-45`, `Super\*Controller` (`get()` across all tenants), `UserController::index:41` (all users, unpaginated) + C4 | ~40 unbounded `->get()` sites: whole patient/stock/service tables into `<select>`s. | `<x-ui.select-search>` + `#[Computed]`; paginate everything. |
| L2 | 🟠 | `ReportService.php:29-30,50-53,94`, `FinancialYearService::report:89,100`, `ReportController::index:24-33` (6 calls) | Loads every payment/invoice/visit in range into PHP for bcmath sums (OOM on a mid-size tenant). | `SUM()` on `DECIMAL(12,2)` in SQL (exact); cache per range 60 s. |
| L3 | 🟡 | `app/Support/Dashboard/DashboardService.php` (543 L, 40+ `count()`s, zero `Cache::`) | Dashboard = dozens of aggregate queries per load; `fallback.blade.php:25-26` calls `lowStockCount()` twice. | Per-tenant/role cache 30–60 s; lazy sections. |
| L4 | 🟡 | `UserController.php:91`, `Livewire/Users/Index.php:42` (`Mail::to()->send()` of a `Queueable` mailable), 7 synchronous DomPDF renders, `ResetPasswordNotification` sync | Blocking SMTP/PDF on request threads. | `->queue()`; PDF jobs for large docs. |
| L5 | 🟡 | `.env`, `.env.example` | `CACHE_DRIVER=redis` (Laravel 12 reads `CACHE_STORE`) → app silently uses the **database** cache incl. Spatie's 24 h permission cache; `FILESYSTEM_DRIVER`/`BROADCAST_DRIVER`/`MAIL_ENCRYPTION` also obsolete names. | Rename keys (M2). |
| L6 | 🔵 | `layouts/admin.blade.php:25-26` | A8. | A8. |

## M. Config, environment & deployment

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| M1 | 🔴 | root `index.php` (comment still says `/devrootsacademy/index.php`), root `.htaccess`, `public/.htaccess:25`, `APP_URL` path, `AppServiceProvider::fixLivewireSubdirectoryUrls()` | The subdirectory hack (I2) plus `config([...])` writes at boot that break under `config:cache`; `forceRootUrl` pins every generated URL (queues, mail, webhooks) to `APP_URL`. | Domain-root deploy (I2); keep `fixLivewireSubdirectoryUrls()` as a no-op guard; `docs/deployment.md`. |
| M2 | 🟠 | `.env.example` | Obsolete: `CACHE_DRIVER`, `FILESYSTEM_DRIVER`, `BROADCAST_DRIVER`, `MAIL_ENCRYPTION=ssl` (with port 587), `AT_*` (unread), `APP_FOLDER` (unread). Missing: `CACHE_STORE`, `FILESYSTEM_DISK`, `BROADCAST_CONNECTION`, `MAIL_SCHEME`, `ONBOARDING_GATE`, `TAWK_ENABLED/ID`, `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, `SANCTUM_STATEFUL_DOMAINS`, `HORIZON_PATH`, `TELESCOPE_ENABLED`, `REDIS_*`, `LOG_STACK`, `LOG_DAILY_DAYS`, `APP_LOCALE`. `.env.production` carries both old and new names. | Rewrite `.env.example` grouped and commented; `config:show` check in CI. |
| M3 | 🟠 | `routes/web.php:17-19,30,33,49-53` (7 closures) | `route:cache` fails. | D9. |
| M4 | 🟡 | `config/trading.php` | Config for a retired product, never read. | Delete. |
| M5 | 🟡 | `config/horizon.php` (no failed-job notifications, `HorizonServiceProvider:18-20` commented), `bootstrap/app.php:20` (`/up` only) | No `/health` probing DB/cache/queue; no alerting. | Health endpoint + Horizon notifications. |
| M6 | 🟡 | `AppServiceProvider.php:35-38` | `LogChannel` bound unconditionally → **every SMS in production is written to the log**, never sent; `AfricasTalkingChannel` never bound; `SmsChannel` never registered. | Env-driven binding; or remove SMS until wired. |
| M7 | 🔵 | `scripts/smoke.sh` (checks `/trade/*` on `localhost:8888/cryptocoinex`), `scripts/com.cryptocoinex.queue.plist` | Dead, machine-specific. | Delete; rewrite smoke for HMS routes as a post-deploy job. |
| M8 | 🔵 | `laravel/sail` dev dep without `compose.yaml`; `.env.example` hardcodes a MAMP socket path; no `Makefile`; no `.nvmrc` | Onboarding requires MAMP. | `compose.yaml` (php-fpm, mysql 8, redis, mailpit) or drop Sail; `Makefile`/`justfile`. |

## N. Dead code, legacy residue & repo hygiene

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| N1 | 🟡 | 28 orphaned index views (`admin/{patients,departments,rooms,staff,schedules,appointments,visits,services,invoices,stock,stock-categories,lab-tests,lab-orders,radiology-studies,radiology-orders,wards,beds,admissions,insurance-providers,insurance-claims,financial-years,users}/index.blade.php`, `super/{hospitals,plans,subscriptions}/index.blade.php`) + `admin/patients/{create,edit,_form}` + 11 `_form` partials + 28 `create/edit` views for nouns whose Livewire modal is the only UI entry | ~1,100 lines of dead Blade; the **routes still exist** (`Route::resource(...)->except(['show','index'])`) so `create`/`edit` pages are reachable by URL and `store/update/destroy` exist in **two implementations** with independently written validation. `super/subscriptions/edit.blade.php:94` held the only "Record payment" UI — verify it exists in the Livewire modal before deleting. | Delete views + ~34 controller methods; narrow resources to what Livewire actions do not cover; one implementation per write. |
| N2 | 🟡 | `_legacy/` (1.5 MB, 219 files, three retired products), `database/migrations/archive/` (65 files) — each needs exclusion rules in `phpstan.neon`, `pint.json`, `check-empty-files.php`, tailwind content | Git history (`610839e`) already preserves them. | Delete; record SHA in `docs/decisions.md`; drop the four exclusion rules. |
| N3 | 🟡 | `app/Support/Money.php` ("Live Account", `int` USD), `app/Support/VerificationCode.php` (only `checkChar()` used), `config/trading.php`, `ThemeController` + route + `users.theme`, `DeviceTokenController` + model + table (no sender), `AfricasTalkingChannel`/`SmsChannel` (unbound), `AuthenticatedSessionController::create` (unreachable), `UserController::index` (unreachable), `admin/partials/status-badge`, `partials/tawk`, `emails/live/notice`, empty `resources/views/public/` | Dead. | Delete (with migrations where columns/tables go). |
| N4 | 🟡 | Repo root: `ADMIN_UI_PLAN.md` (registry-era `.ad-*`), `FRONTEND_AUDIT.md` (**DevRoots Academy**), `ONYX_LEGAL_PLAN.md`, `PLAN.md` (Cryptocoinex/Onyx), `TRUE_DOCTOR_AGENT_PROMPT.md` (superseded), `ARCHITECTURE.md` (describes the retired registry as current), `googlefe4fcb0c9b8eddc9.html`, `logo-horizontal.png`/`logo-square.png`/`true-doc-logo.png` (819 KB duplicated), `ssh.txt`, `.phpunit.result.cache` (162 stale trading entries) | Violates the project's own constraint E23 (one canonical doc per feature; no root markdown sprawl). | Delete foreign docs; archive superseded ones under `docs/archive/`; keep `HMS_PLAN.md`, `README.md`; move `DASHBOARD_PLAN.md` → `docs/dashboards.md`, `AGENT_COMMAND.md` → `docs/`; move the Google file into `public/`. |
| N5 | 🔵 | `.github/workflows/ci.yml` (`MYSQL_DATABASE: clinic_pro_ci`), `AGENT_COMMAND.md:7` (`clinic-pro`), `AuthenticatedSessionController` docblocks ("students… trading screen") | Stale names. | Rename. |

## O. Documentation

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| O1 | 🟡 | `README.md:43-51` | "Phase 0 complete" — six months behind; points at `ARCHITECTURE.md` (retired registry); Node 18 claim wrong for Vite 7. | Rewrite "Where things stand"; link this plan. |
| O2 | 🟡 | `docs/IMPROVEMENT_PLAN.md` (40 KB Cryptocoinex), `docs/TRADE_UX_EDUCATION_PLAN.md`, `docs/trading/` (10 files, 176 KB), `docs/ADBLOCKER-COSMETIC-FILTERS.md` (blog post) | 244 KB of docs for products that no longer exist. | Delete/move. |
| O3 | 🟡 | `docs/frontend-spa-proposal.md` goal #5 ("dark-mode-aware") vs `d2b36cb` (light-only) | Contradiction never amended. | Amend; strike the Phase 7 dark-mode item. |
| O4 | 🟡 | missing | `docs/README.md` (index), `docs/deployment.md`, `docs/livewire-conventions.md` (→ Part V of this plan), `docs/design-system.md`, `docs/testing.md`, `CONTRIBUTING.md`, `CHANGELOG.md` (`.gitattributes:11` references it), `LICENSE` (composer says MIT), `SECURITY.md`. | Write them as part of the roadmap. |

## P. Tests & CI

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| P1 | 🔴 | `tests/Feature/AppointmentBookingHttpTest.php:20`, `tests/Feature/Api/AppointmentApiTest.php:21,102-103` | `MON_0900 = '2026-08-03 09:00'` vs `AppointmentRequest:32` `after_or_equal:today` → **8 failures since 2026-08-03**; nobody noticed (CI signal unwatched). | `Carbon::setTestNow()` or compute next Monday; branch protection so red cannot merge. |
| P2 | 🔴 | `phpunit.xml:22-23` (SQLite `:memory:`), `.github/workflows/ci.yml` | Every `lockForUpdate()` guarantee (billing, cards, stock, beds, slots, gateway settle) is tested on an engine with **no row locks**; migrations are proven on MySQL but the suite is not. | Run the suite on the MySQL service; add contention tests (parallel processes) for each locked path. |
| P3 | 🟠 | `tests/Feature/Livewire/*` (16 files, 80 tests) | No test for `Rooms\Index`; `WithTable` (sorting whitelist, `perPage`, `resetPage`) untested; no Livewire-level cross-tenant test for any index; no `assertRedirect` for the four navigating components; nothing asserts `<title>`, `wire:navigate` presence, or absence of `window.location`; `ImportsSamples` tamper untested; 16 role-helper setups duplicated per file (`tests/TestCase.php` is empty; no `InteractsWithTenant` trait). | Fill gaps; add HTML regression tests; `Tests\Concerns\InteractsWithTenant`. |
| P4 | 🟠 | — | **No browser tests.** Five shipped UI bugs (`62796d3`, `540bbc7`, `a40297c`, `987ac98`, `447ffd5`) were of a class `Livewire::test()` cannot catch (loading indicator stuck, drawer toggle, blank page from a 404'd asset, error overlay). | Dusk or Playwright smoke suite: login→dashboard, sidebar toggle mobile, slide-over round-trip, navigate between two indexes with persisted shell, toast after redirect, pagination click, no console errors. |
| P5 | 🟠 | untested: `EnsureSubscribed`, `UserController` role escalation, `ApiController` password rule, `DeviceTokenController`, `PlanController::withLimits`, policies (no `tests/Unit/Policies`), soft-delete+unique recreation, webhook double-settle/invalid hash | The exact bugs in §I/§K have no regression coverage. | Add with each fix. |
| P6 | 🟡 | `.github/workflows/ci.yml` | Branch filter hardcodes `hms-restructure`; no composer/npm cache; no `npm ci && npm run build`; no `composer audit`; no coverage; single PHP 8.2; no `concurrency`; no `permissions`; no dependabot (17 outdated deps incl. Livewire 3.8 → 4.x major); no deploy workflow; no `config:cache && route:cache` smoke. | Rework the workflow (Part IV, Phase 0). |
| P7 | 🟡 | 5 Breeze auth test files (16 permanently skipped) | Noise on every run. | Delete with Breeze. |
| P8 | 🔵 | `phpstan.neon` level 5, `paths` exclude `tests`; no `declare(strict_types=1)` anywhere; Pint default rules | Low bar for a money/clinical system. | Ratchet to 8 with baseline; add `tests`; Pint `declare_strict_types`, `no_unused_imports`. |

## Q. Accessibility & mobile

| ID | Sev | Where | What | Fix |
|---|---|---|---|---|
| Q1 | 🟡 | ~40 icon-only controls (`<a class="btn-tb-icon"><i class="fas fa-eye"></i></a>`, edit/delete buttons) across Livewire and classic views | No accessible name. | `<x-ui.icon-button label="…">` (required prop). |
| Q2 | 🟡 | B1/B3 rows | Not focusable, no `role`, no Enter. | Link cell + `tabindex`. |
| Q3 | 🟡 | C21 slide-over | No focus trap/initial focus/restore. | `x-trap`. |
| Q4 | 🟡 | `reports/index:6-7`, filter bars generally | `<select>` with no `<label>`/`aria-label`. | `<x-ui.field>`. |
| Q5 | 🟡 | 8-column tables (`schedules/index:17`), appointments filter bar (`min-width:220px` ×4 controls) | No card view on phones; guaranteed horizontal overflow at 360 px. | Responsive table → card pattern below 640 px; wrapping toolbar. |
| Q6 | 🔵 | `treatments/show:17` (`target=_blank` without `rel=noopener`), `patients/show:100` (orphan `id="ac"`), `admin/stock/alerts` vs `stock/index` (two different "low stock" reds) | Small a11y/consistency defects. | Cleanup. |

---

# Part III — Module-by-module conversion matrix

Legend: **Now** = current implementation · **Target** = shape from §4.4 · **Components** = Livewire classes to create (namespace `App\Livewire\…`).

| Module | Screen | Now | Target | Components | Deletes |
|---|---|---|---|---|---|
| Dashboard | `/admin` | Blade + 10 role views, ~20 queries, static | Board | `Dashboard\Index` (role router) + `Dashboard\Sections\*` (`#[Lazy]`, `wire:poll.60s.visible`), cached `DashboardService` | `DashboardController` |
| Onboarding | `/admin/onboarding`, `/skip` | Blade + GET skip | List (static) | `Onboarding\Index` (`skip()` action) | `OnboardingController`, skip closure |
| Patients | index | Livewire ✅ | List | (kit + sorting already) | orphan `admin/patients/index` |
| | create/edit | Livewire full page ✅ + modal ✅ | Editor | `Forms\PatientForm` replaces trait | `admin/patients/{create,edit,_form}` |
| | show | Blade, 12 forms | Detail | `Patients\Show` + `Patients\Panels\{Cards,Documents,Dependents,Insurances,Treatments}` | `PatientCardController`, `PatientDocumentController@store/destroy` (keep `download`), `PatientDependentController`, `PatientInsuranceController`, `TreatmentRecordController@store/destroy` (keep `show`,`photo`) |
| | treatments/show | Blade | Detail | `Patients\TreatmentShow` | — |
| Staff (users) | index | Livewire ✅ | List | shared `StaffService` | `admin/users/{index,create,edit,show}`, `UserController` |
| Departments / Rooms / Staff profiles / Schedules / Services / Stock categories / Lab tests / Radiology studies / Wards / Beds / Insurance providers | index+modal | Livewire ✅ | List + Editor | `Forms\*Form` + `CrudModal` | 11 × `create/edit/_form` views + controller `create/edit/store/update/destroy` |
| Appointments | index+book | Livewire ✅ | List + Editor | `Forms\AppointmentForm` | `appointments/{create,edit,_form,index}`, `AppointmentController@create/store/edit/update` |
| | show | Blade | Detail | `Appointments\Show` (transition, reschedule slide-over, open visit) | `AppointmentController@show/transition` |
| | queue | Blade, N×M forms | Board | `Appointments\Queue` (`wire:poll.15s.visible`) | `AppointmentController@queue` |
| Visits | index+open | Livewire ✅ | List + Editor | `Forms\VisitForm` (+ intake tab) | `visits/{create,index}`, `VisitController@create/store/intake` |
| | show | Blade, 12 forms, 92 inline styles | Detail workspace | `Visits\Show` + `Visits\Panels\{Vitals,Clinical,Charges,LabOrders,RadiologyOrders,Dispense,Prescriptions}` | `VisitController@vitals/clinical/transition`, `MedicalServiceController`, `InvoiceController@generate`, `LabOrderController@store`, `RadiologyOrderController@store`, `DispensationController@store`, `PrescriptionController` |
| Invoices | index | Livewire ✅ | List | — | orphan index |
| | show | Blade | Detail | `Invoices\Show` + `Invoices\TakePayment` (pessimistic) | `InvoiceController@show`, `PaymentController@store` (keep `receipt`, `pdf`, gateway `start`) |
| Lab | orders index | Livewire ✅ | List (+poll) | `withCount` | — |
| | order show | Blade | Detail | `LabOrders\Show` (inline results `wire:model.blur`) | `LabOrderController@show/transition/result` (keep `pdf`) |
| Radiology | order show | Blade | Detail | `RadiologyOrders\Show` | `RadiologyOrderController@show/transition/report` (keep `pdf`) |
| Pharmacy | stock index+modal | Livewire ✅ | List + Editor | `Forms\StockItemForm` | `stock/{create,edit,_form,index}`, `StockItemController@create/store/edit/update` |
| | stock show | Blade | Detail | `Stock\Show` + `Stock\Movement` slide-over (receive/adjust) | `StockItemController@show/receive/adjust/destroy` |
| | alerts | Blade | Board | `Stock\Alerts` (`wire:poll.60s.visible`) | `StockAlertController` |
| | dispensation show | Blade | Detail | `Dispensations\Show` | `DispensationController@show` |
| Inpatient | admissions index+admit | Livewire ✅ | List + Editor | `Forms\AdmissionForm` | `admissions/{create,index}`, `AdmissionController@create/store` |
| | admission show | Blade, 5 forms | Detail | `Admissions\Show` + `Admissions\Panels\{Transfer,Discharge,VitalRounds,Medications,NursingNotes}` | `AdmissionController@show/transfer/discharge`, `NursingController` (keep `summaryPdf`) |
| | board | Blade | Board | `Admissions\Board` (`wire:poll.30s.visible`) | `AdmissionController@board` |
| Insurance | claims index+create | Livewire ✅ | List + Editor | `Forms\InsuranceClaimForm` | `insurance-claims/{create,index}`, `InsuranceClaimController@create/store` |
| | claim show | Blade | Detail | `InsuranceClaims\Show` | `InsuranceClaimController@show/transition` |
| Finance | FY index | Livewire ✅ (no table) | List | adopt `WithTable`; confirm on reopen | `financial-years/{create,index}`, `FinancialYearController@create/store/close/reopen` |
| | FY show | Blade | Detail (report) | `FinancialYears\Show` | `FinancialYearController@show` |
| Notifications | index | Blade | List (+poll) | `Notifications\Index`, `Shell\NotificationBell` | `NotificationController` |
| Reports | index | Blade GET form | Board | `Reports\Index` (`#[Url]` range, `#[Computed]`, cached) | `ReportController` |
| Settings | site / billing | Blade | Editor | `Settings\Site`, `Settings\Billing` (live preview) | `SettingController`, `BillingSettingController`, `ThemeController` |
| Subscription | plans/checkout | Blade | List | `Subscription\Index` (checkout stays POST → gateway) | `SubscriptionCheckoutController@index` |
| Super | hospitals/plans/subscriptions | Livewire ✅ | List + Editor | policies; `withoutGlobalScope`; record-payment modal | `super/*/{index,create,edit}`, `Super\*Controller@create/edit/store/update` |
| Shell | layout | Blade + inline JS | Persistent shell | `Shell\NotificationBell`, `Shell\ProfileModal` | `ApiController` (profile drawer), `partials/sw-kill` |
| Auth / marketing / PDFs / emails | — | classic | classic (out of SPA) | — | — |

---

# Part IV — Execution roadmap

Each step: implement → tests → `composer ci` green → commit. Steps within a phase are ordered; phases are sequential except where noted. Findings IDs in brackets.

## Phase 0 — Stop the bleeding (P0, ≈1–2 days)
0.1 Untrack the SQL dump, ignore `/storage/backups`, extend `secrets-scan.sh` with size/`*.sql` rules; **history purge + credential rotation is an owner decision** — schedule it. [I1]
0.2 Fix the date-bomb tests; suite green. [P1]
0.3 `->middleware('web')` on the custom Livewire update route; `Livewire::addPersistentMiddleware([...])`; tests asserting both. [C1, C2]
0.4 `EnsureSubscribed` → `activeSubscription()`; apply `subscribed` to admin + API groups behind `tenancy.enforce_subscription` (on in prod/local, off in tests like the onboarding gate); regression test. [I3, I4]
0.5 Block `super_admin` assignment by non-super-admins in both surfaces; policies `before()` → `isSuperAdmin()`; test. [I5]
0.6 Horizon gate → super-admin + `auth`; Telescope default off. [I6, I7]
0.7 Profile password rule → `Password::defaults()` via FormRequest. [I9]
0.8 Interim root `.htaccess` deny for sensitive extensions; `docs/deployment.md` describing the docroot=`public/` deploy. [I2, M1]
0.9 Route closures → controllers; `route:cache` smoke in CI. [D9, M3]
0.10 `.env.example` rewrite (correct Laravel 12 keys, documented app keys). [M2, L5]
0.11 Delete `DeviceToken` feature or fix uniqueness + authorization. [I10]

## Phase 1 — SPA foundation (≈1 week)
1.1 Vite pipeline: `resources/css/td-admin/*` (split, tokens, missing classes `tb-alert-danger`→`tb-alert-error` alias, `badge-success`→`badge-active`), `resources/js/admin.js` (shell Alpine data, single `livewire:navigated` hook, scroll-lock, dirty-guard, offline banner), `@vite` in `layouts.admin`/`auth`/`marketing`; remove Tailwind/PostCSS/`app.js`; `.nvmrc`; CI builds. [H1, G1, A1, A2, A6, A7]
1.2 Layout rewrite per §4.1: `<title>` fix, `[x-cloak]` in head, `@persist` regions, delete `sw-kill`, delete `chart.min.js`, delete flash banner, `no-store` middleware. [A3–A6, A12, A13, A21, B7]
1.3 `Shell\NotificationBell` (polled) and `Shell\ProfileModal` (replaces `ApiController` profile endpoints). [A7, A8]
1.4 `config/livewire.php` published; assets published; progress bar themed. [4.2]
1.5 `WithTable` v2: `paginationView()` + themed `livewire.partials.pagination`; `perPage` whitelist + selector; `search` max; generic `resetPage`; sort either real (via `<x-ui.th-sort>`) or removed per table; standard loading targets. [F1, F2, C3, C12, C15]
1.6 `x-ui` kit: `link`, `icon-button`, `page-header`, `breadcrumb`, `toolbar`, `table`, `empty`, `skeleton`, `badge`, `money`, `field`, `select-search`, `confirm`, `detail-cols`, `stat`, `section`; `slideover` gets `x-trap`, focus restore, ESC dirty-guard. [G5, G6, C21, Q1]
1.7 Toast pipeline unification: delete the 29 per-view `session('error')` blocks and the layout banner; flash instead of dispatch before navigate-redirects. [E2, E3, C6]
1.8 Navigation sweep: every `<a>` in `resources/views/admin/**` and `super/**` → `wire:navigate` (via `x-ui.link`/`breadcrumb`), except the download/PDF/gateway exclusions; delete the 9+2 `onclick="window.location"` rows; `.hover` prefetch on sidebar/dashboard; HTML regression test. [B1–B6, A15]
1.9 Livewire component hardening: `#[Locked]` ids, `#[Computed]` option lists + `@if($showForm)` guards everywhere, `withCount` fixes, narrow `catch`, `ImportsSamples` validation, `User::currentHospital()`, `withoutGlobalScope` in `Super\*`, confirm on FY reopen, wording/empty-state fixes. [C4, C5, C7, C8, C14, C16–C19, C23–C26]

## Phase 2 — One source of truth for forms (≈3–4 days)
2.1 `rulesFor()` statics on all FormRequests; `Livewire\Form` objects per model; `CrudModal` trait; delete duplicated `rules()`/`edit()`/`save()` bodies. [C9, C10]
2.2 Delete the duplicate controller create/edit/store/update paths + orphaned views for the 14 nouns with modals; narrow `Route::resource`s. [N1]
2.3 `StaffService` shared by `Users\Index` (only surface left). [J6, I5]

## Phase 3 — Clinical workspaces (≈2 weeks)
3.1 `Visits\Show` workspace + 7 panels (repeaters for dispense/prescribe; `select-search` for stock/services). [D1–D3]
3.2 `Patients\Show` + 5 panels (typeahead dependents, `WithFileUploads` documents with progress). [D1, D5, D6]
3.3 `Appointments\Show`, `Appointments\Queue` (poll). [D.1]
3.4 `Admissions\Show` + panels, `Admissions\Board` (poll); discharge pessimistic + confirm. [D4]
3.5 `LabOrders\Show`, `RadiologyOrders\Show`, `Dispensations\Show`. 
3.6 `Invoices\Show` + `TakePayment` (pessimistic; gateway POST stays). 
3.7 `Stock\Show` + movement slide-over, `Stock\Alerts` (poll). 
3.8 `InsuranceClaims\Show`, `FinancialYears\Show`, `Notifications\Index`, `Reports\Index`, `Settings\*`, `Subscription\Index`, `Onboarding\Index`, `Dashboard\Index` + lazy polled sections with cached service. [D7, D10, L3]
Each step deletes its controller methods/views and ships `Livewire::test()` coverage (render, RBAC, tenancy, action side-effects, redirect/flash).

## Phase 4 — Data integrity & backend hardening (≈1 week, parallelisable with Phase 3)
4.1 Sequences table for document numbers. [K1] · 4.2 Booking guard. [K2] · 4.3 Invoice-per-visit guard inside the transaction + unique. [K3] · 4.4 Plan limit keys. [K4] · 4.5 Soft-delete-aware uniques. [K5] · 4.6 `restrictOnDelete` on ledgers. [K6] · 4.7 Prescription cascade / dose scoping. [K7] · 4.8 Closed-period guard on all money writes. [K8] · 4.9 Locks in transfer; bed status derivation. [K9] · 4.10 Float removals. [K10] · 4.11 Subscription currency + history rows. [K11] · 4.12 Gateway reconcile + unique `provider_ref`. [K12] · 4.13 Indexes. [K13] · 4.14 Policies + abilities (J3), FormRequests for inline validations, `Rule::enum` on transitions. · 4.15 Security headers, trusted proxies, API login throttle, Sanctum abilities/expiry, avatar mimes, activity-log tenancy, private disk. [I11–I20]

## Phase 5 — Sweep & DX (≈3 days)
5.1 Delete dead assets/layouts/components/legacy/config/scripts/docs; move root docs; fix stale names. [H2–H5, N2–N5, O2, M4, M7]
5.2 Docs: `README` rewrite, `docs/README.md`, `deployment.md`, `livewire-conventions.md` (Part V), `design-system.md`, `testing.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `LICENSE`, `SECURITY.md`. [O1–O4]
5.3 CI: MySQL-backed suite, contention tests, coverage + threshold, `npm ci && build`, `composer audit`, PHP matrix, caches, `concurrency`/`permissions`, dependabot, branch filter, `route:cache` smoke; browser smoke suite (Dusk/Playwright). [P2–P8]
5.4 PHPStan ratchet to 8; `declare(strict_types=1)`; Blade formatter; arch tests ("no `$request->validate()` in controllers", "every tenant model uses `BelongsToHospital`", "no `window.location` in views"). [P8]

## Phase 6 — Real-time & polish (optional, after go-live)
Reverb + Echo on `hospital.{id}` private channels replacing `wire:poll`; command palette (⌘K); responsive table→card; reduced-motion; Livewire 4 upgrade evaluation.

---

## Execution status — 2026-09-14

Phases 0–5 are **implemented and merged** on `hms-restructure`. Phase 6 (Reverb, command
palette, mobile card tables) remains open by design.

| Phase | State | Evidence |
|---|---|---|
| 0 — Stop the bleeding | ✅ done | dump untracked + scan guards it; date-bomb tests fixed; Livewire update route carries `web` and access gates persist; subscription gate wired; `super_admin` escalation closed; Horizon/Telescope locked; route closures gone (`route:cache` works); `.env.example` rewritten |
| 1 — SPA foundation | ✅ done | persisted shell + Vite pipeline + `x-ui` kit + `WithTable` v2 + themed AJAX pagination; 192 links converted; 9 row-click hacks removed; `SpaNavigationHtmlTest` guards it |
| 2 — One source of truth | ✅ done | `CrudModal` + 49 FormRequests exposing `rulesFor()`; every duplicated rule array gone; classic create/edit paths deleted |
| 3 — Screen conversion | ✅ done | **0 classic admin views remain**; 75 Livewire components / 119 views; visit workspace (7 lazy panels, real repeaters) replaced 12 POST forms; patient, invoice, lab, radiology, stock, admission, appointment, claim, financial-year, dispensation and treatment details converted; dashboard, reports, settings, notifications, onboarding, subscription converted |
| 4 — Data integrity | ✅ done | `Sequence` numbering; invoice-per-visit lock; booking lock; closed-period guards; float-free money; ledger FKs restrict; indexes; headers/proxies/Sanctum/API throttle; `IntegrityHardeningTest` |
| 5 — Sweep & DX | ✅ done | `public/` 6.3 MB → 1.5 MB; `_legacy/`, Breeze, dead layouts/components/config removed; docs set written; CI rebuilt (matrix, Vite build, audits, MySQL lock run, dependabot) |
| 6 — Real-time & polish | ⬜ open | `wire:poll` is in place on the live surfaces; Reverb, ⌘K palette and mobile card tables deferred |

### Measured state

| Metric | Before | Now |
|---|---|---|
| Classic admin Blade pages | 40 | **0** |
| Admin controllers | 44 | **11** (PDFs, downloads, gateway, device tokens only) |
| Classic POST forms in views | 113 | **11** (7 auth pages, 2 sign-out, 2 gateway hand-offs) |
| Links without `wire:navigate` | 233 | **0** |
| `window.location` / `confirm()` / `alert()` | 13 / 21 / 2 | **0 / 0 / 0** |
| Inline `style=""` | 1,049 | **191** (47 in emails, 2 in PDFs, 96 thinly spread in Livewire views) |
| Livewire components | 29 | **75** |
| `#[Lazy]` / `#[Computed]` / `#[Locked]` / `wire:poll` | 0 / 0 / 0 / 0 | **21 / 98 / 32 / 6** |
| Tests | 402 (8 failing, 16 skipped) | **~590 passing, 0 failing, 0 skipped** |
| PHPStan (level 5) | 0 errors, but gate red on tests | **0 errors, whole gate green** |

### Verified against the running application

Seeded demo data on MySQL 5.7 and walked the app over HTTP (`artisan serve`, real login):
31 index pages, 14 detail pages and 9 role dashboards all returned 200; another tenant's
records returned 404; `livewire.js` and the Vite bundles loaded; `/livewire/update`
answered 419 without a CSRF token (proving it carries the `web` group); the patients page
shipped a server-rendered `<title>`, 41 `wire:navigate` links, 5 persisted regions and none
of the code Phase 1 removed. Two real defects surfaced and were fixed: demo tenants had no
subscription (so the new gate locked the whole demo environment out) and the seeder stopped
at patients and stock, leaving most modules empty. `SmokeRoutesTest` now walks the route
table dynamically so both stay fixed.

### Still open (tracked, not forgotten)

1. **Deployment** — the document root must move to `public/` (`docs/deployment.md`); the
   repository-root `index.php`/`.htaccess` MAMP hack is hardened but still present.
2. **History purge** — the 134 MB dump is untracked and guarded, but rewriting it out of
   git history (and rotating the credentials it contains) is an owner decision.
3. **Browser tests** — Dusk/Playwright smoke for the navigate lifecycle (the class of bug
   `Livewire::test()` cannot see) is specified in Part IV §5.3 and not yet built.
4. **PHPStan ratchet to level 8** and `declare(strict_types=1)`, one PR per level.
5. **Reverb** to replace `wire:poll` on the live surfaces.
6. **Inline styles** — 96 remain in Livewire views; the agents listed the handful of CSS
   classes (`.tb-repeater-row`, `.tb-bed-grid`, `.tb-thumb-grid`, `.tb-dl`, `.tb-fieldset`,
   `.tb-checkbox-list`, `.tb-pipeline`) that would remove most of them.

---

# Part V — House rules: the Livewire/PJAX constitution

These are the rules that keep the app "perfect" after the roadmap. They belong in `docs/livewire-conventions.md` and are enforced by tests/arch rules where marked ⚙.

1. **Every link is `wire:navigate`** (`<x-ui.link>`), except downloads, PDFs, external redirects and sign-out. ⚙ no `href="{{ route` without `wire:navigate` in `resources/views/{admin,livewire,super,partials,layouts}`.
2. **No `window.location`, `location.reload()`, `alert()`, `confirm()`, `<form method="POST">` in admin views.** ⚙ grep test. Destructive actions use `wire:confirm`.
3. **One `<h1>` per page**, server-rendered `<title>` via `->title()`; list pages use `sr-only`. ⚙ test per component.
4. **Components are thin**: `mount()` authorize + hydrate, `render()` authorize + query, actions authorize → validate (Form object) → Service → toast/flash. No business logic in components. Domain exceptions only in `catch`.
5. **Every record-identifying public prop is `#[Locked]`**; every client-editable array prop is validated before use; `perPage` whitelisted; `search` ≤ 100 chars.
6. **Option lists are `#[Computed]`** and referenced only inside the open modal; lists > 50 rows use `<x-ui.select-search>`.
7. **Tables use `WithTable`**: URL-bound state, `resetsPage` list, `wire:loading.class.delay` + explicit targets, `wire:key="{table}-{id}"`, themed `paginationView()`, filter-aware empty state.
8. **Modals use `<x-ui.slideover>`**: `@if($showForm)`-guarded body, focus trap, ESC dirty-guard, close only via server or explicit cancel.
9. **Feedback**: `dispatch('toast')` for in-place actions; `session()->flash('toast', …)` before any redirect; never both. No layout banners.
10. **Scripts**: shell JS in the Vite bundle (`<head>`, runs once); component JS only via `@script`/`@assets`; islands inside `wire:ignore`; every listener/timer has teardown. No `@push('scripts')` on Livewire pages, no `DOMContentLoaded`.
11. **Shell regions are `@persist`-ed**; anything inside them must be navigation-agnostic (reads `document.title`, polls, never assumes page context).
12. **Live data polls with `wire:poll.Ns.visible`**; money writes are pessimistic (disabled button + spinner + server confirmation); optimistic UI only for idempotent toggles (mark-read).
13. **Validation rules live in FormRequests** (`rulesFor()`); Form objects and API share them. ⚙ arch test: no inline `rules()` array duplicating a FormRequest.
14. **Tenancy**: never hand-filter `hospital_id` (use scopes/`currentHospital()`); `Super\*` explicitly `withoutGlobalScope`; every new component ships a cross-tenant test.
15. **Naming**: filters `$filterX`, rows `$rows`, form fields on `$form`, events past-tense (`patient-saved`), toasts sentence-case with the record identifier.
16. **Markup**: `x-ui.*` components only; no inline `style=` except computed values (widths/gradients); tokens only, no hex. ⚙ Stylelint.
17. **Accessibility**: every icon-only control has `aria-label` (enforced by `<x-ui.icon-button>`), every dialog traps focus, every table row action is keyboard-reachable.
18. **Tests per component**: renders for the allowed role, 403 for a denied role, tenant B cannot see tenant A, each action's side-effect via the Service, redirect/flash, validation errors surface.

---

# Part VI — Acceptance checklist ("perfect" defined)

The work is done when every line is true and, where marked ⚙, enforced by CI.

**Navigation & shell**
- [ ] ⚙ Zero `href="{{ route` without `wire:navigate` under admin/super/livewire/partials/layouts (allow-list for downloads).
- [ ] ⚙ Zero `window.location`, `location.reload`, `alert(`, `confirm(`, `method="POST"` in admin views (allow-list: logout, gateway).
- [ ] Sidebar/topbar/toasts/footer persist across navigation (browser test: scroll position + open accordion + in-flight toast survive a navigate).
- [ ] Server HTML `<title>` correct on every page (⚙ Livewire test per component asserts `<title>`).
- [ ] No listener/timer growth after 50 navigations (browser test counts `getEventListeners`/timers).
- [ ] Progress bar visible on slow navigations; prefetch on sidebar hover.
- [ ] After sign-out, Back does not render an authenticated page.

**Actions & forms**
- [ ] Every create/edit/action on every screen in Part III is a Livewire action with loading state and a single toast.
- [ ] Every destructive/money/clinical action has `wire:confirm` and is pessimistic.
- [ ] Validation errors render inline without losing modal/scroll state; `PlanLimit`, `SlotUnavailable`, `InsufficientStock`, `ClosedPeriod` map to user-readable messages.
- [ ] Dirty-guard on navigate/ESC for unsaved forms; offline banner when the wire drops.
- [ ] ⚙ Every FormRequest exposes `rulesFor()`; no duplicated rule arrays.

**Security**
- [ ] ⚙ Both Livewire update routes carry `web`; persistent middleware includes `admin`, `super`, `subscribed`, `permission`.
- [ ] ⚙ Lapsed tenant → 403 on page and on Livewire action; demoted user cut off on next action.
- [ ] ⚙ Tenant admin cannot create/assign `super_admin`.
- [ ] Horizon/Telescope gated to super-admins; Telescope off by default.
- [ ] Repo contains no data dumps; secrets scan fails on large/`*.sql` files; deploy docroot is `public/`; security headers present.

**Performance**
- [ ] No query runs in `render()` that is not needed for the visible DOM (option lists computed lazily).
- [ ] Layout runs zero per-request COUNT queries; dashboard/report sections cached and polled.
- [ ] ⚙ N+1 detector (`Model::preventLazyLoading()` in tests) passes on every component test.
- [ ] Admin CSS/JS served hashed and minified via Vite; FontAwesome subset; fonts self-hosted; dead assets removed (public/ < 1.5 MB).

**Data integrity**
- [ ] Document numbers allocated via a locked sequence; ⚙ MySQL contention tests for invoices, cards, stock, beds, slots, gateway settle.
- [ ] Soft-deleted names can be recreated; ledgers cannot cascade-delete; closed periods reject all money writes.

**Quality**
- [ ] ⚙ `composer ci` green on MySQL and SQLite; PHPStan level 8; coverage ≥ 80 % on `app/Services` and `app/Livewire`.
- [ ] ⚙ Browser smoke suite green (login, navigate, slide-over, toast, pagination, mobile drawer, no console errors).
- [ ] ⚙ Arch tests green (Part V rules).
- [ ] Docs in place: `deployment.md`, `livewire-conventions.md`, `design-system.md`, `testing.md`, README current, no foreign-product docs in the tree.

---

# Appendix — Verification commands and counts

```bash
# Navigation coverage
grep -rn 'href="{{ route' resources/views | grep -vc 'wire:navigate'          # 237 at audit
grep -rn 'window.location\|location.reload' resources/views | wc -l           # 13 at audit
grep -rn '@csrf' resources/views/admin resources/views/super | wc -l          # 113 at audit
grep -rn 'onsubmit="return confirm\|onclick="return confirm' resources/views | wc -l   # 21

# Livewire feature usage (all 0 at audit)
grep -rln '#\[Lazy\]\|#\[Computed\]\|#\[Locked\]\|Livewire\\Form\|@script\|@assets\|@persist\|wire:poll' app resources

# Livewire routes and middleware
php artisan route:list --path=livewire -v

# Gate
composer ci
php artisan route:cache && php artisan route:clear   # must succeed

# Repo hygiene
git ls-files | xargs -I{} sh -c 'test $(wc -c < "{}") -gt 5000000 && echo "{}"'   # large tracked files
git ls-files | grep -E '\.(sql|dump)$'
du -sh public/* | sort -h
```

Counts at audit (2026-09-13): 334 PHP files in `app/`; 205 Blade views; 29 Livewire components; 26 tables on `WithTable`; 24 slide-over modals; 402 tests (80 Livewire), 8 failing; 65 migrations; 54 models (49 tenant-scoped); 907 inline styles in classic views; 127 hex colours; `public/` 6.3 MB (~4.4 MB dead); `.git` 158 MB (134 MB dump).
