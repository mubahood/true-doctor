# API v1 (Phase 5)

A Sanctum-authenticated JSON API over the same domain services and Policies as the admin panel,
so the two surfaces can't drift (C13). One auth mechanism only (Sanctum, C16); one response
envelope everywhere (`App\Support\ApiResponse`); tenancy by the global scope.

This is **Step 21a** — auth + the patients resource, establishing the pattern the rest follow.

## Envelope

Every response — success or error — is:

```json
{ "success": bool, "code": "ok|validation_failed|forbidden|...", "message": string|null,
  "data": ..., "errors": object|null }
```

Lists add a `meta` block (`current_page`, `per_page`, `total`, `last_page`) via
`ApiResponse::paginated()`. Errors are rendered into the envelope centrally in `bootstrap/app.php`
(validation → 422 `validation_failed`, auth → 401 `unauthenticated`, policy → 403 `forbidden`,
model-not-found → 404 `not_found`, throttle → 429, else 500). JSON Resources are configured
`withoutWrapping()` so the envelope is the only wrapper.

## Auth

| Method | Route | Notes |
|---|---|---|
| POST | `/api/v1/auth/login` | email + password → `{ token, user, password_change_required }`; rejects bad creds (401) and disabled accounts (403) |
| GET | `/api/v1/auth/me` | current user + permissions (needs token) |
| POST | `/api/v1/auth/logout` | revokes the current personal access token |

`Authorization: Bearer <token>`. The middleware priority list runs `Authenticate` before
`ResolveHospital`, so the tenant is resolved from the **token user** and every query is scoped —
proven by `PatientApiTest::test_index_is_scoped_to_the_token_users_hospital`.

## Resources

- `GET/POST /api/v1/patients`, `GET/PUT/DELETE /api/v1/patients/{uuid}` — reuse `PatientService`
  + `PatientPolicy`. RBAC is identical to the panel (a role without `patients.create` gets 403).

## Tests

`AuthApiTest` (login envelope, bad creds, disabled account, me needs token, logout revokes);
`PatientApiTest` (tenant-scoped index, create, mirrored RBAC 403, cross-hospital 404, validation
envelope).

## Step 21b/c — clinical resources

- **Appointments** (`AppointmentService` + policy): `GET /api/v1/appointments` (date/status/doctor
  filters), `POST` (book), `GET {uuid}`, `POST {uuid}/transition`. Slot conflicts and illegal
  transitions return **422** in the envelope. The resource exposes `next_statuses` from the state
  machine.
- **Visits** (`VisitService` + policy): `GET /api/v1/visits` (status filter),
  `POST` (open — returns the generated `visit_no`), `GET {uuid}`, `POST {uuid}/vitals`
  (BMI computed), `POST {uuid}/clinical` (diagnosis — `diagnose` ability), `POST {uuid}/transition`.
  Each ability is authorized exactly as in the panel (nurse can take vitals but not diagnose).

Pattern for every resource: thin controller → shared Service + Policy → JSON Resource →
`ApiResponse`. Domain exceptions (conflicts, illegal transitions) map to 422; not-found and
cross-tenant map to 404 via the central handler.

## Step 21d — read resources

Read-only endpoints for integration / mobile clients, each gated by the matching view
permission:

- **Stock items** (`pharmacy.view`): `GET /api/v1/stock-items` (`?filter=low|expiring`, `?q=`),
  `GET /api/v1/stock-items/{uuid}` — includes `is_low_stock` / `is_expired`.
- **Lab orders** (`lab.view`): `GET /api/v1/lab-orders` (`?status=`), `GET {uuid}` — includes each
  test's result value + flag.
- **Invoices** (`billing.view`): `GET /api/v1/invoices` (`?status=`), `GET {uuid}` — full money
  breakdown + line items.

All tenant-scoped and paginated. `ReadResourcesApiTest` covers the low-stock filter, RBAC,
invoice money fields, and cross-hospital 404/empty.

## Surface so far

`auth/*`, `patients`, `appointments` (+transition), `visits` (+vitals/clinical/transition),
`stock-items`, `lab-orders`, `invoices`. More write resources can follow the same
service+policy+resource pattern as needed.

## OpenAPI

`GET /api/v1/openapi.json` (public) serves a hand-maintained OpenAPI 3.0 document describing the
auth flow, resources, the bearer security scheme, and the standard response envelope as a
reusable schema. Point Swagger UI / Redoc / a client generator at it. Kept in
`OpenApiController` alongside the routes so it evolves with them.

## Account state and `meta` (for the mobile & desktop app)

Three states of the *account* — not of the request — each with its own code, so a client can
show the right screen instead of a generic error. All enforced by `EnsureApiAccount` (runs after
`auth:sanctum`, before `subscribed`) and `EnsureSubscribed`:

| Code | Status | When | What still works |
|---|---|---|---|
| `account_disabled` | 401 | the user was deactivated after the token was issued; the token is **deleted** | nothing — sign in again |
| `password_change_required` | 403 | the account is on a temporary password | `auth/me`, `meta`, `auth/password`, `auth/logout` |
| `subscription_ended` | 403 | the hospital's subscription has lapsed (grace included) | the same four |

- `POST /api/v1/auth/password` `{current_password, password, password_confirmation}` — the same
  rules and messages as the web page (`PasswordChangeRequest`, at least 6 characters, must differ
  from the current one). Clears `password_change_required` and **revokes every other token** of the
  user.
- `GET /api/v1/meta` — what the app draws the system from, never copied into its code:
  `api_version`, `sync_protocol`, `server_time`, `web_url`; `user` (+ `role_label`, `permissions`);
  `hospital` (name, logo URL, timezone, `money` {code, symbol, position, decimals, separators},
  `tax`); `subscription` (status, plan, grants_access, days_remaining, badge); `setup_required`;
  `navigation` — the web sidebar for this user, from the same `App\Support\Navigation` definition
  (each destination named by its web route, with `web_url`); `enums` — every status/option list in
  `app/Enums` with the web's label and badge tone (`success|info|warn|danger|neutral`).

Tests: `AccountStateApiTest`, `MetaApiTest` (the menu is asserted equal to the web sidebar for
each role).
