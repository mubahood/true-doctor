# Patient links and document verification (roadmap 05 §4)

A patient opens a lab result, radiology report, discharge summary, prescription or dental quote from a text
message, without an account. Every printed document carries a QR and a short code that confirm it is genuine.

Service: `App\Services\PatientLinks\PatientLinkService` (links), `App\Services\PatientLinks\SharedDocuments`
(what a link can open, and the PDF), `App\Services\DocumentVerifier` (the QR). The web's "Send to patient" button
(`PatientLinks\Panels\Share`), the Patient links page (`PatientLinks\Index`) and this API all call the same service.

## The rules

- **A link** is `/p/{token}`: 24 random letters and digits. Only the SHA-256 of the token and a bcrypt hash of the
  six-digit code are stored, so the link and its code are shown (and returned by the API) **once**.
- **Opening it** asks for the code or the patient's date of birth (`DD/MM/YYYY`, `YYYY-MM-DD`). A correct answer opens
  the document in that browser for 30 minutes. **Five wrong answers lock it for good**; a new link must be sent.
- **Expiry**: 7 days by default; staff choose 1, 3, 7, 14 or 30. **Withdrawing** a link stops it at once, even in a
  browser that already unlocked it. A link that no longer works says why and gives the clinic's phone number.
- **The open log** (`patient_link_opens`, append-only) records every attempt: `viewed`, `unlocked`, `wrong_code`,
  `locked`, `refused` (opened after it stopped working), `downloaded`, with the IP address and browser.
- **When it can be sent**: a lab result once completed, a radiology report once reported, a discharge summary once the
  patient is discharged, a prescription once something is prescribed on the visit, a quote once there is planned treatment.
- **Who may send or withdraw**: whoever may print that document: `lab.view`, `radiology.view`, `ipd.view`, `visits.view`,
  or (`treatments.view` or `visits.diagnose`) for the quote. Seeing the list and the open log: `patients.view`.
- **The text**: with `send_sms`, the link and code go through `PatientMessenger` as event `document_link`
  ("Kato Medical Centre: your lab result is ready. View it here: … (code: 482913)"). The clinic's message settings, the
  patient's consent (SMS, care), quiet hours and message credit all apply; a message that cannot go is written down with
  the reason and the link is still made.
- **Rate limits**: the public pages 30 a minute per address; answers 10 a minute.

## Endpoints (`/api/v1`, Sanctum, standard envelope)

| Endpoint | Permission |
|---|---|
| `GET /patient-links?patient={uuid}&state=active\|expired\|revoked\|locked&q=&per_page=` | `patients.view` |
| `GET /patients/{uuid}/shareable` | `patients.view` (what is offered follows the document's own) |
| `POST /patient-links` | the document's own (above) |
| `GET /patient-links/{uuid}` | `patients.view` |
| `DELETE /patient-links/{uuid}` | the document's own |

`q` searches like the web list: the patient's name or number, the document's number or title. Every link row
carries `can_revoke`: the link still opens (or is locked) and this person may print that document.

A link of another hospital, or a document of another hospital, is `404`.

### POST /patient-links

```json
{ "document_type": "lab_result", "document_uuid": "…", "days": 7, "send_sms": true }
```

`document_type`: `lab_result` (lab order uuid), `radiology_report` (radiology order uuid), `discharge_summary`
(admission uuid), `prescription` (visit uuid), `dental_quote` (patient uuid). Covered by `Idempotency-Key`: a retry
replays the same answer (and the same code) and makes no second link.

`201`:

```json
{
  "link": { "uuid": "…", "document_type": "lab_result", "document_label": "Lab result", "reference": "LAB-000042",
            "patient": { "uuid": "…", "name": "Amina Nakato", "patient_no": "P-000012" },
            "state": "active", "state_label": "Active", "state_tone": "success", "expires_at": "2026-10-24T10:00:00+03:00",
            "revoked_at": null, "locked_at": null, "failed_attempts": 0, "opens_count": 0, "last_opened_at": null,
            "created_by": "Dr. Okello", "created_at": "…", "can_revoke": true },
  "url": "https://…/p/Xy7…",
  "code": "482913",
  "qr": "data:image/png;base64,iVBORw0…",
  "message": { "uuid": "…", "status": "queued", "status_label": "Waiting to send", "skip_reason": null }
}
```

`qr` is the link as a QR (a PNG data URI) for the patient's phone camera, or null if it could not be drawn; the
link is made either way.

`message` is `null` when no text was asked for or messages are off for the clinic. `422` with `errors.document` when
the document cannot be sent yet ("The lab has not released this result yet.").

### GET /patient-links/{uuid}

The link (as above) plus `revoked_by` (who withdrew it), `message` (`status`, `status_label`, `skip_reason` of the
text, or null when none was asked for) and `opens`: the last 50 attempts, newest first, each
`{event, event_label, ip, user_agent, at}`.

### GET /patients/{uuid}/shareable

What can be sent to this patient now, for the app's "Share a link" on the patient record: see
[`patient-records.md`](patient-records.md#what-can-be-sent-by-link).

### DELETE /patient-links/{uuid}

Withdraws it; safe to repeat. Returns the link with `state: "revoked"`.

## Public pages (web, no login)

| Route | What |
|---|---|
| `GET /p/{token}` (`patient-link.show`) | asks for the code, or shows the document's title, number and the button to open it |
| `POST /p/{token}` (`patient-link.unlock`) | `answer` = the code or the date of birth |
| `GET /p/{token}/document` (`patient-link.document`) | the PDF, inline, once unlocked in this browser |
| `GET /v` (`verify.form`), `GET /v/{code}` (`verify.show`) | document verification |

## The verification QR

Every PDF drawn by `<x-pdf.document>` gets, in its footer, a QR and an 8-character code (`K7QM-4XPA`, no look-alike
characters, random) that open `/v/{code}`. The page says: "This is a genuine laboratory report from Kato Medical Centre,
issued on 17 Oct 2026 for a patient with initials A. N." with the document's number, and **never its contents**.
One code per document per day it is printed (`document_verifications`, keyed by hospital, title, number, patient and
day), so reprinting does not grow the table. A missing QR never stops a document printing.
