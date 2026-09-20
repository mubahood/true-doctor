# True-Doctor — Architecture

## 1. Overview

Layered Laravel app: thin controllers → **Service classes** (business rules) → Eloquent models.
Two entry surfaces:

- **Public** (`routes/web.php` + `routes/verify.php`, no auth): the scan-first home, the verify
  endpoints, public profile, certificate download, fraud report.
- **Staff back-office** (`/admin/*`, `auth` + `admin` middleware): the registry CRUD, credential
  review, status actions, fraud queue, configuration, analytics, bulk import.

The public API lives at `/api/v1/verify/{code}` (rate-limited, open).

## 2. Data model

The heart is one **`registrations`** table (the verifiable entity), with configuration and audit
tables around it.

```
regulatory_bodies ─┐
                   ├─< categories ──< registrations >── districts
                   │                      │  ▲ institution_id (self-ref)
                   │                      │
users (staff) ─────┘        credentials >─┤
                            renewals      ├─< verification_logs
                            status_histories
                            institution_members
                            fraud_reports  (registration_id nullable)
settings (k/v)              activity_log (Spatie)
```

Key tables:

- **registrations** — `uuid`, unique `verification_code` (typed), unique `qr_token` (scanned),
  `category_id`, `regulatory_body_id`, `district_id`, `institution_id` (self-ref), `status`,
  identity fields, `issued_at`/`expires_at`, `verified_at`/`verified_by`, `profiled_by`,
  `meta` (JSON, category-specific), `verification_count`, `is_searchable`, soft-deletes.
  Fulltext index on `full_name, license_number, specialty` (MySQL only).
- **categories** — data-driven entity types; hierarchical (`parent_id`), `group`
  (practitioner/facility/pharmacy/drug/student/institution/supplier), `code_prefix`,
  `default_validity_months`, `schema_json`.
- **regulatory_bodies** — UMDPC, UNMC, PCU, NDA, AHPC, UMC, MoH (seeded, editable).
- **credentials** — documents proving a registration (private disk); review workflow
  (pending/approved/rejected) modelled on the retired KYC pattern.
- **verification_logs** — every public scan/lookup (audit + analytics + anti-fraud). Append-only.
- **status_histories** — immutable log of every status transition (belt-and-braces alongside the
  Spatie activity log).
- **renewals**, **fraud_reports**, **institution_members**, **settings**.

## 3. Verification code & QR

`App\Support\VerificationCode`:

- Format `TD-<PREFIX>-<YEAR>-<SEQUENCE><CHECK>`, e.g. `TD-DR-2026-000482K`.
- Check character = a mod-N checksum over an **unambiguous alphabet** (no `0/O`, `1/I`) so the
  browser catches typos client-side (`isValid()` mirrors the server).
- The **QR encodes the URL** to `/v/{qr_token}` — an unguessable 48-char token, never the code, so
  codes can't be enumerated from a photographed QR.

`App\Services\QrService` renders the QR as SVG (no imagick needed) and caches it on the public disk.
`App\Services\CertificateService` renders a branded A4 PDF (DomPDF) with the QR + validity window.

## 4. Status state-machine (safety-critical)

Owned by `App\Services\RegistrationService`. Every transition writes to **`status_histories`** AND
the Spatie activity log, and (on verify) computes the expiry from the category's validity.

```
        submit / verify
draft ───────────────► pending ──verify──► verified ◄──renew── (expired|suspended)
  │                       │  │                 │  │  │
  │  verify (fast-path)   │  │ suspend/revoke  │  │  └─ suspend ─► suspended
  └───────────────────────┘  └─────────────────┘  └──── expire ─► expired
                                     revoke ──► revoked  (terminal)
```

- `draft` → not public.
- `pending` → profiled, credentials not yet approved ("Registration pending — not yet verified").
- `verified` → green, in good standing.
- `suspended` → amber/red, temporarily not allowed to practise.
- `revoked` → red, struck off (**terminal**).
- `expired` → grey, past validity (auto-set nightly by `registry:sweep-expiry`).

`Registration::verificationResult()` maps the live state (incl. lapsed-but-verified → `found_expired`)
to the key returned by the public API and logged: `found_verified|found_pending|found_suspended|
found_revoked|found_expired|not_found`.

## 5. Service map

| Service | Responsibility |
|---|---|
| `RegistrationService` | Create (mint code + qr_token), status transitions, renew, expiry sweep |
| `VerificationService` | Resolve a code/token → safe public payload; log every lookup; count scans |
| `CredentialService` | Upload (private disk) + approve/reject credential documents |
| `QrService` | Build/cache the QR SVG; the scan URL |
| `CertificateService` | Branded verification-certificate PDF |
| `Channels\VerificationChannel` | Swappable SMS/USSD (LogChannel default, AfricasTalkingChannel for prod) |
| `Support\VerificationCode` | Code format + check character + qr_token |
| `Support\Settings` | Cache-backed key/value app config |

## 6. Roles & permissions (Spatie)

Six staff roles seeded by `RbacSeeder`: **super_admin, registrar, verifier, auditor,
institution_admin, data_clerk**. Permissions gate the back-office; policies
(`RegistrationPolicy`, `CredentialPolicy`, `FraudReportPolicy`) enforce per-action access with a
`super_admin` bypass. The `role` column mirrors the Spatie role via `User::syncSpatieRole()`.

## 7. Security & privacy

- Public verify is **rate-limited per IP** (`throttle:verify`, configurable in settings).
- Codes are **not enumerable** — the QR uses a signed random token.
- Credential documents and fraud evidence live on the **private disk**, streamed only through
  admin-gated routes; never publicly reachable.
- The public payload (`VerificationService::publicPayload`) exposes **professional info only** —
  never national ID, DOB, home address, internal notes, or raw documents.
- Fraud-report form has a honeypot; uploads are validated & size-limited.
- Every status change and profile edit is **double-logged** (status_histories + activity log).

## 8. Automation

- `registry:sweep-expiry` (daily) flips lapsed verified records to `expired`.
- `registry:send-expiry-reminders` (daily) messages soon-to-expire registrants via the channel.
- Heavy work (QR/PDF/mail/SMS/import) is queue-friendly (Horizon + Redis).

## 9. Front-end

- Public: a single dark, blue-accented, no-scroll scan-first home (Alpine + vanilla JS). The camera
  QR scanner lazy-loads `html5-qrcode` only when "Scan QR" is tapped. Result cards cover the six
  states. "Learn more" pages reuse `layouts/public.blade.php`.
- Admin: the reused admin shell (`layouts/admin.blade.php`), retinted to medical blue, with a
  reusable `admin.partials.status-badge` component. Light/dark via `data-theme` (persisted to
  `localStorage` + the `users.theme` column through `/admin/theme`).
