# Dental API

The same rules as the web (`docs/dental.md`). All under `/api/v1`, authenticated.

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `patients/{uuid}/dental` | `?as_of=YYYY-MM-DD` optional | `enabled`, `chart` (per tooth: `tooth`, `finding`, `label`, `tone`, `mobility`, `surfaces` {letter: finding}, `notes`, `updated_at`), `plan` (items), `history` (newest 50), `next_recall_on`, `findings` (catalogue) |
| POST | `patients/{uuid}/dental/findings` | `findings[]`: `uuid?`, `tooth`, `finding`, `surfaces?`, `mobility?`, `notes?`; `visit_uuid?` | 201: `recorded` (uuids), `chart` |
| POST | `patients/{uuid}/dental/plan` | `service_id`, `tooth?`, `surfaces?`, `price?` (inside the range), `notes?`, `visit_uuid?` | 201: the item |
| POST | `dental/plan/{item_uuid}/status` | `status` (`accepted`, `in_progress`, `done`, `cancelled`), `visit_uuid?` | the item; `billed: true` once done. **Idempotency-Key** like every money route |
| PUT | `patients/{uuid}/recall` | `next_recall_on`: a date, `"default"` (six months) or null | `next_recall_on` |
| GET | `dental/report` | `?from=&to=` | by dentist, by procedure, by month, planned/accepted/done, revenue |

An item: `uuid`, `service_id`, `procedure`, `tooth`, `surfaces`, `price`, `price_label`, `status`, `status_label`, `tone`, `next_statuses`, `notes`, `done_at`, `done_by`, `billed`.

Offline: findings go through the sync outbox as entity `dental_chart_entries` (create only) with `uuid`, `patient_uuid`, `visit_uuid?`, `tooth`, `finding`, `surfaces?`, `mobility?`, `notes?`.

Errors: 422 with the field (`findings.0.tooth`, `price`, `visit` when no open visit to bill on, `status` for a move that is not allowed); 403 when the role cannot.
