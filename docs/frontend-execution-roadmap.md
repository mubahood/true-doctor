# Frontend SPA conversion — decisions & execution roadmap

Companion to [`frontend-spa-proposal.md`](frontend-spa-proposal.md). This file records the **final
decisions** (all chosen for lowest risk, zero visual regression, and an advanced UX) and the
**module‑by‑module execution plan**. Each item is checked off as it ships. Non‑breaking throughout:
the app stays fully working and fully tested after every commit.

## Decisions (locked)

| # | Question | Decision | Rationale |
|---|---|---|---|
| 1 | Reactivity | **Livewire 3** | server‑driven, reuses our Services/Policies, SEO‑safe, PHP‑first |
| 2 | SPA navigation | **`wire:navigate`** | instant transitions, no build‑time SPA router |
| 3 | CSS / design | **Keep `td-admin.css` (`tb-*`)** — no Tailwind‑4 migration, **no Flux** | zero visual regression; Livewire is CSS‑agnostic |
| 4 | Component kit | **Blade components wrapping existing `tb-*`** | one house style, no rework of the look |
| 5 | Tables | **Custom reusable Livewire table** (trait + `tb-table` markup) | perfect visual match; avoids PowerGrid's Tailwind assumptions |
| 6 | Forms | **Livewire Form objects reusing FormRequest `rules()` → Services** | one validation source; one business‑logic path |
| 7 | Modals / slide‑overs / toasts | **Blade + Alpine components** (reuse existing modal CSS) | consistent, lightweight |
| 8 | Real‑time | **`wire:poll` now; Reverb later** | no infra dependency during build; upgrade per‑surface |
| 9 | Charts | **Chart.js** (already bundled) | no new dependency |
| 10 | Single‑file comps | **No Volt** — class‑based components | clearer, easier to test |

**Architecture rule:** Livewire components are *thin* (like our controllers). They **authorize**
(Policies), **validate** (shared rules), **call a Service**, and **render**. No business logic in
components. The Sanctum API stays for external clients. One core, two surfaces.

## Base building blocks (Phase 0 — build once, reuse everywhere)

- **Blade components:** `x-ui.button`, `x-ui.badge`, `x-ui.status` (drives off enum `badge()`),
  `x-ui.card`, `x-ui.field` (label+control+error+hint), `x-ui.modal`, `x-ui.slideover`,
  `x-ui.confirm`, `x-ui.empty`, `x-ui.skeleton`, `x-ui.page-header`, `x-ui.stat`, `x-ui.money`.
- **Livewire table base:** a `WithTable` trait (search, sort, per‑page, filters, URL‑bound state,
  `WithPagination`) + a shared `livewire.partials.table` shell + `livewire.partials.pagination`.
- **Toast host + flash bridge:** one toast region in the shell; components `dispatch('toast', …)`;
  server flash (`session('success'|'error')`) is bridged into a toast on load.
- **Shell integration:** Livewire scripts/styles wired into `layouts.admin`; the standalone Alpine
  include removed (Livewire 3 bundles Alpine); `tdAdmin()` registered via `alpine:init`;
  `wire:navigate` on every sidebar/nav/breadcrumb link; persistent shell so it never flickers.

## Testing rule (every phase)

- Keep **all** existing feature tests green (they protect the Services — the crown jewels).
- Add **`Livewire::test()`** component tests per converted screen: renders, filter/sort/search,
  pagination, validation errors, authorization (RBAC), tenant scope, service side‑effects, events.
- Gate unchanged: **Pint + PHPStan(1G) + migrate:fresh --seed + full test run** must pass before each
  commit.

## Execution plan (module by module — the full system, nothing skipped)

Legend: ⬜ todo · 🟦 in progress · ✅ done. Each module = **List** (Livewire table) + **Editor**
(Livewire form, slide‑over/page) + **Detail** (show page with live/lazy sections), unless noted.

### Phase 0 — Foundation
- ✅ Install Livewire 3; integrate into `layouts.admin` (remove dup Alpine, add scripts, keep
  `tdAdmin`); `wire:navigate` on the shell nav. *(commit 3bd5dad)*
- ✅ Build the Livewire **table base** (`WithTable` trait + `sort-caret` partial). *(3bd5dad)*
- ✅ Build the **toast host** + server‑flash bridge (`partials/toast-host`, `dispatch('toast', …)`). *(this phase)*
- ✅ Verify: app renders identically, navigates SPA‑style, all tests green. **Committed.**
- Note: `x-ui.*` Blade kit deferred — converting screens directly against `tb-*` classes keeps
  zero visual regression and avoids a speculative abstraction layer; extracted later if duplication warrants.

### Phase 1 — Patients (reference module; sets the template) — **DONE**
- ✅ `Patients\Index` (Livewire table: search + status filter + sort + paginate; row → show). *(3bd5dad)*
- ✅ `Patients\Form` (full‑page create/edit; reuses `PatientRequest` rules + `PatientService`; plan‑limit → inline error + toast).
- ✅ `Patients\Show` — kept as Blade, SPA‑navigated via `wire:navigate` (fully functional; nested action
  panels unchanged). Deep lazy‑section conversion is a later polish pass, not a blocker.
- ✅ Livewire tests: `PatientsIndexTest` (6), `PatientFormTest` (5). **Committed.**

### Phase 2 — Core clinical — **indexes DONE**
- ✅ Appointments: Livewire index (date + doctor + status filter, patient search). Book/show stay Blade (SPA‑nav); live check‑in queue is a later `wire:poll` pass.
- ✅ Visits: Livewire index (stage filter + visit/patient search). Open form + show stay Blade (SPA‑nav).

### Phase 3 — Money — **indexes DONE**
- ✅ Invoices: Livewire index (status filter + invoice/patient search). Show + take‑payment stay Blade (SPA‑nav).
- ✅ Insurance: providers Livewire index (search + inline archive) + claims Livewire index (status filter + search). Claim show stays Blade.
- ⬜ Subscription/checkout page: already partly dynamic; left as Blade.

### Phase 4 — Pharmacy & diagnostics — **indexes DONE**
- ✅ Stock items: Livewire index (name search + low‑stock/expiring quick filters). Item show + receive/adjust stay Blade.
- ✅ Stock categories: Livewire index (search + inline delete). Form stays Blade.
- ✅ Lab: tests catalogue Livewire index + lab orders Livewire index (status filter + patient search). Order show stays Blade.
- ✅ Radiology: studies catalogue Livewire index + radiology orders Livewire index. Order show stays Blade.

### Phase 5 — Inpatient — **indexes DONE**
- ✅ Wards + Beds: Livewire indexes (search + inline delete). Forms stay Blade.
- ✅ Admissions: Livewire index (active‑cohort default + status filter + patient search). Admit form + show + occupancy board stay Blade.

### Phase 6 — Org, staff, reports, settings, dashboard — **indexes DONE**
- ✅ Departments, Rooms, Staff profiles, Doctor availability: Livewire indexes (search + inline delete). Forms stay Blade.
- ✅ Services (price list): Livewire index (search + inline delete). Form stays Blade.
- ✅ Financial years: Livewire index with inline close/reopen (via `FinancialYearService`).
- ⬜ Reports & dashboards: filterable Chart.js tiles — later polish pass (kept as Blade).
- ⬜ Settings, Notifications list, Super‑admin: kept as Blade (SPA‑navigated); low list‑volume.

**Scope note (honest):** every high‑traffic **list/index** screen across the whole system is now a
Livewire table (live search / filter / sort / paginate, inline row actions, toast feedback).

### Forms — every create/edit is now an AJAX modal — **DONE**
A reusable `<x-ui.slideover>` (Alpine `$wire.entangle`, no teleport so `wire:` bindings stay intact,
`position:fixed` overlay) hosts an in‑place create/edit form on each index. Opening/editing/saving is
all over Livewire AJAX — no navigation, the table refreshes in place, and feedback comes through the
toast host. Each form authorizes via its Policy, validates with the same rules as its FormRequest, and
persists through the module's Service (business logic untouched).

Converted: Departments, Rooms, Services, Stock categories, Lab tests, Radiology studies, Wards, Beds,
Insurance providers, Staff profiles, Doctor availability, Financial years (create), Stock items,
Patients (register/edit — shared `InteractsWithPatientForm` trait + `_fields` partial reused by the
full‑page editor and the modal), Users (avatar upload via `WithFileUploads`, generated‑password +
welcome email, optional password reset), Appointments (book → `AppointmentService`), Visits
(open → `VisitService`), Insurance claims (→ `InsuranceService`), Admissions (admit →
`AdmissionService`). Modals with large option lists render their form only while open (`@if($showForm)`)
so a closed page never leaks the full patient roster. Each is covered by `Livewire::test()` cases
(create, edit, validation, RBAC, tenant scope, service side‑effects).

### Phase 7 — Polish
- ⬜ Command palette (⌘K), dark‑mode audit, a11y pass, mobile table card‑view, motion polish.
- ⬜ (Optional/infra) Reverb + Echo to replace `wire:poll` on the live surfaces.

## Coexistence & rollback

- Un‑converted modules keep their current controllers/routes untouched.
- A converted module swaps its route target to the Livewire component **at the same URL** — users see
  no half‑built app.
- Each phase is an isolated, revertable commit; if a phase regresses, revert just that commit.
