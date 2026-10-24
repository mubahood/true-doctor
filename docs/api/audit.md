# Audit log API

The owner's audit log on the phone (roadmap 07 §3; how it works: `docs/audit.md`). A caller outside any hospital (a platform account) is 403 on every audit endpoint, as on `admin/access`: with no tenant the scope would filter nothing. Both endpoints need `manage-settings` (the hospital administrator), are read-only, scoped to the caller's hospital, and use the standard envelope.

## GET /api/v1/audit-log

Changes to clinical and money records, newest first, paged.

Query: `type` (a log name such as `patient`, `invoice`, `service`), `event` (`created`, `updated`, `deleted`; anything else is 422), `user_id`, `from`, `to` (`Y-m-d`), `q` (the web's search box: a record's number; other text matches the description), `per_page` (1 to 100, default 20).

```json
{
  "success": true, "code": "ok", "message": null,
  "data": [{
    "id": 812, "at": "2026-10-17T09:14:03+03:00",
    "type": "service", "type_label": "Price list item", "record_id": 14,
    "event": "updated", "event_label": "Changed",
    "description": "updated", "who": "Grace Nakato", "via": "web", "via_label": "The web", "ip": "192.168.1.24",
    "summary": "Price",
    "fields": [{ "field": "Price", "old": "18000.00", "new": "20000.00" }]
  }],
  "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 },
  "errors": null
}
```

`who` is null for a change made by the system (seeding, a scheduled job). A masked value reads `[hidden]`. `summary` is the web's "Fields" column ("Status, Paid amount and 2 more"); `event_label` falls back to the description (capitalised) for an entry with no event, as the web's dialog does.

## GET /api/v1/audit-log/{id}

One change, the web's dialog: the same object as a row (with `fields`, `via_label` and `ip`). Another hospital's entry is 404.

## GET /api/v1/audit-log/opens

The web's "Records opened" tab: who opened or printed a patient's record, newest first, paged.

Query: `patient` (a uuid of this hospital's register; anything else is 422), `q` (a patient's name or number: the phone's search box, where the web has a patient picker), `action` (`opened`, `printed`), `user_id`, `from`, `to` (`Y-m-d`), `per_page` (1 to 100, default 20). Each row is the access-log row below plus `id` and `patient {uuid, name, patient_no}`.

## GET /api/v1/audit-log/filters

The choices of the web's filter bar: `types[{value, label}]` (every audited record type), `events[{value, label}]`, `actions[{value, label}]`, and `people[{id, name, role_label, is_active}]`: everybody with an account in this hospital, people who have left included (their changes stay in the trail).

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
