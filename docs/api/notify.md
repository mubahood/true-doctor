# Notifications, devices, preferences, reminders

All under `/api/v1`, Sanctum bearer, standard envelope (`{ success, code, message, data, errors }`, lists add `meta`). A person only ever sees or changes their own rows; another person's device or notification is a `404`. Routes: `routes/api/notify.php` (the notification list and read routes are in `routes/api.php`).

Categories: `clinical`, `ward`, `schedule`, `stock`, `billing`, `sync`, `account`, `system`. Priorities: `low`, `normal`, `urgent`.

**Privacy.** `public_message` / `title_public` never contain a name or number: they are what a lock screen or a push may show. `message`, `private_message` and `body_private` may name a patient: show them only inside the signed-in, unlocked app. Push payloads are built from `title` + `public_message` only.

## GET /notifications

Query: `unread=1`, `per_page` (max 100), `since=<ISO date>` (created after), `category=`, `priority=`. Rows stored before the standard payload existed are given the category, priority, route and entity their `type` implies, so filters and the app treat them the same.

```json
{
  "success": true, "code": "ok", "message": null,
  "data": [{
    "id": "9f3c…", "type": "lab_result_ready",
    "category": "clinical", "priority": "urgent",
    "title": "Lab results ready",
    "message": "Lab results are ready for Amina Nakato.",
    "public_message": "A lab result is ready.",
    "private_message": "Lab results are ready for Amina Nakato.",
    "route": "admin.lab-orders.index", "entity": "lab_order", "entity_uuid": "3b1e…",
    "dedupe_key": "lab_order:3b1e…:lab_result_ready", "expires_at": null,
    "data": { "type": "lab_result_ready", "title": "…", "message": "…", "lab_order_uuid": "3b1e…", "category": "clinical" },
    "read_at": null, "created_at": "2026-10-17T08:00:00+00:00"
  }],
  "meta": { "current_page": 1, "per_page": 30, "total": 1, "last_page": 1, "unread": 1 },
  "errors": null
}
```

`meta.unread` is one indexed count; ask with `unread=1&per_page=1` to poll cheaply.

## POST /notifications/{id}/read, POST /notifications/read-all

`{"data": {"unread": 0}}`. `read-all` is a single `UPDATE` (the collection is not loaded).

## PUT /devices/{device_uuid}/push-token

`device_uuid` is the install's id: the same value the app sends as `X-Device-Id`. Idempotent: the same device is one row, a new token replaces the old. Registering again brings a removed device back. `token` may be omitted (an install with no push still tells the server its zone and locale).

```json
// request
{ "token": "fcm-token…", "platform": "android", "provider": "fcm", "app_version": "1.2.0",
  "locale": "en", "tz": "Africa/Kampala", "push_enabled": true }
// response data
{ "device_uuid": "…", "platform": "android", "provider": "fcm", "app_version": "1.2.0", "locale": "en",
  "tz": "Africa/Kampala", "push_enabled": true, "has_token": true, "push_delivers": false,
  "last_seen_at": "2026-10-17T08:00:00+00:00" }
```

`platform`: android, ios, web, windows, macos, linux. `provider` (default from platform): none, fcm, apns, webpush. `push_delivers` is true only when a real push driver is configured (`PUSH_DRIVER`, `config/push.php`); with the shipped `null` driver it is false and the app relies on polling and its own local reminders. Validation errors are `422 validation_failed`.

## GET /devices, DELETE /devices/{device_uuid}

`GET` lists this person's un-revoked devices (the shape above). `DELETE` revokes (`revoked_at`, `push_enabled=false`); the row is kept. `POST /auth/logout` revokes the device named by the request's `X-Device-Id` header (only that one).

## GET /notification-preferences, PUT /notification-preferences

```json
// GET data: one row per category
[{ "category": "stock", "enabled": false, "default_enabled": false, "is_default": true,
   "channels": null, "quiet_start": "22:00", "quiet_end": "06:00", "tz": null }, …]
// PUT
{ "preferences": [{ "category": "ward", "enabled": false, "quiet_start": "21:30", "quiet_end": "05:30",
                    "tz": "Africa/Kampala", "channels": ["database", "push"] }] }
```

Role defaults: a category whose permission the person lacks starts off (clinical `visits.view`, ward `ipd.view`, schedule `appointments.view`, stock `pharmacy.view`, billing `billing.view`); sync, account, system start on. The server skips a notification (and its push) for a category switched off. Quiet hours are advice for the phone's own scheduler; the server does not delay in-app notifications.

## GET /reminders/upcoming?from=&to=

What this person should be reminded of in the window (default now → +7 days, at most 14 days; `meta.truncated` says it was cut). Built by `App\Services\ReminderService`; only what the person's permissions and hospital allow. The phone uses the same `key`s for the reminders it plans itself, so the two never double up.

```json
{ "data": [{
    "key": "appt:7d1c…:day", "category": "schedule", "priority": "normal",
    "fire_at": "2026-10-16T18:00:00+03:00",
    "title_public": "Appointment tomorrow",
    "body_private": "Appointment tomorrow at 10:00 with Amina Nakato.",
    "route": "admin.appointments.index", "entity": "appointment", "uuid": "7d1c…",
    "expires_at": "2026-10-17T10:00:00+00:00" }],
  "meta": { "from": "…", "to": "…", "truncated": false, "server_time": "…", "timezone": "Africa/Kampala" } }
```

Keys: `appt:<uuid>:day` (18:00 the evening before, hospital time) and `appt:<uuid>:soon` (60 minutes before) for scheduled/confirmed appointments (follow-ups included; a doctor without `appointments.manage` sees their own); `stock:digest:<date>` (08:00, `pharmacy.view`); `licence:<days>:<end date>` for 3, 1 and 0 days before a trial/subscription ends (SaaS) or the updates window ends (standalone), 09:00, `manage-settings`. Doses arrive in a later wave.

## Server-side events and the scheduler

- `LabService` raises `App\Events\LabResultReleased`, `StockService` raises `App\Events\StockBelowReorder`; `App\Listeners\NotifyUser` sends `LabResultReady` to the ordering doctor and `LowStockAlert` to everyone in the hospital with `pharmacy.manage`, skipping anyone who switched the category off. (Standalone: a new listener class ships in a release; never upload it by hand.)
- `appointments:remind` runs **hourly**: day-before (tomorrow's appointments, from 07:00 hospital time; doctor in-app/push/SMS and patient SMS) and two-hours-before (doctor in-app/push). Silent 20:00–07:00 hospital time. Each is stamped on the appointment (`reminder_sent_at`, `reminder_soon_sent_at`) before sending, so late, overlapping and repeated runs send nothing twice. `--date=Y-m-d` reminds about that date at any hour.
- Push: `PushChannel` → `PushTransport` (`config/push.php`, `PUSH_DRIVER`). Default `null` logs and drops. `App\Services\Push\PushManager::delivers()` is what `meta.capabilities.features.push` should report.
