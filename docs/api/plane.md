# The data plane: bootstrap, capabilities, search, conditional requests, deltas, tombstones, error codes

Everything an app needs to start from, stay current cheaply, and find anything. All routes are under the
authenticated, subscribed group of `/api/v1` and use the standard envelope. Built for both editions: nothing here
needs Redis, websockets, a long-running worker or instant cron (a standalone copy's cron may run only every 19
minutes), so revisions are bumped by **model observers**, never by a schedule.

Code: `ConditionalJson`, `PagesAndDeltas`, `BootstrapController`, `SearchController`, `Capabilities`,
`ReferenceRevision`, `EnumCatalogue`, `TombstoneObserver`, `PullService`. Tests: `tests/Feature/Api/{ConditionalGet,
DeltaList,Bootstrap,Tombstone,Search,Capabilities,ErrorCodes,ListCap}ApiTest.php`.

## 1. Conditional requests (ETag / 304)

Every successful `GET` (and `HEAD`) JSON answer carries:

```
ETag: W/"3f6c1d0a4b7e5c9a1d2e8f90b1c2d3e4f5a6b7c8"
Cache-Control: private, no-cache
X-Server-Time: 2026-10-16T16:36:27+00:00
Vary: Authorization, Accept-Language
```

Send the tag back and an unchanged answer costs headers only:

```
GET /api/v1/patients            If-None-Match: W/"3f6c..."   ->   304 Not Modified   (empty body, same ETag)
```

- The tag is a hash of the **final** body, so tenancy and permissions are already in it: two users who see different
  data get different tags, and a tag from hospital A never validates hospital B's answer.
- `server_time` (in `meta`, in `/meta`, in `capabilities`) is left out of the hash, otherwise nothing could ever be
  `304`. The server's clock rides in the `X-Server-Time` header on both the `200` and the `304`.
- `If-None-Match` may list several tags or be `*`; comparison is weak (RFC 9110).
- Only `200` JSON on a safe method is tagged. Writes, PDFs/downloads and errors are never tagged or answered `304`.
- The app caches the body with the tag and serves the cached body on `304` (app task B5).

## 2. `GET /api/v1/bootstrap?since=<revision>&have=<kinds>`

One call to start from: `meta`, `capabilities`, the reference catalogues this user may see, and what has been removed.

| Parameter | Meaning |
|---|---|
| `since` | A `revision` from an earlier answer. Absent or `0`: everything visible, in full. |
| `have` | Comma list of sections the app holds. A visible section not in the list is sent in full whatever its revision (a user who just gained a permission). Optional. |

```json
{
  "success": true, "code": "ok", "message": null, "errors": null,
  "data": {
    "revision": 9,
    "unchanged": false,
    "rebuild": false,
    "meta": { "api_version": 1, "edition": "saas", "...": "exactly what GET /meta returns" },
    "capabilities": { "...": "see section 3 (also inside meta.capabilities)" },
    "reference": {
      "services": [ { "id": 1, "name": "Consultation", "code": "CONS", "price": "20000.00" } ],
      "payment_methods": [ { "id": 3, "value": "mobile_money", "label": "Mobile money", "needs_reference": true } ],
      "doctors": [ { "id": 7, "name": "Dr Okello", "role": "doctor", "specialty": "Paediatrics", "department_id": 2,
                     "schedules": [ { "id": 11, "weekday": 1, "start_time": "08:00", "end_time": "13:00", "slot_minutes": 20, "room_id": 3 } ] } ]
    },
    "revisions": { "services": 3, "doctors": 5, "payment_methods": 7, "districts": 6 },
    "omitted_kinds": [ "wards", "beds", "lab_tests", "medications", "stock_categories", "radiology_studies" ],
    "removed": [ { "entity": "patients", "uuid": "08ee5484-f03b-43ad-bb0f-bdb75918963b",
                   "at": "2026-10-16T16:36:27+00:00", "reason": "deleted", "revision": 9 } ],
    "removed_reference": { "services": [ 12 ] }
  }
}
```

- **`revision`** is the hospital's change counter read *before* the rows were. Pass it back as `since`. A change that lands
  in between is simply sent again next time; one is never lost.
- **`reference`** holds the sections that changed after `since` (all visible ones when `since` is absent), each **in full**:
  the app replaces a section, it does not patch one. Every row has an integer `id`.
- **`revisions`** is the revision of every section this user can see (changed or not). Sections are omitted, never empty,
  when the user lacks the permission (see the table).
- **`unchanged`**: `since` given and nothing moved: `reference` is `{}` and `removed` is `[]`. The whole answer is also
  ETag-tagged, so `If-None-Match` earns a `304` when `meta` is unchanged too.
- **`omitted_kinds`**: sections that exist but this user may not see **now**. Purge them from the device.
- **`removed`**: tombstones with `revision > since` (section 6), at most 500. If more exist, **`rebuild`** is `true`: the
  app is too far behind to patch; call again with no `since` and replace everything.
- **`removed_reference`**: per changed section, ids that left it (deleted or made inactive). Redundant with replacing the
  section; provided for apps that patch.

| Section | Needs one of | Rows |
|---|---|---|
| `wards`, `beds` | `ipd.view`, `wards.manage` | `{id, name}` / `{id, ward_id, name, status}` |
| `services` | `visits.view` | `{id, name, code, price}` |
| `lab_tests` | `lab.view` | `{id, name, specimen, unit, reference_range, price}` |
| `medications` | `pharmacy.view` | `{id, name, unit, sku}` (never a balance) |
| `doctors` | `appointments.view` | `{id, name, role, specialty, department_id, schedules:[{id, weekday, start_time, end_time, slot_minutes, room_id}]}` |
| `departments` | `visits.view` or `appointments.view` | `{id, name, code}` |
| `stock_categories` | `pharmacy.view` | `{id, name, unit}` |
| `insurance_providers` | `insurance.view` | `{id, name, code}` |
| `payment_methods` | `billing.view` | `{id, value, label, needs_reference}` |
| `districts` | `patients.view` | `{id, name}` |
| `radiology_studies` | `radiology.view` | `{id, name, modality, body_part, price}` |

Not provided: order-catalogue picks (`/orders/catalogue` stays a search endpoint) and units (there is no units table;
units are columns on stock items and categories).

### How revisions work (`App\Support\ReferenceRevision`)

Each section has a revision drawn from the hospital's sync counter (`SyncRevision::next`) when a model that feeds it is
created, deleted, restored, or **updated in a column the section shows** (a stock balance or a bed's `updated_at` moves
nothing). Observers do this on the write, so a catalogue edit is visible on the next request on both editions. The
numbers live in the default cache store (file on a standalone copy). If the cache is cleared, a section with no stored
number reads as the hospital's *current* counter, which is never smaller than anything issued before: every client
sees "changed" once and refetches, and it is stable from then on. Losing the cache costs one refetch and can never hide
a change. `districts` and `payment_methods` have no hospital behind them; they keep a global token and each hospital
mints its own revision the first time it sees the token move.

### `GET /api/v1/sync/reference?since=&have=` (offline protocol, unchanged shape plus additions)

Reads the same sections through the same code. `since` was ignored before; it now returns only changed sections (each in
full). New keys beside the old `reference` and `reference_version`: `unchanged`, `revisions`, `omitted_kinds`, `removed`.
Without `since` the answer is what it always was, now with more kinds. Wards and beds now need `ipd.view` or
`wards.manage` (they used to be sent to everyone).

## 3. `capabilities` (in `GET /meta` and `GET /bootstrap`)

What this server can do for this user. Existing `meta` keys are unchanged; `capabilities` is added beside them. An app
treats an absent key (an older server) as "off".

```json
{
  "api_version": 1, "min_api_version": 1, "deprecated_after": null,
  "edition": "saas", "practice_kind": "general_hospital",
  "modules": { "inpatient": true, "laboratory": true, "radiology": true, "pharmacy": true, "insurance": true, "cards": true },
  "permissions": [ "access-admin", "appointments.view", "billing.view", "patients.view", "visits.view" ],
  "features": { "search": true, "etag": true, "tombstones": true, "bootstrap": true,
                "agenda": true, "push": false, "attachments": false, "batch": false },
  "limits": { "per_page_max": 100, "upload_max_kb": 8192, "rate_per_min": 240, "idle_timeout_minutes": 15 },
  "locales": [ "en", "lg", "sw" ],
  "server_time": "2026-10-16T16:36:27+00:00", "timezone": "Africa/Kampala",
  "licence": null
}
```

- `permissions`: the user's effective permission strings, the same ones every Policy checks.
- `features.push` is `true` only when `config('push.driver')` is not `null` (the logging driver). A standalone copy never has our credentials.
  `attachments`, `batch` are owned by later workstreams and flip to `true` in the change that ships them.
- `limits.upload_max_kb` is `min(post_max_size, upload_max_filesize)` in KB (PHP refuses a request at the lower one).
- `licence` is `null` on SaaS; on a standalone copy: `{valid, id, customer, updates_until, updates_active, limits:{max_staff?, max_patients?, max_beds?}}`.
  An invalid licence never stops clinical work.
- `enums` in `/meta` is built from an explicit list (`EnumCatalogue::ENUMS`, guarded by a unit test) and cached under a key
  made of the app version and the newest enum file's mtime; no `glob` per request.

## 4. `?updated_since=` / `include_deleted=1` on lists

Lists: `patients`, `visits`, `invoices`, `stock-items`, `appointments`, `lab-orders`, `radiology-orders`, `services`.

```
GET /api/v1/patients?updated_since=2026-10-16T08:30:00Z&include_deleted=1&per_page=100
```

```json
{
  "data": [ { "uuid": "08ee5484-...", "patient_no": "PT-2026-000123", "...": "...", "updated_at": "2026-10-16T16:36:27+00:00", "deleted_at": null } ],
  "meta": { "current_page": 1, "per_page": 1, "total": 1, "last_page": 1,
            "server_time": "2026-10-16T16:36:27+00:00", "next_since": "2026-10-16T16:36:27+00:00" }
}
```

- `updated_since` is an ISO 8601 date-time; anything else is `422 validation_failed` on `updated_since`. Rows with `updated_at`
  strictly later are returned, **oldest change first** (`updated_at, id`), so the last page's `next_since` is a safe resume point.
- `include_deleted=1` also returns soft-deleted rows; they carry `deleted_at` (a soft delete moves `updated_at`). Without it,
  deleted rows are never returned.
- Every list row now has `updated_at` and `deleted_at`. `meta.server_time` and `meta.next_since` (the largest `updated_at` returned;
  the caller's own `updated_since` when nothing came back; `null` if neither) are on every list, delta or not.
- `updated_at` has second resolution and the filter is strict: resume from `next_since` **minus two seconds**. Re-delivered rows are
  harmless because applying a row is an upsert.
- Tenant scope always applies; `include_deleted` never crosses hospitals.
- `per_page` defaults to what each list always had (20; 25 for stock items), is clamped to `1..100`
  (`capabilities.limits.per_page_max`).
- Lists that returned everything are now bounded: `GET /visits/{uuid}/orders` and `GET /visits/{uuid}/prescriptions` return at most 200 and say so:
  `meta: {"total": 205, "returned": 200, "limit": 200, "truncated": true}`.
- `GET /stock-items?q=` also matches the **SKU**.

## 5. `GET /api/v1/search?q=&types=patients,visits,invoices,stock,staff&limit=5`

```json
{
  "success": true, "code": "ok", "message": null, "errors": null,
  "data": {
    "q": "mari", "limit": 5,
    "groups": [
      { "type": "patients", "hits": [
        { "type": "patients", "uuid": "08ee5484-f03b-43ad-bb0f-bdb75918963b", "title": "Mariam Okello",
          "subtitle": "PT-2026-000123 · 0700111222", "score": 70, "route": "patients/08ee5484-f03b-43ad-bb0f-bdb75918963b" } ] }
    ]
  }
}
```

- `q` needs at least 2 characters after cleaning (`422` otherwise). `%`, `_` and `\` are not wildcards; they split words.
  `types` defaults to all five; an unknown name is `422`. `limit` defaults to 5 and is capped at 10, per type.
- A type the user may not search (Policy `viewAny`: `patients.view`, `visits.view`, `billing.view`, `pharmacy.view`, `manage-users`) is **omitted**,
  not returned empty. Asking only for forbidden types gives `groups: []`.
- `score`: exact number or SKU `100` > number prefix `90` > name `80` exact / `70` prefix > phone prefix `60` > substring `40`.
  Hits within a group are sorted by score. `route` is the API-relative path of the record (staff have `id` and `uuid: null`).
- Two passes per type: an indexed `LIKE 'x%'` first; the unindexed `LIKE '%x%'` only to fill what is left under `limit`. No FULLTEXT.
  Patients match number, first name, last name, both phones; visits match number and the patient's name; invoices number and patient;
  stock name and SKU; staff name and email. Multi-word queries (`mariam oke`) must match every word.
- Tenant-safe: all models except `User` carry the hospital scope; staff use the same `manageableBy` as the staff page.
- Throttled to 60 a minute per user (`429 too_many_requests`, `retryable: true`, `retry_after`). Migration
  `2026_10_17_100001_index_delta_and_search_columns` adds `(hospital_id, updated_at)` on the delta lists and prefix indexes
  on `patients.first_name`, `patients.phone_2`, `stock_items.sku`/`name`, `users.name`, each only if missing.

## 6. Tombstones (`removed`)

Table `sync_tombstones(id, hospital_id, entity, uuid, revision, reason, deleted_at)`. A revision comes from the hospital's own
counter, so a removal sits in the same order as the changes in `sync/pull`.

Written by `TombstoneObserver` when:

- a `patients`, `visits`, `invoices`, `stock_items`, `appointments`, `lab_orders`, `radiology_orders` or `services` row is deleted
  (`reason: "deleted"`; restoring clears it). `uuid` is the record's uuid, or its numeric id as a string for a row with none (services);
- a **lab order is completed or cancelled**: one `lab_orders` and one `lab_items` per test, `reason: "closed"`. This closes the known gap
  where a closed order left the bench worklist and the device kept the item for ever;
- a radiology order is reported or cancelled: `radiology_orders`, `reason: "closed"`.

Served as `removed: [{entity, uuid, at, reason, revision}]`:

- `GET /sync/pull`: beside `changes`, in the same revision-ordered pages (`has_more`/`next_cursor` cover both). An old app ignores the new key.
- `GET /bootstrap`: tombstones after `since`.

Only entities the user may see are sent (`patients.view`, `visits.view`, `billing.view`, `pharmacy.view`, `appointments.view`, `lab.view`,
`radiology.view`). Hospital B never receives hospital A's. Pruned after 90 days by a scheduler entry that runs every minute and
decides for itself (at most once every 12 hours), because a 19-minute cron would almost never hit a `dailyAt` minute.
A bulk query-builder delete fires no model events and writes nothing: code that deletes in bulk must write the tombstones.

## 7. Error codes

Envelope unchanged: `{success, code, message, data, errors}`. New optional keys, present only when the server has a view:
`retryable` (bool) and `retry_after` (seconds; also the `Retry-After` header). Status codes of existing answers did not change;
only the `code` gets more exact.

| Code | Status | Meaning | `retryable` |
|---|---|---|---|
| `conflict` | 409 | The record changed under the caller | false |
| `plan_limit` | 403 | Plan or licence limit reached (was `forbidden`: patients, visits-intake, staff, beds) | false |
| `payload_too_large` | 413 | Body or upload over the limit (`capabilities.limits.upload_max_kb`) | false |
| `device_revoked` | 403 | This device was blocked; the app clears its local copy | false |
| `licence_expired` | 402 | The standalone licence lapsed | false |
| `maintenance` | 503 | Down for maintenance | true |
| `too_many_requests` | 429 | Throttled | true, with `retry_after` |

```json
{ "success": false, "code": "plan_limit", "message": "Your plan's limit for patients (100) has been reached. Upgrade to add more.",
  "data": null, "errors": null, "retryable": false }
```

### Language

`Accept-Language` (first supported tag by q-value: `en`, `lg`, `sw`) sets the locale for translated messages; otherwise the user's
`locale` if the account has one; otherwise the app default. The answer carries `Content-Language`. Unsupported tags fall back; the
choice never leaks into the next request.
