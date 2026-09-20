# Subscription plan limits (Phase 4, Step 21)

A hospital's subscription **plan** may cap countable resources; creating past the cap is blocked
with a clear "upgrade" message.

## How it works

`Plan.limits` (JSON) may contain `max_staff`, `max_patients`, `max_beds`. `App\Support\PlanLimit`
resolves the current hospital's **active subscription → plan**, reads the limit, counts the
hospital's existing rows, and throws `PlanLimitExceededException` when at/over the cap.

- **No limit key → unlimited** for that resource.
- **No active subscription / no plan (e.g. super-admin) → not enforced.**
- Counts are **per hospital** (User is counted by `hospital_id` since it isn't globally scoped).

## Enforcement points

| Resource | Where | Key |
|---|---|---|
| Patients | `PatientService::register` (covers web, API, and inline intake) | `max_patients` |
| Staff | `UserController@store` | `max_staff` |
| Beds | `BedController@store` | `max_beds` |

Web controllers catch the exception and flash an error (with old input); the API patient
endpoint returns **403** in the envelope.

## Tests

`PlanLimitTest` (below/at/limit, no-key = unlimited, no-subscription = no enforcement,
per-hospital); `PlanLimitApiTest` (API create blocked at cap with 403, unlimited without a plan).
