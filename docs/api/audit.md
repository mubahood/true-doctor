# Audit log API

The owner's audit log on the phone (roadmap 07 §3; how it works: `docs/audit.md`). Both endpoints need `manage-settings` (the hospital administrator), are read-only, scoped to the caller's hospital, and use the standard envelope.

## GET /api/v1/audit-log

Changes to clinical and money records, newest first, paged.

Query: `type` (a log name such as `patient`, `invoice`, `service`), `event` (`created`, `updated`, `deleted`; anything else is 422), `user_id`, `from`, `to` (`Y-m-d`), `per_page` (1 to 100, default 20).

```json
{
  "success": true, "code": "ok", "message": null,
  "data": [{
    "id": 812, "at": "2026-10-17T09:14:03+03:00",
    "type": "service", "type_label": "Price list item", "record_id": 14,
    "event": "updated", "event_label": "Changed",
    "who": "Grace Nakato", "via": "web",
    "fields": [{ "field": "Price", "old": "18000.00", "new": "20000.00" }]
  }],
  "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 },
  "errors": null
}
```

`who` is null for a change made by the system (seeding, a scheduled job). A masked value reads `[hidden]`.

## GET /api/v1/patients/{uuid}/access-log

Who opened or printed this patient's record, newest first, paged (`per_page`, default 20).

```json
{
  "data": [{
    "at": "2026-10-17T10:02:11+03:00", "action": "printed", "action_label": "Printed",
    "what": "Statement of account", "who": "Peter Okello", "role": "receptionist",
    "channel": "web", "device": "Chrome on Windows", "ip": "192.168.1.24"
  }],
  "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 }
}
```

Another hospital's patient is 404; a caller without `manage-settings` is 403.

## Reads that are logged

`GET /api/v1/patients/{uuid}` and `GET /api/v1/patients/{uuid}/summary` record an `opened` entry, and `GET /api/v1/patients/{uuid}/statement` a `printed` one, once per person, patient and action in 10 minutes. The app's `X-App-Version` header is kept as the device.

## The confidential note on a visit

`GET /api/v1/visits/{uuid}` (and every visit in a list) carries only `has_sensitive_notes`: the note itself is read on the web, because the app caches visit replies unencrypted. `POST /api/v1/visits/{uuid}/clinical` accepts `sensitive_notes` (string, up to 5000, nullable). It is not part of offline sync.

## The clinical record and who reads it (2026-10-17)

A visit's `complaints`, `diagnosis`, `doctor_remarks`, and `has_sensitive_notes` are **omitted**, and `vitals` too unless the caller records vitals or opens visits (the desk takes them at intake), (the keys are absent) for a caller without `visits.clinical`: by default the receptionist and the accountant, and any role a clinic has switched off in Settings, Staff and access (docs/rbac.md). The same caller gets 403 on `visits/{uuid}/diagnoses`, `visits/{uuid}/prescriptions` and the dental endpoints, no `clinical`, `lab` or `imaging` section in `patients/{uuid}/summary`, and no diagnosis on its visit rows. `capabilities.permissions` in `meta` is the effective list, after the clinic's choices.
