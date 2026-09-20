# Treatment records (Phase 2, Step 14)

A patient's procedure timeline — dressings, minor surgery, dental work — each with a
description and **multiple photos**. Specialty charting (e.g. a dental tooth-map) lives in the
record's `meta` JSON rather than a bespoke table, per HMS_PLAN §4.

## Files

| Concern | File |
|---|---|
| Tables | `2026_07_19_220000_create_treatment_records_table.php`, `…_220010_create_treatment_record_items_table.php` |
| Models | `app/Models/{TreatmentRecord,TreatmentRecordItem}.php` |
| Service | `app/Services/TreatmentService.php` |
| Request / Policy | `app/Http/Requests/TreatmentRecordRequest.php`, `app/Policies/TreatmentRecordPolicy.php` |
| Controller | `app/Http/Controllers/Admin/TreatmentRecordController.php` |
| Views | treatment section on the patient page + `resources/views/admin/treatments/show.blade.php` |

## Photos

`TreatmentService::create` writes the record and its photo rows in one transaction; each photo
is stored on the **private** `local` disk under `treatments/<hospital>/<patient-record>/…` —
never a public URL. Photos stream through `TreatmentRecordController@photo` behind the Policy
(and tenant scope), and are removed from disk when the record is deleted. Up to 12 images per
record, validated as images ≤8 MB.

## RBAC

`treatments.view` · `treatments.manage` — doctors and nurses manage; the timeline shows on the
patient page.

## Tests

`TreatmentRecordTest` — add-with-photos (stored on the private disk under the tenant path),
delete-removes-photos, receptionist-can't-add, tenant isolation.
