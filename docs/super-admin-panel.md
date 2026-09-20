# Super-admin panel & marketing site

Built in Phase 0 Step 5 (HMS_PLAN.md §7, §10). Route convention: `/admin/*` is the
tenant back-office, `/super/*` is SaaS central, `/` is marketing — three distinct
surfaces per §10's conventions recap.

## `/super/*` — SaaS central

Gated by the `super` middleware alias (`App\Http\Middleware\IsSuperAdmin`), which
requires `User::isSuperAdmin()` (`hospital_id === null && role === 'super_admin'`).
A single check suffices here — there's exactly one role that should ever reach this
surface — so no separate Policy classes; matches how `admin` middleware already gates
`/admin/*` by a coarse role check rather than per-route Policies.

- **Hospitals** (`Super\HospitalController`) — create/edit. No hard delete exposed;
  deactivation is `status = suspended` (`App\Enums\HospitalStatus`), consistent with how
  SaaS platforms usually handle this (soft-deletes exist at the model level for the rare
  real cleanup case, done outside this UI).
- **Plans** (`Super\PlanController`) — create/edit, same no-hard-delete reasoning
  (`plans.plan_id` on `subscriptions` is `restrictOnDelete` at the DB level regardless).
  `max_users`/`max_patients` are separate form inputs assembled into the `limits` JSON
  column by the controller — not a raw JSON textarea, for a usable admin UI.
- **Subscriptions** (`Super\SubscriptionController`) — create/edit, plus the "record
  payment" action described below. Feature gating from `limits` (`hospital->hasModule()`)
  isn't built yet — HMS_PLAN.md §2.2 places that in Phase 4 §21.

### Record payment → extend (HMS_PLAN.md §2.2)

The MVP manual/mobile-money billing flow: `Super\SubscriptionController::recordPayment()`
delegates to `App\Services\SubscriptionService::recordPayment()` (constraint A1 — business
logic in a Service, not the controller), which in one DB transaction (constraint F):

1. Creates an append-only `SubscriptionPayment` row (amount, method, reference, notes,
   who recorded it, when it was paid).
2. Extends `ends_at` — from the *current* `ends_at` if it's still in the future (renewing
   early doesn't lose remaining time), otherwise from now (a lapsed subscription's clock
   restarts at the payment date, not some already-past date).
3. Reactivates the subscription (`status = Active`) if it wasn't already granting access —
   so recording a payment against an `Expired` subscription both extends it and lets the
   hospital back in immediately.

`method` is a plain string today (`manual`/`mobile_money`/`bank_transfer` offered in the
UI), not an enum — with no real payment gateway yet (Flutterwave is Phase 4 §16), an enum
would be speculative; it becomes one once real gateway methods exist.

## `/` — marketing

`resources/views/marketing/home.blade.php` + `layouts/marketing.blade.php` — a fresh,
self-contained layout (not a reuse of the retired True-Doctor public site, which moved to
`_legacy/resources/views/public/` and `_legacy/resources/views/layouts/public.blade.php`
in Phase 0 Step 1). Introduces True-Doctor's HMS, previews the full module roadmap, and
links to staff sign-in. No self-signup/trial form — that's explicitly Phase 4 §21 scope
("self-signup + trial, plan limit enforcement, subscription emails").

## Admin shell re-skin

`layouts/admin.blade.php`: sidebar subtitle shows the current hospital's name for
hospital-scoped staff, "SaaS Central" for Super Admin; a "Platform" nav section
(Hospitals/Plans/Subscriptions) is
shown only when `$u->isSuperAdmin()`. The dead search form left over from the registry
domain (silently guarded by `Route::has()` since Phase 0 Step 1, never actually fixed)
is removed outright.
