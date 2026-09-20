# Proposal — Making True‑Doctor feel like a single‑page app, and making every module robust

**Status:** Draft for sign‑off · **Author:** engineering · **Date:** 2026‑07‑19
**Scope:** the *web* admin/clinical UI (the marketing site and the Sanctum API are covered only where they intersect).

---

## 0. TL;DR (the recommendation in one paragraph)

Adopt **Livewire 3** as the reactivity layer for the whole back‑office, using **`wire:navigate`** for
instant SPA‑style page transitions, **Livewire PowerGrid** for every list/table (search, sort,
filter, paginate, export — all over AJAX, no full reloads), **Livewire form components** for
create/edit/inline‑edit (real‑time validation that *reuses our existing FormRequests + Services*),
and **Laravel Reverb + Echo** for the handful of genuinely live surfaces (check‑in queue, occupancy
board, notifications bell, dashboard tiles). Keep our hand‑built `tb-*` design system but formalise
it into a **Blade + Livewire component library** so every module looks and behaves identically.
The **Sanctum API stays** as the surface for mobile/integrations; **both** the web (Livewire) and the
API (controllers) call the **same domain Services + Policies** we already built — so there is *one*
source of truth and zero logic duplication. This is incremental and non‑breaking: we convert one
module at a time; nothing we already shipped is thrown away.

> **Why not a Vue/React SPA that consumes our API?** It would discard ~40 working Blade views, add a
> Node/SSR pipeline for SEO, and split business rules across two runtimes. Livewire gives ~95 % of the
> SPA feel for ~20 % of the cost on a PHP‑first codebase like ours. Full analysis in §3 and Appendix A.

---

## 1. Goals & guiding principles

**Goals (what "robust + SPA‑like" means here):**

1. **No full‑page reloads** for navigation, form submits, table paging/sorting/filtering, tab
   switches, or modal opens.
2. **Every module consistent** — the same table, the same form, the same modal, the same toast, the
   same loading/empty/error states, everywhere.
3. **Real‑time where it matters** — the queue, occupancy board, and notification bell update
   themselves; nothing stale on screen.
4. **One source of truth** — business rules live in Services/Policies/FormRequests (already built);
   the UI never re‑implements them.
5. **Perfect, calm UI/UX** — fast, obvious, accessible, responsive, dark‑mode‑aware, with motion that
   informs rather than decorates.
6. **Robustness** — optimistic where safe, pessimistic where money is involved; graceful failure;
   loading skeletons; retry; validation inline; audit‑friendly.

**Principles (non‑negotiable):**

- **Incremental & non‑breaking.** The app keeps working during the migration; we convert module by
  module behind the existing routes.
- **Reuse the domain core.** `PatientService`, `BillingService`, `AppointmentService`,
  every Policy and FormRequest — untouched. Livewire components are thin, exactly like our
  controllers are thin.
- **Tenancy & RBAC unchanged.** `ResolveHospital` global scope + Policies still gate everything;
  Livewire respects them identically (a Livewire request is a normal authenticated Laravel request).
- **No design regression.** We keep the `tb-*` visual language; we just componentise it.
- **Everything stays tested.** Existing feature tests keep passing; new Livewire tests are added as
  components land.

---

## 2. Current‑state assessment (what we're building on)

| Area | Today | Implication |
|---|---|---|
| Framework | Laravel 12, PHP 8.2 | Livewire 3 fully supported |
| Build tooling | **Vite 7** configured (`vite.config.js`, `npm run dev/build`) | Asset pipeline already exists — Livewire/Alpine bundle cleanly |
| JS | **Alpine 3** (loaded as a vendor file today), **axios**, **Chart.js**; a little `fetch()`‑based AJAX in the admin shell | Alpine ships *inside* Livewire 3 — we consolidate |
| CSS | Hand‑built **`td-admin.css`** with CSS custom properties and `tb-*` classes; marketing uses inline `<style>`; Tailwind is installed but under‑used in the admin | We have a real design system already — formalise, don't replace |
| API | **Sanctum** v1 (auth, patients, appointments, visits, read resources) + OpenAPI | Stays as the external surface; not the web driver |
| Domain | Thin controllers → **Services + Policies + FormRequests + enums/state‑machines**; append‑only ledgers; bcmath money; ~283 tests | The hard part is done and is *exactly* what Livewire wants to sit on top of |
| Patterns | Every module already has index / create / edit / show Blade views with consistent `tb-*` markup | Uniform starting point → uniform conversion |

**Verdict:** the codebase is a near‑ideal candidate for Livewire — a PHP‑first team, a clean service
layer, a consistent Blade UI, and Vite/Alpine/Tailwind already present.

---

## 3. The core decision — how do we get "SPA‑like"?

Four honest options, scored for *our* situation.

| Option | SPA feel | Reuses our Blade | Reuses our Services | JS build complexity | SEO/marketing | Team fit (PHP‑first) | Real‑time | Verdict |
|---|---|---|---|---|---|---|---|---|
| **A. Livewire 3** (+ Alpine, `wire:navigate`) | High | ✅ evolve in place | ✅ direct | Low (no SPA router, no API client) | ✅ server‑rendered | ✅✅ | ✅ Reverb/Echo/polling | **Recommended** |
| B. Inertia 2 + Vue/React | Very high | ❌ rewrite to components | ✅ (monolith) | Medium (Vite + framework, + Node SSR for SEO) | ⚠️ needs SSR | ⚠️ needs JS skill | ✅ Echo | Overkill for us |
| C. Decoupled Vue/React SPA on our **Sanctum API** | Very high | ❌ discard Blade | ⚠️ via HTTP only | High (SPA + token/session, CORS) | ❌ CSR only | ❌ | ✅ | Highest cost, worst fit |
| D. htmx / Hotwire‑Turbo (HTML‑over‑the‑wire) | Medium‑High | ✅ | ✅ | Very low | ✅ | ✅ | ⚠️ Turbo Streams | Good, but less capable than Livewire for rich stateful screens |

**Why Livewire wins for us (grounded in current guidance):**

- *"Livewire's navigation features allow for smooth page transitions without full reloads, mimicking a
  true SPA experience,"* and *"Livewire renders actual HTML on the server, making it excellent for SEO
  out of the box."* For a back‑ender who wants to *"continue writing back‑end Laravel code, creating
  PHP classes and Blade files,"* Livewire *"doesn't take you outside the comfort zone of Laravel"* and
  *"enables more team members to contribute."* ([Laravel News](https://laravel-news.com/livewire-inertia),
  [OneCodeSoft](https://onecodesoft.com/blogs/livewire-3-vs-inertia-20-best-laravel-stack-for-2025),
  [ScalablePath](https://www.scalablepath.com/php/livewire-vs-inertia))
- *"For internal tools, admin dashboards, customer portals, and many B2B SaaS interfaces, Livewire +
  Volt is the fastest way to ship interactive UI … much more capable than it was in 2022."* — which is
  precisely what an HMS back‑office is. ([Techglock, mid‑2026](https://techglock.com/blog/laravel-in-mid-2026-volt-reverb-pulse-pennant-and-the-new-starter-kit-story))
- The **one caveat** — *"if your app requires heavy client‑side interactions (drag‑and‑drop, rich text
  editors, complex maps), React/Vue libraries still outperform Livewire's DOM‑diffing approach"*
  ([LogRocket](https://blog.logrocket.com/livewire-vs-inertia-js/)). Our HMS is form/table/workflow
  heavy, not canvas/drag‑drop heavy, so this doesn't bite us. Where we *do* hit it (e.g. a future
  **timetable drag‑drop calendar**), we drop an Alpine/JS island (FullCalendar) into a Livewire shell —
  best of both. Islands are cheap and local.

**On "use the backend API to power everything":** there are two readings, and the robust one is
architectural, not literal.

- *Literal:* the web app makes HTTP calls to our own `/api/v1/*`. **We recommend against this** — it
  adds a network hop, JSON (de)serialisation, and a second auth path for *in‑process* work, and it
  splits validation/authorisation across layers. It's the right pattern only for *separate* clients.
- *Architectural (recommended):* **everything is powered by the same backend core** —
  `Services + Policies + FormRequests`. Livewire components call that core directly (in‑process); the
  Sanctum API controllers call the *same* core over HTTP for mobile/integrations. One brain, two
  mouths. This is already how we built the API (C13 — "the two surfaces can't drift"); we simply add
  Livewire as the second, richer mouth for the web.

```
                       ┌─────────────────────────────────────────┐
   Web browser ──────▶ │  Livewire components (thin)             │──┐
   (SPA feel via       └─────────────────────────────────────────┘  │
    wire:navigate)                                                   ▼
                       ┌─────────────────────────────────────────┐  Domain core
   Mobile / partner ─▶ │  Sanctum API controllers (thin)         │──▶  Services · Policies
   (Bearer tokens)     └─────────────────────────────────────────┘  FormRequests · Enums
                                                                     State machines · Ledgers
```

---

## 4. Recommended stack

| Layer | Choice | Why | Alternative considered |
|---|---|---|---|
| Reactivity | **Livewire 3** | server‑driven, reuses Services, no API client, SEO‑safe | Inertia+Vue (bigger rework) |
| SPA navigation | **`wire:navigate`** (built‑in) | instant transitions, prefetch on hover, preserves scroll, progress bar | Turbo Drive |
| Local interactivity | **Alpine 3** (ships with Livewire) | dropdowns, tabs, toggles, menus — no server round‑trip | vanilla JS |
| Data tables | **Livewire PowerGrid v5** | search, sort, multi‑filter, column filters, bulk actions, inline edit, **CSV/Excel/PDF export** out of the box — matches our reporting/export needs | `rappasoft/laravel-livewire-tables` (simpler, larger install base) |
| Component/design kit | **Keep `tb-*`, componentise as Blade components**; evaluate **Flux UI** for net‑new complex widgets | preserves our look, zero visual regression; Flux (official Livewire kit) is Tailwind‑4 and would be a larger reskin | Flux‑everything (bigger visual change) |
| Real‑time | **Laravel Reverb + Echo** (+ `pusher-js` protocol) | first‑party WS server, Redis‑scalable, the 2026 default; Livewire integrates via `#[On('echo:…')]` | polling fallback (built into Livewire) |
| Charts | **ApexCharts** (or keep **Chart.js**) via a small Alpine wrapper | richer dashboards; both are fine | Chart.js (already loaded) |
| File uploads | **Livewire file uploads** (built‑in) | progress bars, temporary storage, validation reuse | Dropzone island |
| Build | **Vite 7** (already present) + **Tailwind** (already present) | one pipeline for Livewire/Alpine/CSS | — |
| Single‑file components (optional) | **Livewire Volt** | fewer files for simple components | class components (default) |

*Version note:* our `package.json` currently mixes **Tailwind 3.1** with **@tailwindcss/vite 4** —
we should pick **one** (recommend Tailwind 4 to keep the door open for Flux and modern tooling) as a
small, isolated pre‑work item (§10 Phase 0).

Findings behind these picks:
[PowerGrid v5](https://livewire-powergrid.com/) ("advanced datatables … global search, column data
filters and data export tools"); [rappasoft tables](https://github.com/rappasoft/laravel-livewire-tables)
(established, ~2.9 M downloads, Alpine‑3 based); [Flux — official Livewire UI kit, Tailwind CSS](https://fluxui.dev/)
([GitHub](https://github.com/livewire/flux)); [Reverb is the first‑party, Redis‑scalable WS default in
mid‑2026](https://techglock.com/blog/laravel-in-mid-2026-volt-reverb-pulse-pennant-and-the-new-starter-kit-story).

---

## 5. Interaction patterns (the "how every screen behaves" spec)

These become the house style — implemented once as base components, reused everywhere.

### 5.1 SPA navigation
- Add `wire:navigate` to all sidebar/nav/table‑row/breadcrumb links. Result: click → **no white
  flash**, top progress bar, scroll preserved, `<head>` assets persisted.
- Prefetch on hover (`wire:navigate.hover`) for the common jumps (dashboard → patients → show).
- The persistent shell (sidebar, top bar, toasts host, notification bell) lives in a **layout that is
  not re‑rendered** between navigations (`@persist`), so it never flickers.

### 5.2 Data tables (every index page)
One `PowerGrid` component per resource. Standard capabilities on *every* table:
- Debounced **search**, **column sort**, **multi‑filter** (status, date range, doctor, etc.),
  **per‑page** selector, **deep‑linkable** state (filters in the URL via `#[Url]`).
- **Row click → `wire:navigate` to show**; **row actions** (edit/print/cancel) gated by Policy.
- **Bulk actions** (e.g. mark appointments no‑show) with a confirm modal.
- **Export** the *current filtered view* to CSV/Excel/PDF (reuses our data; respects tenancy).
- **Empty state**, **loading skeleton**, **error toast** — standard slots.
- Server‑side everything → scales to large tenants; only the visible page is sent.

### 5.3 Forms (create / edit / inline)
- **Livewire form objects** hold the fields; **validation reuses our FormRequest rules** (extract the
  `rules()` into a shared method or a Livewire `Form` that references the same array) so web and API
  validate identically.
- **Real‑time / on‑blur validation** (`wire:model.blur`) — errors appear inline as you go.
- **Submit calls the Service** (`PatientService::register`, etc.) — same code path as the controller;
  domain exceptions (`SlotUnavailable`, `InsufficientFunds`, `PlanLimitExceeded`) map to inline
  errors/toasts, never a 500.
- Presented as a **slide‑over** (create/edit) or full page for long forms — consistent everywhere.
- **Dirty‑guard**: warn before navigating away from an unsaved form.
- **File uploads** with progress and preview (patient photo, treatment photos, documents).

### 5.4 Modals / slide‑overs / drawers
- One base **`<x-modal>`** and **`<x-slideover>`** (Alpine‑driven open/close, focus‑trap, ESC, backdrop,
  scroll‑lock). Confirmations use a shared **`<x-confirm>`** (destructive actions: archive, cancel,
  discharge, void).
- Money/irreversible actions always **pessimistic** (spinner, disabled submit, server‑confirmed) — no
  optimistic UI on financial state.

### 5.5 Toasts, flash & notifications
- A single **toast host** in the shell; Livewire components `dispatch('toast', …)`; success/error/info
  variants; auto‑dismiss + manual close; queue multiple.
- The **notification bell** (Step 19) becomes live via Echo — unread count updates without reload.

### 5.6 Loading / optimistic / empty / error states
- `wire:loading` spinners + **skeleton loaders** on tables/cards.
- **Optimistic UI** only for safe toggles (e.g. mark notification read); **pessimistic** for money.
- Consistent **empty states** (icon + message + primary action) and **error boundaries** (a component
  that failed shows a retry, not a broken page).

### 5.7 Real‑time (Reverb + Echo)
Targeted, not everywhere:
- **Check‑in queue** — new check‑ins/updates stream in.
- **Occupancy board** — bed status flips live as admissions/transfers/discharges happen.
- **Notification bell** — low‑stock / lab‑ready / reminders push instantly.
- **Dashboard tiles** — revenue/occupancy refresh on the relevant domain events.
- Mechanism: domain events → `broadcast()` on a **private, hospital‑scoped channel**
  (`hospital.{id}.…`, authorised so tenants never hear each other) → Livewire `#[On('echo-private:…')]`
  refreshes just that component. Fallback: `wire:poll` where a socket is overkill.

### 5.8 Charts
- Dashboard/report tiles rendered with **ApexCharts** fed by our `ReportService` (revenue by day,
  occupancy, service mix, stock valuation). Re‑query on filter change via Livewire; animate on update.

---

## 6. Design system / UI‑UX overhaul (consistency is the product)

Keep the current calm, square, Inter‑based aesthetic; make it a **system**.

- **Tokens** — promote the `td-admin.css` custom properties into a documented token set (colour,
  spacing, radius, shadow, type scale, z‑index, motion). Light + dark already exist; keep both,
  theme‑aware.
- **Component library** (Blade components, some Livewire): `x-button`, `x-badge`, `x-card`, `x-field`
  (label+input+error+hint), `x-select`, `x-table` (or PowerGrid theme), `x-modal`, `x-slideover`,
  `x-confirm`, `x-toast`, `x-empty`, `x-skeleton`, `x-tabs`, `x-pagination`, `x-avatar`, `x-money`,
  `x-status-pill` (drives off our enums' `badge()`), `x-page-header`, `x-stat`. Every module composes
  these — no bespoke markup per screen.
- **Interaction grammar** — one way to do each thing: destructive = red + confirm; primary action =
  top‑right; filters = a filter bar; detail pages = left summary + right sections (already our pattern).
- **Accessibility** — keyboard nav, focus rings, ARIA on modals/menus/toasts, `prefers-reduced-motion`,
  colour contrast AA. (Health software should be usable one‑handed at a busy desk.)
- **Responsiveness** — the sidebar already collapses; extend the mobile‑first pass to tables (card view
  on small screens) and forms.
- **Motion** — 120–160 ms ease transitions for nav, modals, toasts; skeletons for perceived speed.
- **Command palette** (stretch) — `⌘K` to jump to any patient/module (Livewire + Alpine).

---

## 7. Per‑module robustness blueprint

Every module collapses to **three reusable shapes**, so "robust + consistent" is automatic:

| Shape | Built from | Applies to |
|---|---|---|
| **List** | PowerGrid table (search/sort/filter/paginate/export/bulk) | patients, appointments, visits, invoices, stock, lab/radiology orders, claims, admissions, staff, services, plans… |
| **Editor** | Livewire form object (real‑time validation → Service) in a slide‑over/page | every create/edit + inline actions |
| **Detail** | Show page: summary card + live sections (vitals, ledger, timeline) + action bar | patient, visit, invoice, admission, lab/radiology order… |

Module‑specific "robust" wins that fall out of this:

- **Billing / invoices** — live balance, take‑payment slide‑over, gateway status polled, **pessimistic**
  money flows, receipt/print without reload.
- **Pharmacy** — dispensing form validates stock live; **low‑stock toast** to pharmacists via Echo.
- **Scheduling** — booking form checks the slot server‑side before enabling submit; queue is live.
- **Inpatient** — occupancy board live; admit/transfer/discharge as slide‑overs with confirms.
- **Visits** — the pipeline advances inline; vitals/diagnosis save without leaving the page.
- **Reports** — filter → charts + tables refresh in place; export the current view.
- **Timetable (future)** — a Livewire shell hosting a FullCalendar island for drag‑drop allocation
  (this is the one place we deliberately use a JS island).

---

## 8. Package list (what we'd add)

| Package | Type | Purpose | Risk / note |
|---|---|---|---|
| `livewire/livewire` ^3 | composer | core reactivity | low — mature, first‑party |
| `power-components/livewire-powergrid` ^5 | composer | data tables + export | low — active, well‑docd |
| `laravel/reverb` ^1 | composer | WebSocket server | low — first‑party; needs a process in prod |
| `laravel/echo` + `pusher-js` | npm | client for Reverb | low |
| `apexcharts` (+ tiny Alpine wrapper) | npm | charts | low; or keep Chart.js |
| `livewire/volt` ^1 (optional) | composer | single‑file components | optional |
| `livewire/flux` (optional, evaluate) | composer/npm | official UI kit (Tailwind 4) | medium — visual reskin; adopt selectively |
| *(dev)* `laravel/dusk` (optional) | composer | browser E2E for critical flows | optional |

Everything else (Sanctum, bcmath, DomPDF, endroid QR, Spatie perms/activitylog) stays exactly as is.

---

## 9. Migration strategy (incremental, non‑breaking, always green)

**Phase 0 — Foundation (1 short iteration).** Add Livewire; move Alpine to Livewire's bundle; settle
Tailwind on one major version; build the **component library** (§6) + the **base patterns** (table,
form, modal, toast, skeleton, empty). Turn on `wire:navigate` in the shell. *No module logic changes —
the app looks identical but navigates like an SPA.* Ship.

**Phase 1 — Reference module (Patients).** Convert Patients end‑to‑end as the **blueprint**: PowerGrid
index, slide‑over create/edit (reusing `PatientRequest` rules + `PatientService`), live show sections.
Write Livewire tests. This proves the pattern and becomes the copy‑paste template. Ship.

**Phase 2 — High‑traffic clinical (Appointments, Visits, Queue).** Convert; add **Reverb** for
the live queue. Ship.

**Phase 3 — Money (Billing/Invoices, Payments, Insurance, Subscription).** Convert with strict
pessimistic patterns; gateway status live. Ship.

**Phase 4 — Inventory & diagnostics (Pharmacy, Lab, Radiology, Stock alerts).** Live low‑stock. Ship.

**Phase 5 — Inpatient (Wards/Beds/Admissions/Nursing).** Live occupancy board. Ship.

**Phase 6 — Org/admin/reports/settings** + dashboards with charts + command palette + polish. Ship.

**Coexistence rule:** during migration, un‑converted modules keep their current Blade controllers and
routes. A converted module simply swaps its route target from `Controller@index` to a Livewire
component at the *same URL*. Users never see a half‑built app; tests stay green throughout.

---

## 10. Testing strategy

- **Keep** all existing feature tests (they exercise Services/Policies — the part that must never
  regress). Controller‑based tests for a converted module are replaced by **`Livewire::test()`**
  component tests (assert rendered rows, filter behaviour, validation errors, authorization, emitted
  events) — these are fast and precise.
- **Service tests unchanged** (the money/state‑machine/ledger suites are the safety net).
- **Reverb/broadcast**: assert events are dispatched (`Event::fake`) rather than hitting a live socket.
- **Optional Dusk** smoke tests for the 3–4 critical journeys (register→pay, book→check‑in→consult,
  invoice→pay, admit→discharge).
- CI gate is unchanged: Pint + PHPStan + `migrate:fresh --seed` + full test run.

---

## 11. Performance & scalability

- **Server‑side pagination/filtering** everywhere → only the visible page crosses the wire.
- **`wire:navigate`** persists assets and the shell → transitions are near‑instant; hover‑prefetch hides
  latency.
- **Deferred/lazy components** (`#[Lazy]`) for heavy show‑page sections (ledger, history) → fast first
  paint, sections stream in with skeletons.
- **Debounce** search/filters; **`wire:key`** correctness on loops; avoid N+1 with eager loads (we
  already do).
- **Reverb** scales horizontally via Redis pub/sub (tens of thousands of connections/instance) —
  private hospital channels keep fan‑out small.
- **Caching** for read‑heavy report tiles (short TTL) behind `ReportService`.

---

## 12. Security

- Livewire requests are **normal authenticated Laravel requests** → `ResolveHospital` scope + Policies
  apply unchanged; **authorize in every component** (`$this->authorize(...)`) exactly as controllers do.
- **CSRF** handled by Livewire automatically; **property tampering** guarded (server re‑authorizes on
  every action; never trust a client‑set id — bind by route/model + Policy).
- **Broadcast auth** on private `hospital.{id}` channels so tenants can't subscribe to each other.
- **File uploads** validated (mime/size) as today; stored on the private disk; streamed behind Policy.
- **Rate limiting** on Livewire endpoints (and the existing API throttle) preserved.
- Money/irreversible flows stay **pessimistic + server‑confirmed** (no optimistic financial UI).

---

## 13. Risks & mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| "Big‑bang" temptation → destabilise a working app | med | strict **module‑by‑module**, coexistence rule, ship each phase |
| Tailwind 3↔4 mismatch causes styling drift | med | Phase 0 pins one version in isolation, visual‑diff the shell first |
| Livewire round‑trips feel laggy on slow links | low | prefetch, skeletons, `wire:loading`, deferred sections; islands for the rare heavy‑interactive screen |
| Reverb ops overhead in prod | low | it's first‑party + documented; start with polling, switch on Reverb per‑surface |
| Duplicated validation drifts (web vs API) | low | **share the `rules()` array** between FormRequest and Livewire Form |
| Team ramp on Livewire | low | PHP‑first; the reference module (Phase 1) is the training artifact |
| PowerGrid lock‑in | low | tables are thin config; `rappasoft` is a drop‑in alternative if needed |

---

## 14. Effort & rollout (rough sizing, not a commitment)

| Phase | Content | Rough size |
|---|---|---|
| 0 | Foundation: Livewire + component library + `wire:navigate` shell + Tailwind settle | S–M |
| 1 | Patients reference module + Livewire test pattern | M |
| 2 | Appointments + Visits + live queue (Reverb) | M–L |
| 3 | Billing/Invoices/Payments/Insurance/Subscription (pessimistic) | M–L |
| 4 | Pharmacy + Lab + Radiology + live low‑stock | M |
| 5 | Inpatient + live occupancy | M |
| 6 | Org/reports/dashboards/settings + charts + polish + command palette | M |

Each phase is independently shippable and leaves the app fully working and fully tested.

---

## 15. Reference implementation sketch — the Patients module (Phase 1)

*(illustrative — the exact shape we'd template from)*

- **`App\Livewire\Patients\Index`** → renders a PowerGrid table: columns (no., name, sex, age, phone,
  status pill), search, status filter, per‑page, export; `authorize('viewAny', Patient::class)`; rows
  `wire:navigate` to show; "Register" opens the create slide‑over.
- **`App\Livewire\Patients\Form`** (a Livewire `Form` object) → fields mirror `PatientRequest`;
  `rules()` pulled from the *same array*; `save()` calls `PatientService::register/update`; catches
  `PlanLimitExceededException` → inline error; emits `toast` + closes slide‑over + refreshes the table.
- **`App\Livewire\Patients\Show`** → summary card + **lazy** sections (cards/ledger, documents,
  dependents, treatments, insurance) each a small component with its own skeleton; action bar
  (edit/print ID card/archive) gated by Policy; `@persist` shell.
- **Tests** — `Livewire::test(Index::class)` (filter, RBAC, tenant scope), `Form` (validation, service
  call, plan‑limit error), `Show` (renders, cross‑tenant 404).

Same three shapes then stamp out every other module.

---

## 16. Open questions for sign‑off

1. **Tailwind**: settle on **v4** (unlocks Flux) or stay **v3** (least change)? *(Recommend v4 in Phase 0.)*
2. **Design kit**: keep our `tb-*` system and componentise (recommended, zero visual regression), or
   adopt **Flux** for a more "designed" refresh (bigger reskin)?
3. **Tables**: **PowerGrid** (export‑rich, recommended) vs `rappasoft` (simpler)?
4. **Real‑time**: switch on **Reverb** in Phase 2, or start with polling and add Reverb later?
5. **Charts**: **ApexCharts** (richer) or keep **Chart.js** (already loaded)?
6. Rollout order — is Patients → Clinical → Money → Inventory → IPD → Reports the right priority?

---

## Appendix A — Why not a decoupled Vue/React SPA on the API?

It's the most expensive option with the worst fit here: it **discards ~40 working Blade views**,
introduces a **second runtime** for business logic (or forces everything through HTTP with extra
latency/serialisation), needs **Node SSR** for any SEO, adds **token/session + CORS** complexity, and
splits the team into front/back silos. Our HMS is **form/table/workflow‑centric**, exactly where
server‑driven Livewire shines, and we already have the **Services + API** that make future decoupling
*possible* if a specific need (e.g. a React Native app) ever arises. We keep that door open without
paying the cost now.

## Appendix B — Where we *will* use JS islands (and why that's fine)

Livewire's known weak spot is **heavy client‑side interactivity**. We embrace small, local JS islands
exactly there, inside Livewire shells:

- **Timetable / calendar** drag‑drop → FullCalendar island.
- **Rich text** (letters/discharge summaries) → a small editor island.
- **Signature pad**, **barcode/QR scan**, **charts** → islands.

Everything else — the 90 % that is lists, forms, wizards, detail pages, dashboards — is pure Livewire.

---

### Sources
- Livewire vs Inertia (Laravel News): https://laravel-news.com/livewire-inertia
- Livewire 3 vs Inertia 2 (OneCodeSoft): https://onecodesoft.com/blogs/livewire-3-vs-inertia-20-best-laravel-stack-for-2025
- Livewire vs Inertia (ScalablePath): https://www.scalablepath.com/php/livewire-vs-inertia
- Livewire vs Inertia (LogRocket): https://blog.logrocket.com/livewire-vs-inertia-js/
- Laravel in mid‑2026: Volt, Reverb, Pulse (Techglock): https://techglock.com/blog/laravel-in-mid-2026-volt-reverb-pulse-pennant-and-the-new-starter-kit-story
- Livewire PowerGrid v5: https://livewire-powergrid.com/
- rappasoft/laravel-livewire-tables: https://github.com/rappasoft/laravel-livewire-tables
- Flux — official Livewire UI kit: https://fluxui.dev/ · https://github.com/livewire/flux
