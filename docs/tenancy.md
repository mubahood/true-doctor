# Multi-tenancy

Built in Phase 0 Step 3 (HMS_PLAN.md §2.1, §7). Single enforced tenancy key, per
constraint B8 — the legacy audit's cautionary tale was two competing tenancy keys
and no global scope. Here there is exactly one mechanism.

## The pieces

- **`hospitals`** — the tenant. `App\Models\Hospital`: uuid + slug auto-generated on
  create, `status` (`App\Enums\HospitalStatus`), `settings` JSON, soft-deletes.
- **`plans`** — the SaaS catalogue. `App\Models\Plan`: `price` (`decimal(12,2)`),
  `billing_cycle` (`App\Enums\BillingCycle`), `limits` JSON (max_users, max_patients,
  modules — read via `Plan::limit($key)`), not yet enforced (Phase 4 §21 feature gating).
- **`subscriptions`** — links a hospital to a plan for a period. `App\Models\Subscription`:
  `status` (`App\Enums\SubscriptionStatus`: Trialing/Active/Expired/Cancelled),
  `starts_at`/`ends_at`/`trial_ends_at`. `subscription_payments` (recording manual/
  mobile-money payments) is Phase 0 Step 5 scope, not built yet.
- **`users.hospital_id`** — nullable. `null` = super-admin (SaaS central, not scoped
  to a hospital). Non-null = hospital staff, scoped to exactly that hospital.

## The mechanism

`App\Support\CurrentHospital` is a container singleton (bound in `AppServiceProvider`)
holding the resolved tenant id for this request/process — nothing else may read or
set tenant context directly.

`App\Http\Middleware\ResolveHospital` (global, appended to the `web` and `api`
middleware groups) sets it on every request:

- Hospital-scoped user → their `hospital_id`.
- Super-admin (`hospital_id` null) with no switch → `null` (unscoped — sees every
  hospital; this is the deliberate default for SaaS-central screens).
- Super-admin who has switched into a hospital (session `viewing_hospital_id`,
  set by the super-admin panel — Phase 0 Step 5 builds the switch UI) → that
  hospital's id.
- No authenticated user (guest, or a console/queue/seeder context with no HTTP
  request) → `null`, same as an unswitched super-admin.

`App\Models\Concerns\BelongsToHospital` — applied to every tenant-scoped model,
starting with real HMS modules in Phase 1 (Patient, Appointment, …) and already
applied to `Subscription` in this step (a subscription belongs to exactly one
hospital; scoping it is correct defense-in-depth for a future hospital-facing
billing view, and a no-op for super-admin's default unscoped view):

- Registers `App\Models\Scopes\HospitalScope` as a global scope: when
  `CurrentHospital` resolves to an id, every query is constrained to
  `hospital_id = <that id>`. When it resolves to `null`, the scope is a
  **no-op** — deliberately never `WHERE 1=0`. A restrictive default would be its
  own tenancy bug (mass-hiding data in console/seeder contexts); the isolation
  guarantee comes from the fact that a *scoped* context always filters correctly,
  never from an unscoped context pretending to be empty.
- Auto-fills `hospital_id` on `creating` from `CurrentHospital` if not already
  set — callers never assign it by hand (constraint B8: "never manual per-query
  filtering" extends to writes).

`App\Http\Middleware\EnsureSubscribed` (aliased `subscribed`, applied per tenant
route group starting when real tenant routes ship) blocks the request unless the
hospital's latest subscription both grants access
(`SubscriptionStatus::grantsAccess()`) and is within `ends_at` + the configurable
grace period (`config/tenancy.php`, `SUBSCRIPTION_GRACE_DAYS` env, default 3 days).
No-op when there's no hospital context.

## Isolation tests

`tests/Unit/BelongsToHospitalTest.php` proves the mechanism itself against a
scratch table + fixture model (`tests/Unit/Fixtures/TenancyScopeProbe.php`) — no
real tenant business model exists yet (Phase 1). Proves: reads are scoped;
hospital A cannot read, update, or delete hospital B's row by id even when it
knows the id; `hospital_id` is auto-filled; no-context is unscoped, not hidden.

`tests/Feature/TenancyMiddlewareTest.php` proves `ResolveHospital` and
`EnsureSubscribed` end-to-end against ad hoc test routes.

`tests/Unit/TenancyModelsTest.php` covers `Hospital`/`Subscription` model logic
(uuid/slug generation, `activeSubscription()`, `isCurrentlyActive()`).

**This isolation suite must grow with every tenant-scoped module** — HMS_PLAN.md
§2.1: "Isolation test suite is a deliverable: automated tests assert hospital A
can never read/write hospital B's rows, on every module, via UI and API." Phase 5
§22 is the full-suite gate; this step is the foundation it builds on.
