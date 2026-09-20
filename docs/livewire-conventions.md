# Livewire & PJAX conventions

The back-office is a single-page application built on Livewire 3 `wire:navigate`.
These rules keep it that way. They are the "house rules" from
[`PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md`](PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md) Part V and
are enforced by `tests/Feature/SpaNavigationHtmlTest.php` where marked ⚙.

## Navigation
1. ⚙ Every in-app link is `wire:navigate` (`<x-ui.link>`). Plain links only for
   downloads, PDFs, the payment gateway redirect and sign-out (allow-list in the test).
2. ⚙ No `window.location`, `location.reload()`, `alert()`, `confirm()` or
   `<form method="POST">` in Livewire views. Destructive actions use `wire:confirm`.
3. One `<h1>` per page and a server-rendered title via `->title('…')` in `render()`.
   List pages use `<h1 class="sr-only">`; the topbar mirrors `document.title`.

## Shell
- `resources/views/layouts/admin.blade.php` is the only admin layout. The sidebar,
  toast host, confirm dialog, offline banner and footer are `@persist`-ed: anything
  inside them must not assume page context (the sidebar re-derives its active item
  client-side; see `resources/js/admin.js`).
- Shell JavaScript lives in `resources/js/admin.js`, built by Vite and loaded in
  `<head>` so it runs once. Component JavaScript uses `@script`/`@assets`; never
  `@push('scripts')`, never `DOMContentLoaded`, and every listener/timer has teardown.
- Feedback: `$this->dispatch('toast', message:, type:)` for in-place actions;
  `session()->flash('success', …)` before any `redirect(…, navigate: true)`. Never both.

## Components
- Thin: `mount()` authorizes + hydrates; `render()` authorizes + queries; actions
  authorize → validate → call a Service → toast/flash. No business logic in components.
- Catch only domain exceptions (`\DomainException`, `SlotUnavailableException`,
  `InsufficientStockException`, `ClosedPeriodException`, `PlanLimitExceededException`…).
  Anything else is reported and shown as a generic toast.
- `#[Locked]` on every record-identifying public property; validate every
  client-editable array before persisting it.
- Option lists are `#[Computed]` and referenced only inside `@if($showForm)`. Lists
  over ~50 rows use `<livewire:ui.select-search>`.
- Tables: `use WithTable` (URL-bound state, whitelisted `perPage`, `resetsPage()`,
  themed `paginationView()`); loading state
  `wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage,…"`;
  `wire:key="{table}-{id}"`; filtered empty state via `<x-ui.empty :filtered>`.
- Catalogue create/edit: `use CrudModal` with `modelClass()`, `formFields()`,
  `nounLabel()`, `rules()` delegating to `XRequest::rulesFor($this->editingId)`.
  Validation rules live in the FormRequest only.
- Dialogs: `<x-ui.modal show="showForm" size="lg">` — a **centred modal**, never a
  side drawer. Body inside `@if($showForm)`; Cancel calls `requestClose()`
  (dirty-guard); focus is trapped and restored; Escape and backdrop close through
  the same guard. Sizes: `sm` 460 · `md` 640 · `lg` 860 (default) · `xl` 1100 ·
  `full` 96vw; on phones every modal becomes a full-screen sheet.
  Use `<div class="tb-modal-body">` and `<div class="tb-modal-foot">` inside it.
- Live data polls with `wire:poll.Ns.visible`; money/clinical writes are pessimistic
  (button disabled while loading, server-confirmed). Optimistic UI only for
  idempotent toggles.
- Tenancy: never hand-filter `hospital_id` — use the global scope or
  `User::currentHospital()`. `Super\*` components explicitly `withoutGlobalScope(HospitalScope::class)`.
- Naming: filters `$filterX`, rows `$rows`, events past tense, keys `{table}-{id}`.

## Adding a new screen (checklist)
1. Pick the shape: List / Editor / Detail / Board (`PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md` §4.4).
2. Component in `app/Livewire/<Module>/…`, view in `resources/views/livewire/<module>/…`,
   `#[Layout('layouts.admin')]`, route in `routes/web.php` pointing at the class.
3. Markup from the `x-ui.*` kit only; no inline `style=""` except computed values.
4. Tests (`tests/Feature/Livewire/…`, use `Tests\Concerns\InteractsWithTenant`):
   renders for an allowed role · 403 for a denied role · tenant B cannot see tenant A ·
   each action's side-effect via the Service · redirect/flash · validation errors.
5. `composer ci` green (Pint, PHPStan, empty-file + secrets scans, tests).
