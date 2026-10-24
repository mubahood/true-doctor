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
`ApiResponse::paginated()`; the delta lists also carry `server_time` and `next_since`, and `per_page` is capped at 100
([`api/plane.md`](api/plane.md)). Errors may add `retryable` and `retry_after`. Errors are rendered into the envelope centrally in `bootstrap/app.php`
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
  `?q=` matches name, patient number, phone and the **old file number** (`old_file_no` on the
  resource: the number from the paper file or the old system, kept by Settings, Import data).
  A patient may be sent with an **age** instead of `dob`: `age_years` (0..130) and, for a baby,
  `age_months` (0..23). The server works out the date (1 July of the year that makes the age
  right today, or exactly that far back when months are given) and the resource carries
  `dob_estimated: true`. A `dob` sent outright is exact (`dob_estimated: false`). The same two
  fields work on `POST /visits/intake`. Editing with an unchanged age keeps the stored date.

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
  (BMI computed; a reading not sent is left as it is), `POST {uuid}/clinical` (diagnosis — `diagnose` ability), `POST {uuid}/transition`.
  `POST {uuid}/delete` (`visits.delete`, body `reason` 3..255): deletes a visit opened by mistake
  (`VisitService::delete`, docs/visits.md "Deleting a visit"); returns `{ uuid, deleted: true }`.
  Refused with **422** and the reason in plain words while money paid on it is held ("Refund it
  first"), or for an admission, a credit note or an insurance claim. The visit leaves every list
  (soft-deleted; a tombstone reaches the phone through sync).
  Each visit carries `vital_signs`, the readings the hospital records (`VisitForm`); a dental
  clinic fills `reason` from the complaint and takes `dental_notes` (docs/api/dental.md, "Simple notes").
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
- **Orders on a visit** (`visits.view` to read, `visits.create`/`visits.manage` to write): place,
  move, cancel, report, lines, and `PATCH /api/v1/orders/{uuid}` to change one after it is placed;
  `GET /api/v1/orders/assignees` lists who it can be assigned to. Rules and fields: `docs/orders.md`
  ("Changing an order"). `meta.orders` carries the kinds this practice is offered and the form's labels.
  A product line may be given without billing: `POST /api/v1/orders/{uuid}/items` takes optional
  `billed` (boolean, product only; left out, the hospital's choice), each item carries `billed`
  and `billing_set_at`, and `PUT /api/v1/orders/{uuid}/items/{id}/billed` `{billed}` switches one
  (422 once the visit is invoiced). See `docs/orders.md` ("Billed or not billed").
  A line is corrected in place with `PATCH /api/v1/orders/{uuid}/items/{id}`, and the bill at the
  counter (Add charge, the discount, putting it back in step) is in [`api/orders-and-bill.md`](api/orders-and-bill.md).
- **Invoices** (`billing.view`): `GET /api/v1/invoices` (`?status=`), `GET {uuid}` — full money
  breakdown + line items.

All tenant-scoped and paginated. `ReadResourcesApiTest` covers the low-stock filter, RBAC,
invoice money fields, and cross-hospital 404/empty.

## Surface so far

`auth/*`, `patients`, `appointments` (+transition), `visits` (+vitals/clinical/transition),
`stock-items`, `lab-orders`, `invoices`. More write resources can follow the same
service+policy+resource pattern as needed.

## More areas: `docs/api/*.md`

The surface beyond the first resources is documented per area, next to the routes in `routes/api/*.php`:
[`api/care.md`](api/care.md), [`api/pharmacy.md`](api/pharmacy.md), [`api/finance.md`](api/finance.md),
[`api/admin.md`](api/admin.md) and [`api/extras.md`](api/extras.md), [`api/patients.md`](api/patients.md) (the patient page as data, `GET /api/v1/patients/{uuid}/summary`) (suggestions at `GET /api/v1/insights`,
public self-registration for the app, and starter-data imports). They share this envelope, auth and error model.
Writes are safe to retry with an `Idempotency-Key` header: [`api/idempotency.md`](api/idempotency.md).
Refunds and credit notes (ask, approve, reject, PDF): [`api/refunds.md`](api/refunds.md).
Reports and the monthly reports (income, expenses, receivables, patient records; summary or detail; PDF, Excel, CSV): [`api/reports.md`](api/reports.md).
Notifications, devices, notification preferences and upcoming reminders: [`api/notify.md`](api/notify.md).
Suppliers, purchase orders, goods received and returns to supplier: [`api/procurement.md`](api/procurement.md).
The dosage on a visit (medicine from stock, dose, how often, days; `GET`/`POST`/`DELETE visits/{uuid}/dosage`): [`api/dosage.md`](api/dosage.md).
The app's "Try the demo" door (`GET /public/demo`, `POST /public/demo/login`, `meta.demo`; SaaS only): [`api/demo.md`](api/demo.md).
The owner's daily summary (`GET`/`PUT /owner-summary`, hospital administrator only): [`api/owner-summary.md`](api/owner-summary.md).
The data plane - `GET /bootstrap`, `meta.capabilities`, `GET /search`, ETag/`304`, `?updated_since=`, tombstones (`removed`) and the
extended error codes (`conflict`, `plan_limit`, `payload_too_large`, `device_revoked`, `licence_expired`, `maintenance`,
`retryable`/`retry_after`): [`api/plane.md`](api/plane.md).
The journeys (next actions, agenda, invoice/receipt share links, receipt as data, cashier day, debtors, dispense queue, last prescription,
ward doses due and handover, duplicate check, and the Idempotency-Key rule on money endpoints): [`api/journeys.md`](api/journeys.md).

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
- `GET /api/v1/meta` — (plus `capabilities`, see [`api/plane.md`](api/plane.md)) what the app draws the system from, never copied into its code:
  `api_version`, `sync_protocol`, `server_time`, `web_url`; `user` (+ `role_label`, `permissions`);
  `hospital` (name, logo URL, timezone, `money` {code, symbol, position, decimals, separators},
  `tax`); `subscription` (status, plan, grants_access, days_remaining, badge); `setup_required`;
  `navigation` — the web sidebar for this user, from the same `App\Support\Navigation` definition
  (each destination named by its web route, with `web_url`); `enums` — every status/option list in
  `app/Enums` with the web's label and badge tone (`success|info|warn|danger|neutral`).

Tests: `AccountStateApiTest`, `MetaApiTest` (the menu is asserted equal to the web sidebar for
each role).
