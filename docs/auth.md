# Auth hardening

Built in Phase 0 Step 4 (HMS_PLAN.md §3.C, §7). Every item below maps to a specific
legacy-audit finding (§3.C) that this build deliberately does not repeat.

## No default/predictable passwords (C14)

`AdminUserSeeder` generates a random 16-character password (`Str::password(16)`), prints it once
to console output, and sets `users.password_change_required = true`. Re-running the seeder never
touches an existing account's password. `App\Http\Middleware\RequirePasswordChange` (global, `web`
group) redirects any authenticated request to `password.change` while the flag is set — the only
exempt routes are the change form itself, its submit route, and logout. `PasswordController::update`
clears the flag and, when the change was forced, redirects straight to the dashboard instead of
`back()`. Password policy (`Password::defaults()` in `AppServiceProvider`): minimum 8 characters,
letters + numbers required.

## How long a session lasts, and the two doors

**Ninety days, by default.** `App\Support\StaffSession::REMEMBER_DAYS` is applied to the web
guard immediately before `Auth::attempt` (not at boot — resolving the session guard during boot
needs a session that a console command or queued job does not have). Laravel's own default is
five years; the hospital asked for a quarter.

The checkbox is **drawn ticked**, which creates a problem worth naming: an unticked checkbox
posts *nothing*, so "the user unticked it" and "this client never drew one" are the same request.
`<x-auth.remember>` posts a hidden `remember_present` companion, and `LoginRequest::remembers()`
treats its absence as "no opinion → the default the screen promises" and its presence without
`remember` as a real "this shift only". Without the companion field, unticking the box would
have done nothing.

This lengthens a session; it does not weaken the check. The remember cookie is Laravel's —
random, tied to one user, HttpOnly, and cycled by `logout()` the moment anybody signs out of
that account anywhere.

**Two doors, one lock.** `/admin/login` is the hospital's; `/test-login` is the demonstration's
and 404s outside local/demo. Both POST to the same action, so the rate limiter, the
deactivated-account check and the staff-only check cannot drift apart. Both rejections in
`AuthenticatedSessionController::store` tear the session down before throwing: the credentials
were right, so a session exists by then, and a half-signed-in deactivated account is worse than
none. `session()->regenerate()` runs *after* both checks, so a rejected attempt never gets a
fresh session id.

The GET routes carry `guest`, redirecting anyone already signed in to their dashboard
(`RedirectIfAuthenticated::redirectUsing` in `AppServiceProvider` — without it they go to
Laravel's default `/dashboard`, which this application does not have). The POSTs stay open so a
session that expired between rendering a form and submitting it still gets a proper answer.

## Exactly one API auth mechanism (C16)

Sanctum only — no JWT or other package is installed. `config/sanctum.php` is stock. No API
resources exist yet (Phase 1+); when they do, they authenticate via `auth:sanctum` only.

## CORS locked to known origins (C15)

No `config/cors.php` existed before this step, meaning the app silently ran on the framework's
own default (`allowed_origins => ['*']`) — the exact anti-pattern this constraint bans. Now
published explicitly: `allowed_origins` is built from the `CORS_ALLOWED_ORIGINS` env var
(comma-separated exact origins) and defaults to **an empty array — no origins allowed** until
it's set. `supports_credentials` defaults to `false`. No substring/pattern matching is used for
route exemptions anywhere in this app (`RequirePasswordChange`'s exempt list is exact route
names, matching the same discipline the legacy `JwtMiddleware` violated with substring matching
on "login"/"otp"/"register").

## Rate limiting on every API route (C-series)

Laravel's slimmed-down skeleton leaves the `api` middleware group **unthrottled unless asked** —
confirmed by reading `Illuminate\Foundation\Configuration\Middleware`: `apiLimiter` defaults to
`null`. `bootstrap/app.php` now calls `$middleware->throttleApi()`, and `AppServiceProvider` boots
a matching `RateLimiter::for('api', …)` (60/min, keyed by authenticated user id when signed in via
Sanctum, else by IP). The staff login form already had per-attempt rate limiting from Breeze
(`LoginRequest::ensureIsNotRateLimited()`, 5 attempts) — unchanged, already correct.

## Standardized response envelope + exception handling (§3.D)

`App\Support\ApiResponse::success()`/`error()` — every API response, success or error, has the
same shape: `{success, code, message, data, errors}`. `code` is a stable machine-readable
`App\Enums\ApiErrorCode` value; clients branch on `code`, never on `message`.

`bootstrap/app.php`'s `withExceptions()` renders this envelope for every exception on a request
that either targets `api/*` or explicitly asks for JSON (`Accept: application/json`) — validation
errors (422, with field errors), auth/authorization failures (401/403), not-found (404), and
anything else carrying an HTTP status (429 throttled, `abort(403, …)` as `EnsureSubscribed` uses).
Unhandled exceptions return 500 with the real message only when `app.debug` is on. A plain web
request that doesn't ask for JSON still gets Laravel's normal HTML error pages — the envelope
never leaks onto `/admin/*`.

## Deferred to later steps/phases

- Per-role API endpoint authorization via Policies (constraint C13) — ships with each resource's
  Policy as real API endpoints are built (Phase 1+), not before there's anything to authorize.
- 2FA for admin roles (§3.C) — Phase 5 §22 hardening gate.
- Custom business exceptions (`PatientNotFoundException`, …, §3.D) — ship with their respective
  modules; the generic envelope above is what they'll render through.
