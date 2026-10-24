# API: the patient record's panels

The Records tab of the web patient page (documents, insurance cover, dependents, treatment records), the ID
card, and what can be sent to the patient by link, for the app. Envelope, auth and error codes are in
[`../api.md`](../api.md). Code: `routes/api/gap-patient.php`; controllers `Api\V1\PatientDocumentController`,
`PatientCoverageController`, `PatientDependentController`, `TreatmentRecordController` and
`PatientLinkController@shareable`. Files, photos and the ID card stream through the web's own controllers
(`Admin\PatientDocumentController@download`, `Admin\TreatmentRecordController@photo`,
`Admin\PatientController@idCard`), so the phone gets the same bytes as the browser.
Tests: `tests/Feature/Api/PatientRecordsApiTest.php`, `tests/Feature/Api/PatientShareApiTest.php`.

Every endpoint is under `/api/v1/patients/{uuid}/…`. The patient resolves through the tenant scope, so another
hospital's patient is `404`; a sub-record (a document, a cover row, a link, a treatment record) of another
patient is `404` through this one. Each write calls the service the web panel calls, behind the same policy,
and is covered by `Idempotency-Key` (a retry after a lost answer replays it and writes once; uploads are
fingerprinted by file name and size).

| Panel | Read | Write | Service |
|---|---|---|---|
| Documents | `patients.view` | `patients.update` (`PatientPolicy@manageDocuments`) | `DocumentService` |
| Insurance cover | `insurance.view` (`InsuranceClaimPolicy@viewAny`) | `insurance.manage` | `PatientService::addInsurance/removeInsurance` |
| Dependents | `patients.view` | `patients.update` (`PatientPolicy@manageDependents`) | `PatientService::linkDependent/unlinkDependent` |
| Treatment records | `treatments.view` | `treatments.manage` (`TreatmentRecordPolicy`) | `TreatmentService` |
| ID card | `patients.view` | | `Admin\PatientController@idCard` |

The app shows Insurance cover where the summary's `sections` has `insurance` (the permission and the
practice's insurance module), and Treatment records where the person has `treatments.view`.

## Documents

### `GET patients/{uuid}/documents`

Newest first. `meta` carries what the add form needs: `can_manage`, the `types` (`value`, `label`), and the
request's own limits, `max_kb` (10240) and `accept` (`pdf`, `jpg`, `jpeg`, `png`, `webp`, `doc`, `docx`).

```json
{ "success": true, "code": "ok", "data": [
  { "uuid": "6c1e…", "type": "referral", "type_label": "Referral", "original_name": "referral.pdf",
    "mime": "application/pdf", "size": 122880, "size_label": "120 KB", "note": "From the district hospital",
    "uploaded_by": "Rita Akello", "created_at": "2026-10-24T09:12:00+03:00",
    "download_path": "patients/9f0c…/documents/6c1e…/download" } ],
  "meta": { "can_manage": true, "types": [{ "value": "id", "label": "ID" }, { "value": "insurance", "label": "Insurance" }],
            "max_kb": 10240, "accept": ["pdf", "jpg", "jpeg", "png", "webp", "doc", "docx"] } }
```

### `POST patients/{uuid}/documents`

`multipart/form-data`: `type` (one of the types), `note?` (255), `file` (the request's types, 10 MB at most;
the server's PHP upload limit, `meta.capabilities.limits.upload_max_kb`, may be lower). `201` with the document.
`422` with `errors.type`, `errors.file`, `errors.note`. Stored on the private disk under
`patients/<hospital>/<patient>/`.

### `GET patients/{uuid}/documents/{document}/download`

The file, as an attachment with its original name (the web's download link).

### `DELETE patients/{uuid}/documents/{document}`

Removes the file from the disk and archives the row. `200` with `{ "uuid": "…" }`.

## Insurance cover

### `GET patients/{uuid}/insurances`

```json
{ "data": [ { "id": 41, "provider": { "id": 3, "name": "Jubilee" }, "member_no": "JUB-001",
              "coverage_percent": "80.00", "coverage_label": "80%", "valid_from": "2026-01-01",
              "valid_to": "2027-01-01", "is_active": true, "valid": true } ],
  "meta": { "can_manage": true, "providers": [ { "id": 3, "name": "Jubilee" } ] } }
```

`valid` is active and not past `valid_to` (the panel's "Expired" badge when false). `providers` are the active
insurers, given only to someone who may add cover.

### `POST patients/{uuid}/insurances`

`insurance_provider_id` (this hospital's), `member_no` (64), `coverage_percent` (0 to 100), `valid_from?`,
`valid_to?` (on or after `valid_from`), `is_active?` (true when not sent, as the panel's box starts ticked).
`201` with the row; `422` with the field.

### `DELETE patients/{uuid}/insurances/{id}`

A row of this patient's. `200` with `{ "id": 41 }`.

## Dependents

### `GET patients/{uuid}/dependents`

```json
{ "data": {
  "dependents": [ { "id": 7, "relationship": "child", "relationship_label": "Child",
                    "patient": { "uuid": "…", "name": "Baby Nakato", "patient_no": "PT-2026-000124J", "age": 3, "sex": "female" } } ],
  "guardians": [],
  "relationships": [ { "value": "child", "label": "Child" }, { "value": "spouse", "label": "Spouse" } ],
  "can_manage": true } }
```

`guardians` are the links where this patient is the dependent. `patient` is null when the other record was
archived since. Find the dependent with `GET patients?q=` (at least two characters, as the web's search).

### `POST patients/{uuid}/dependents`

`dependent_uuid` (a patient of this hospital), `relationship`. `201` with the link and the message
("Baby Nakato linked as child."). `422` with `errors.dependent_uuid`: "A patient cannot be their own
dependent.", "Baby Nakato is already linked.", or a uuid this hospital does not have.

### `DELETE patients/{uuid}/dependents/{id}`

A link where this patient is the guardian. `200` with `{ "id": 7 }`.

## Treatment records

### `GET patients/{uuid}/treatments`

Newest performed first. `meta`: `can_create`, `max_photos` (12), `photo_max_kb` (8192).

```json
{ "data": [ { "uuid": "…", "procedure": "Wound dressing", "description": "Left forearm, clean.",
              "performed_at": "2026-10-24T10:30:00+03:00", "performed_by": "Sr. Achen", "meta": null,
              "photos": [ { "id": 18, "caption": null, "path": "patients/9f0c…/treatment-photos/18" } ],
              "can_delete": true } ],
  "meta": { "can_create": true, "max_photos": 12, "photo_max_kb": 8192 } }
```

`GET` a photo's `path` for the image (private, never cached).

### `POST patients/{uuid}/treatments`

`multipart/form-data`: `procedure` (150), `description?` (5000), `performed_at?` (a date and time; now when
blank), `photos[]?` (images, 12 at most, 8 MB each). The record goes on the patient's current visit, opening
one if there is none, as the web panel does. `201` with the record; `422` with `errors.procedure`,
`errors.performed_at`, `errors.photos.0`.

### `DELETE patients/{uuid}/treatments/{uuid}`

Removes the photos from the disk and archives the record. `200` with `{ "uuid": "…" }`.

## The ID card

`GET patients/{uuid}/id-card`: the A7 card the web prints, with the QR of the patient number. `application/pdf`,
inline.

## What can be sent by link

`GET patients/{uuid}/shareable` (`patients.view`): the documents this person may send to this patient now, the
latest five of each kind they may print, each ready by the same rule the link's store applies
(`SharedDocuments::notReady`): a lab result once released, a radiology report once reported, a discharge
summary once discharged, a prescription once something is prescribed, a treatment quote once there is planned
treatment. With the expiry choices and whether the link can be texted (and why not).

```json
{ "data": {
  "documents": [ { "type": "lab_result", "type_label": "Lab result", "uuid": "…", "reference": "LAB-000042",
                   "title": "Malaria RDT", "date": "2026-10-24T09:40:00+03:00" } ],
  "days": [1, 3, 7, 14, 30], "default_days": 7, "max_attempts": 5,
  "can_text": false, "text_note": "The patient has not agreed to texts about their care: copy the link and code instead." } }
```

Make the link with `POST patient-links` ([`patient-links.md`](patient-links.md)).
