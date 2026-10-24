# API: administration, settings and account state

Everything here is under `/api/v1/admin/…`, behind `auth:sanctum` + `api.account` + `subscribed`
(see [`../api.md`](../api.md) for the envelope, auth and account-state codes). Each endpoint gives the
**same permission as the web page it mirrors** and calls the same service or validation rules as that
page. Tenancy is by the global scope (another hospital's row is a **404**); user accounts have no
global scope, so `StaffService::manageableBy()` narrows them. No response ever carries a password.
Code: `routes/api/admin.php`, controllers `app/Http/Controllers/Api/V1/*`, tests
`tests/Feature/Api/Admin*ApiTest.php`.

## Conventions

- **Lists** paginate with `ApiResponse::paginated` (`?page=`, `?per_page=` up to 100, default 20; `meta`
  has `current_page, per_page, total, last_page`) and take `?q=` plus the filters listed per endpoint.
- **Updates are partial.** The web form is a whole record, so a `PUT`/`PATCH` starts from the stored
  record and applies the fields sent: leaving `is_active` out never switches something off. To clear a
  nullable field, send it as `null` or `""`.
- **Errors** (envelope `success:false`):

| Code | HTTP | When |
|---|---|---|
| `validation_failed` | 422 | the web's validation rules, or a business rule the web refuses (ward still has beds, occupied bed, own account, bed status). `errors` is keyed by field; rule refusals use the field they concern or `base` |
| `forbidden` | 403 | missing permission; also a **plan limit** reached (`max_staff`, `max_beds`), as the patient API does |
| `not_found` | 404 | unknown row, a row of another hospital, **or an endpoint of the other edition** |
| `subscription_ended` | 403 | lapsed SaaS subscription, for everything except `GET admin/subscription` |
| `password_change_required` | 403 | the caller is on a temporary password |

## Hospital structure

Permissions: departments and rooms are **viewed** with `access-admin` and **changed** with
`departments.manage` / `rooms.manage`; wards and beds are **viewed** with `ipd.view` and **changed**
with `wards.manage`. Deletes are soft (archive). Creating a name that was archived restores the archived
row (HTTP 201, same `id`), as the web does.

### Departments
| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `admin/departments` | access-admin | `?q=` (name/code), `?status=active\|inactive` |
| GET | `admin/departments/{id}` | access-admin | adds `rooms[]` and `staff[]` (first 8) |
| POST | `admin/departments` | departments.manage | `name*` (unique per hospital), `code` (upper-cased, unique), `head_user_id` (same hospital), `description`, `is_active` (default true) |
| PUT/PATCH | `admin/departments/{id}` | departments.manage | partial |
| DELETE | `admin/departments/{id}` | departments.manage | archive |

```json
{ "id": 4, "name": "Cardiology", "code": "CARD", "description": null, "is_active": true,
  "head": {"id": 9, "name": "Dr Able"}, "head_user_id": 9, "rooms_count": 2, "staff_count": 3 }
```

### Rooms
`GET admin/rooms` (`?q=`, `?department_id=`, `?type=`, `?status=`), `GET admin/rooms/options`
(`types`, `statuses`, `departments` for the form pickers), `GET/POST/PUT/DELETE admin/rooms[/{id}]`.
Fields: `name*`, `department_id`, `type*` (RoomType), `status*` (RoomStatus), `capacity` (1-9999, default 1),
`notes`. Resource: `id, name, type, type_label, status, status_label, is_bookable, capacity, notes, department_id, department`.

### Wards
`GET admin/wards` (`?q=`, `?status=active|inactive`; each has `beds_count`), `GET admin/wards/{id}` (adds
`beds[]` and `figures {beds, occupied, free, percent, a_night}`), `GET admin/wards/{id}/reprice-impact`
(`{beds, occupied}`, `wards.manage`), `POST admin/wards`, `PUT/PATCH admin/wards/{id}`, `DELETE`.
Fields: `name*`, `description`, `default_daily_charge*` (0 - 99,999,999.99), `is_active`, and
`apply_to_beds` (bool, form state only): when true the save also sets **every bed** in the ward to the
rate, through `WardService`; the response carries `repriced: {beds, occupied}` and the message warns that
stays in progress are billed at the new rate for every night. `DELETE` is refused (422) while the ward
has beds.

### Beds
`GET admin/beds` (`?q=` bed or ward name, `?ward_id=`, `?status=available|occupied|maintenance`,
`?active=1`), `GET admin/beds/options` (`statuses`, `wards` with `default_daily_charge`),
`GET admin/beds/{id}` (adds `stays[]` last five, `figures {stays, nights, earned}`),
`POST admin/beds`, `PUT/PATCH admin/beds/{id}`, `DELETE admin/beds/{id}`,
`POST admin/beds/{id}/status {status}` (bed status change: `available` or `maintenance` only).
Rules as on the web: a new bed with no `daily_charge` starts at its ward's rate (and defaults to
`available`); the **plan's `max_beds`** is checked on create (403 `forbidden`); an **occupied** bed's
status is ignored on edit, cannot be changed by `/status` (422) and cannot be deleted (422).
```json
{ "id": 12, "name": "B1", "ward_id": 3, "ward": {"id": 3, "name": "Ward A"},
  "daily_charge": "40000.00", "status": "available", "status_label": "Available", "is_active": true }
```

## People

### User accounts (`manage-users`, tenant-narrowed)
| Method | Path | Notes |
|---|---|---|
| GET | `admin/roles` | assignable roles for the picker: `[{key, label, permissions[]}]` (never `super_admin` for a hospital admin) |
| GET | `admin/users` | `?q=` (name/email), `?role=`, `?status=active\|inactive` |
| GET | `admin/users/{id}` | adds `abilities[]` (all permissions), `profile`, `two_factor_enabled`, `can_reset_two_factor` (on, not your own, and you may edit the account), `extras[{key, label}]` (extra abilities) and `can_give_extras` |
| POST | `admin/users` | `name*`, `email*` (unique), `role*` (assignable), `phone`, `bio`, `is_active`, `sees_patients`, `avatar` (image, multipart). A random temporary password is generated and **emailed** (`WelcomeCredentials`); `password_change_required` is true. Response adds `credentials_emailed`. Plan `max_staff` reached: 403 |
| PUT/PATCH/POST | `admin/users/{id}` | same fields, partial; `remove_avatar`; optional `password` + `password_confirmation` (Laravel default rules) sets a temporary password. Use POST with `_method=PUT` for a multipart avatar |
| POST | `admin/users/{id}/activate` , `/deactivate` | switch sign-in on/off; deactivating **yourself** is 422 |
| POST | `admin/users/{id}/reset-password` | `password*`, `password_confirmation*`: an administrator-chosen temporary password; the person must set their own at next sign-in (`password_change_required`). Never echoed |
| DELETE | `admin/users/{id}` | refuses your own account (422) |
| POST | `admin/users/{id}/reset-two-factor` | a colleague lost their phone: turns their two-step sign-in off (`TwoFactorService::resetFor`, logged as "Two-step sign-in turned off by a colleague"). Needs the update policy, so only a hospital admin does this to a hospital admin. Your own account is 422 "Turn your own off on Account security." (that needs your password: `auth/two-factor/disable`, docs/api/security.md); already off is 422 |
| GET | `admin/users/{id}/abilities` | this person's extra abilities (view policy): `{user{id,name,role,role_label}, extras[], can_edit, note, abilities[{key,label,hint,needs[]}]}` |
| PUT | `admin/users/{id}/abilities` | `abilities*` (array, may be empty, each one of the ability keys): makes the extras exactly these (`AccessService::setPersonal`), what they need comes with them. Update policy **and** a hospital admin (else 403 "Only a hospital admin can give extra abilities."); a hospital admin's own account, or any admin's, is 422 "A hospital admin already has every ability." |

`sees_patients` is ignored for doctors (a doctor sees patients by being one). Resource:
`id, name, email, role, role_label, phone, bio, avatar_url, is_active, sees_patients, password_change_required, last_active_at, profile`.

### Staff profiles (view `access-admin`, change `staff.manage`)
`GET admin/staff-profiles` (`?q=` name/specialty/licence, `?department_id=`, `?status=`),
`GET admin/staff-profiles/{id}` (adds `schedule[]` and `upcoming_appointments`), `POST`, `PUT/PATCH`, `DELETE`.
Fields: `user_id*` (same hospital, one profile per user, **immutable after create**), `department_id`,
`job_title`, `specialty`, `license_no`, `qualifications`, `is_active`.

## Offline

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `admin/offline-devices` | manage-users | `?state=active\|revoked\|stale`, `?q=` (label/user); `meta.figures {devices, revoked, stale, conflicts, rejected, failed}` |
| GET | `admin/offline-devices/{uuid}` | manage-users | adds `recent_operations[]` (diagnostics only, never a payload) and `recent_sessions[]` |
| POST | `admin/offline-devices/{uuid}/revoke` | manage-users | `reason*` (5-255 chars; shown on the device). Logged to the activity log. The device's copy is cleared on its next contact |
| POST | `admin/offline-devices/{uuid}/restore` | manage-users | let it back in |
| GET | `admin/offline/readiness` | any signed-in user | `{enabled, available{entity: count}, total, windows{patient_days, visit_days}, devices[]}`: what this user's device would download (scoped by their permissions) and their own machines. The rest of the web page is measured in the browser |

Device: `{uuid, label, platform, user{id,name}, state: active|stale|revoked, registered_at, last_seen_at, last_sync_at, revoked_at, revoked_reason, revoked_by}`.

## Settings (all `manage-settings`)

### Your practice: `admin/settings/practice`
- `GET` → `{chosen, sector, packs[], modules[], modules_customised, addable_packs[], counts{}, preview{part:{label,new,total}}, catalogue{sectors[], packs[], modules[], parts{}}}`.
- `PUT/PATCH` `{sector*, packs[], modules[]}`: `modules` omitted or `null` means "follow the sector"; a list is the admin's own choice. Records the answers only; nothing that exists changes (`PracticeService::choose`).
- The `GET`/`PUT` answer also carries `visit_form` `{vitals[], vitals_title, vitals_customised, shows_bmi, dental_notes, dental_notes_customised, dentistry, asks_reason, suggests, dental_visit_form, dosage, visit_report, visit_report_customised, catalogue{vitals{}, dental_notes{}, visit_report{}}}`. `dosage` true: the visit takes a dosage (docs/api/dosage.md) in place of the prescription form.
- `PUT/PATCH admin/settings/practice/visit-form` `{vitals[]?, dental_notes?, visit_report?}` (`simple` or `detailed`; `summary` or `full`): how a visit is written down and what the printed visit report shows (`PracticeService::chooseVisitForm`). A field left out keeps its value; `null` follows the practice again (a dental clinic: blood pressure only, simple notes and the patient summary). Returns the practice payload.
- `POST admin/settings/practice/apply` `{parts[]}` (any of `departments, services, rooms, wards, lab_tests, radiology, stock, accounts`; default all that apply): sets the hospital up from its saved template, only where something is missing (`PracticeService::apply`). 422 until a practice is chosen. Returns `{created{part:n}, total, …practice}`.

### Hospital letterhead: `admin/settings/hospital`
- `GET` → `{name, address, phone, email, website, registration, footer, logo_url, wordmark_url, brand_colour, tagline}`.
- `POST` (multipart-capable): `name*` (150), `address` (500), `phone` (64), `email`, `website`, `registration`, `footer` (255, shared with the billing invoice footer), `tagline` (120), `brand_colour` (`#RRGGBB`, must be dark enough for white button text, contrast >= 4.5), `logo` and `wordmark` (PNG/JPEG/GIF image files, <= 2 MB). Fields not sent keep their stored value.
- `DELETE admin/settings/hospital/logo` and `…/wordmark`.
Returns the same shape as `GET`.

### Billing: `admin/settings/billing`
- `GET` → `{settings{currency_code, currency_symbol, currency_position, decimals, thousands_separator, decimal_separator, tax_enabled, tax_label, tax_rate, consultation_fee, invoice_prefix, invoice_footer, products_billed}, preview, invoice_example, recorded{invoices, payments, currency}, currencies{}}`.
- `PUT/PATCH`: the `BillingSettingRequest` rules plus "the two separators must differ"; unsent fields keep their value. `products_billed` (boolean) is where a product's Billed or Not billed choice starts on a visit; until it is set, false for a dental clinic and true elsewhere. Response adds `currency_relabelled: true` when invoices/payments already exist in another currency: changing the code **re-labels** recorded money, it does not convert it.

### Staff and access: `admin/access`
The web's Settings, Staff and access (`AccessService`, `Abilities`, `PracticeDefaults`; docs/rbac.md). Read with `manage-settings`; every change needs a **hospital admin** (`AccessService::canManage`, else 403 "Only a hospital admin can change who may do what."). The hospital admin role is never changed (422).

- `GET admin/access` → `{can_edit, locked_note, abilities[{key, label, hint, needs[]}], roles[{key, label, locked, changed, abilities{key: {on, partial, default, changed}}}], people[{id, name, role, role_label, extras[{key, label}]}]}`. `roles[0]` is the hospital admin (`locked: true`, everything on). `on`: the role has all of the ability; `partial`: some of it; `default`: the standard (the matrix plus what the practice starts the role with) gives all of it; `changed`: the clinic made a choice that differs from the standard.
- `PUT admin/access/roles/{role}/abilities/{ability}` `{state: on|off|default}`. `on` brings what it needs, `off` takes what needs it (the web's tick); `default` puts it back to the standard (`AccessService::restoreDefault`; when the standard gives all or none of it, the abilities that depend on it move the same way). Answers the `GET` document plus `moved[]` (the one asked for first) and the web's sentence, for example "See clinical notes, Record vitals, Diagnose and prescribe: off for every nurse." An unknown role or ability is 422 (`errors.base`).
- `POST admin/access/roles/{role}/reset`: the whole role back to the standard (each removal audited). Answers the `GET` document.

### Messages to patients: `admin/settings/messages`
- `GET` → `{available, metered, balance, balance_low, settings{sms, events{event: bool}, quiet_start, quiet_end}, events[{key, label, default, preview}], month{sent, delivered, not_sent, by_status{}}, statuses[{value, label, tone}], owner_summary}`. `available`: this server can send texts at all; `balance` is null on a hospital's own server (it pays its gateway directly); `balance_low` below 50 messages; `month.sent` counts sent and delivered, `not_sent` failed and skipped, as the web's figures do; `preview` is what the patient would read, with this clinic's details; `owner_summary` says the owner's daily summary card applies (docs/api/owner-summary.md).
- `PUT/PATCH` `{sms?, events?{event: bool}, quiet_start?, quiet_end?}` (`H:i`). Partial: a field left out keeps its stored value; unknown events are ignored. `sms: true` on a server that cannot send is 422 `errors.sms` "Text messages are not switched on for this server yet." Answers the `GET` document with the web's sentence.
- `GET admin/messages` (`?status=` one of `statuses`, `?q=` the patient's name or number, or the number it went to, `?per_page=` up to 100, default 25): every message, newest first, `[{uuid, at, patient{uuid, name, patient_no}|null, event, event_label, body, to, status, status_label, status_tone, note, segments, sent_at, delivered_at}]`. `note` is why it was not sent, the gateway's error, or "After the quiet hours, 07:00". A document link's code and link token are shown as `••••••` and `/p/…`, on the web too (`PatientMessage::logBody`): a patient link keeps only their hashes.

## Account state

| Method | Path | Permission | Edition |
|---|---|---|---|
| GET | `admin/onboarding` | access-admin | both |
| GET | `admin/subscription` | manage-settings | **SaaS only** (404 on standalone) |
| GET | `admin/system` | manage-settings | **standalone only** (404 on SaaS) |

**Onboarding**: `{complete, progress{done,total,percent}, can_configure, next_step, issues[], blocking[], steps[{key, title, summary, why, icon, required, done, status, tone, detail, issues[], web_route}]}`. Derived from the hospital's real data by `OnboardingStatus`, the object the wizard and the redirect gate share. Keys: `practice, profile, billing, departments, services, staff` (required) and `rooms, wards, catalogues` (recommended; shown only when the practice uses them).

**Subscription**: `{subscription{status, status_label, plan{…}, starts_at, ends_at, trial_ends_at, days_remaining, grants_access, is_blocked, paid_plan_id, badge}, plans[{id, name, slug, description, price, price_label, billing_cycle, limits, features, is_featured, is_current}], payments[{id, paid_at, method, amount, amount_label, reference, period}], pay_url, month_options[], max_months}`. `pay_url` is the web page (`/admin/subscription`) where the owner chooses a plan and pays through Pesapal; the gateway is not implemented in the API. This is the only admin endpoint that still answers after the subscription has ended (`api.subscription.*` joined `EnsureSubscribed::ALLOWED`), so the app can show a renewal screen.

**Licence & updates**: `{edition, version, installed{}, licence{valid, id, holder, updates_until, updates_covered, staff_limit, address_matches, host}, update{available, latest{version, notes, published_at, size}|null, status, message, busy, web_url}, health{scheduler_heartbeat_at, scheduler_alive, queued_jobs, disk_free_bytes, upload_limit, newest_backup_age_seconds}, backups[{name, size, created_at}]}`. Read-only: checking, backing up, downloading backups and **updating stay on the web** (`update.web_url`). No download link or checksum is handed out.

## Data requests: `data-requests` (`manage-settings`, one clinic's log)

The web's Data requests (`DataRequestService`; docs/compliance/data-subject-rights.md): logged once with the date the answer is due, closed once, never edited. A caller outside any hospital is 403.

| Method | Path | Notes |
|---|---|---|
| GET | `data-requests` | `?status=open\|done\|refused`, `?kind=access\|correction\|objection\|deletion`, `?q=` (who asked, or the patient's name or number), `?per_page=` (default 25). Open requests first, the soonest due at the top. `meta.response_days` |
| GET | `data-requests/{uuid}` | the row plus `details, hint` (how to answer this kind), `recorded_by, created_at, outcome, closed_at, closed_by, personal_data` |
| POST | `data-requests` | `requester_name*` (150), `requester_contact` (150), `kind*`, `received_on*` (date, not after today), `details` (2000), `patient_uuid` (this hospital's register; else 422 `errors.patient_uuid`). 201 with the detail and "Request logged. The answer is due within 30 days." |
| POST | `data-requests/{uuid}/answer` | `outcome*` (2000): what was done. "Marked as answered." |
| POST | `data-requests/{uuid}/refuse` | `outcome*` (2000): the reason the patient is given. A closed request is 422 `errors.outcome` "This request was already closed." |
| GET | `patients/{uuid}/personal-data.pdf` | everything held about the patient, the web's own PDF (`DataProtectionController::patient`, logged as "patient data exported") for a request to see it (`personal_data: true`). `manage-settings`; another hospital's patient is 404 |

Row: `{uuid, requester_name, requester_contact, kind, kind_label, status, status_label, status_tone, overdue, received_on, due_on, patient{uuid, name, patient_no}|null}`.

