# 07. Robust, secure, compliant

**Goal:** a hospital trusts True-Doctor with its patients and its money. Nothing is lost, nothing leaks, every change can be traced, and the law is met: Uganda's Data Protection and Privacy Act 2019 and its 2021 Regulations, plus medical confidentiality. When something breaks, we know before the clinic calls.

**What exists and stays:**

- security headers with HSTS, a private disk for documents, no-store on signed-in pages;
- throttled sign-in;
- spatie/activitylog (Patient and Visit only today) and append-only histories and ledgers;
- idempotency on money routes;
- `StandaloneBackup` (a PHP dump with triggers), the standalone health command, `composer ci` with the secrets scan;
- in the app: PIN lock, `FLAG_SECURE`, no backups, HTTPS only.

**Verify before building:** legal timelines, retention periods and registration steps are checked against the current Act, the Regulations and Personal Data Protection Office guidance, then recorded with their source in `docs/decisions.md`.

---

## 1. Operations

| Item | Build | Tag |
|---|---|---|
| Scheduler that cannot miss a job | The live cron runs every 19 minutes, so fixed-minute jobs never run (`docs/decisions.md` 2026-10-15). Add a `scheduled_runs` table and make every daily job "due since its last successful run": it runs at the first tick after its time and records the run. `admissions:accrue-bed-charges` charges every missed night, idempotently per admission and night. The standalone guide asks for a cron every minute and the health check warns when ticks are more than 5 minutes apart | P0 |
| SaaS backups | Nightly encrypted dump (reuse the `StandaloneBackup` dump code) plus the private disk, sent off the server to S3-compatible storage. 30 daily and 12 monthly copies kept | P0 |
| Restore drill | A monthly job restores the latest backup into a scratch database, checks row counts and a few checksums, and reports. A backup that was never restored does not count | P0 |
| Health | `td:check` (pattern: Budget Pro `shop:check`) covers the database, the queue backlog (jobs older than 10 minutes), the last scheduler tick, the last backup, disk space, mail, the SMS balance and the gateway key mode. `/health` returns a short JSON for monitors | P0 |
| Error tracking | Sentry or self-hosted GlitchTip on web, queue and app, with personal data scrubbed (names, phones and record text never sent). Each error page shows the reference id (03) | P0 |
| Uptime and status | An external monitor on `/up` and `/health` every minute, with alerts to the owner on WhatsApp and email, and a public status page linked from the site and the app | P0 |
| CI | Unblock it with a self-hosted runner on a small VPS, or another CI. Shard the suite by folder so no process needs more than 512 MB. Run the full suite nightly and require it before any release | P0 |
| Releases | Tagged versions, a `CHANGELOG.md` written for people, a deploy script with migrations and a rollback step, and feature flags for risky features | P1 |
| Performance | Slow query log reviewed weekly, an index review of the busiest tables, N+1 detection in tests, and query budgets per screen (06 §8) | P1 |

## 2. Accounts and access

| Item | Build | Tag |
|---|---|---|
| Passwords | At least 8 characters, letters and numbers, plus a check against known breached passwords on SaaS. Fix `AppServiceProvider.php:150` (6 today) and the claim in `CAPABILITIES.md` | P0 |
| Two-factor sign-in | TOTP for owners, administrators and the super admin (required), optional for others, with a one-time code by WhatsApp or SMS as a fallback. Recovery codes, and "trust this device" for 30 days | P0 |
| Sessions | `SESSION_ENCRYPT=true`, secure and same-site cookies, a shorter idle time on shared desk computers, "sign out everywhere", and a device list per user | P0 |
| API tokens | One token per device, revoked when the password changes or the device is removed, and rotated before the 30 days end | P1 |
| Least privilege | Review the 10 roles against the journeys (06 §1). Remove permissions no journey needs | P1 |
| Break-glass | An emergency "open this record" for a clinician without the usual right. They must give a reason; it is logged, and the owner is notified | P2 |

## 3. Records that cannot be changed quietly

| Item | Build | Tag |
|---|---|---|
| Write audit | Extend activitylog with field-level before and after to every clinical and money model (visits, consultations, diagnoses, orders, results, prescriptions, dispensations, invoices, payments, cards, claims, stock movements, prices, beds, users and roles). Today only 4 to 5 fields of Patient and Visit are covered | P1 |
| Read audit | Log who opened a patient's record, printed or exported it, or opened a result link, with time and device. The owner sees it per patient | P1 |
| Tamper evidence | Each audit row carries the hash of the previous row, so a deleted or edited row is visible | P2 |
| Sensitive fields | Encrypt at rest the fields that would hurt a patient if leaked: HIV status, mental health notes, sexual and reproductive health notes, and any note marked "sensitive". Use encrypted casts, with a blind index where search is needed. Fix the `CAPABILITIES.md` claim, which today says these are encrypted | P1 |

## 4. Data Protection and Privacy Act 2019 (P1)

- **Roles:** on SaaS the clinic is the data controller and SOLAVIA GROUP LIMITED is the processor. On standalone the clinic is both.
- **Registration:** register with the Personal Data Protection Office. Show the registration number on `/privacy`, `/security` and the Organization schema once issued.
- **A Data Processing Agreement** that SaaS clients accept at sign-up, recorded with version and date.
- **A clinic privacy notice for patients:** generated from the clinic's details, printable for the waiting room, and linked from every patient message and link page.
- **Consent records:** per channel and purpose (05 §2).
- **Patients' rights:**
  - **Access:** a one-click export of one patient's record as a PDF and a FHIR bundle (04 §9).
  - **Correction:** through the normal edit, with the audit.
  - **Objection to messages:** STOP and the consent switches.
  - **Deletion:** limited by medical-record retention, so records are anonymised when retention ends instead.
- **Retention:** a policy per record type in config, set from current guidance and recorded with its source. A monthly job anonymises records past retention, after a report to the owner.
- **Breach procedure:** a runbook in `docs/runbooks/breach.md`. It covers how to contain, assess and notify the Office and affected people within the legal time, and who decides. Practise it once a year.
- **A DPIA document** for the SaaS, kept current with each new module that touches patient data.
- **Data location:** say on `/security` where the servers are and what leaves the country (for example email or SMS providers).

## 5. A hospital owns its data (P1)

- **A full export from Settings, by the owner:**
  - a ZIP with a CSV per table, all documents, and FHIR bundles;
  - built by a queued job, with a download link that expires;
  - logged.
- **Leaving:** export, then a scheduled purge after the grace period, with a final notice (the existing `hospitals:purge` gains the notice and the export step).

## 6. Hardening the code and the supply chain (P1)

- `composer audit` and `npm audit` in CI. Dependabot or a scheduled check.
- A Content-Security-Policy header on public pages first, then the back office.
- **An external penetration test** before the first hospital with more than 50 staff. Fix findings and record them.
- **A vulnerability report line** on `/security` (it exists; make sure it goes to a real inbox).

## 7. Make the docs tell the truth (P0)

- Fix these claims:
  - `docs/CAPABILITIES.md`: the password policy, encrypted clinical fields and the audit coverage;
  - `HMS_PLAN.md`: "SMS done";
  - `docs/public-site.md`, `docs/deployment-cpanel.md` and `docs/subscriptions.md`: the exchange rate key and value;
  - `docs/self-onboarding.md`: subscriptions are paid through Pesapal.
- Recount `CAPABILITIES.md` (it was counted on 25 September).
- **A test where it is cheap:** for example, a unit test that reads the password rule and fails if the doc's stated minimum differs.

## 8. Tests

- **Scheduler:** a job missed by a long gap runs once at the next tick; bed charges for three missed nights are charged exactly once each.
- **Backups:** the dump restores into an empty database in the restore drill.
- **2FA:** required for owners; recovery codes work once; trusted devices expire.
- **Audit:** every listed model writes before and after; a record view writes a read entry; the chain detects a removed row.
- **Encryption:** sensitive fields are unreadable in the database and readable through the model; search through the blind index works.
- **Export:** the ZIP holds every table of that hospital and nothing of another (an isolation test).
