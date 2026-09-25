# True-Doctor — what the system does, A to Z

Everything this system can do, grouped the way a hospital actually works
rather than alphabetically. If you want a specific term, use the
[index](#z--index-of-every-capability) at the bottom.

This is a **capability reference**, not a manual. It says what exists and what
the rules are; the linked module docs say how each one is built.

Figures in this document were counted from the codebase on 25 September 2026:
**62 data models · 28 domain services · 69 back-office screens · 35 state
enumerations · 9 printable documents · 1,913 automated tests.**

---

## Contents

| | |
| --- | --- |
| [A. What it is](#a--what-it-is) | The shape of the product |
| [B. Who uses it](#b--who-uses-it-roles-and-permissions) | Nine roles, what each may do |
| [C. Patients](#c--patients) | The register |
| [D. Appointments](#d--appointments-and-scheduling) | The diary |
| [E. Visits](#e--visits-the-spine-of-the-system) | The spine |
| [F. Orders](#f--orders-how-work-is-raised) | How work is raised |
| [G. Laboratory & radiology](#g--laboratory-and-radiology) | Benches |
| [H. Pharmacy & stock](#h--pharmacy-and-stock) | The shelf |
| [I. Prescriptions & dosing](#i--prescriptions-and-medication) | Drugs over time |
| [J. Inpatient care](#j--inpatient-care) | Wards and beds |
| [K. Billing & payments](#k--billing-and-payments) | Money in |
| [L. Insurance & patient cards](#l--insurance-and-patient-cards) | Money from elsewhere |
| [M. Reports](#m--reports-and-dashboards) | What happened |
| [N. Documents](#n--printable-documents) | What gets printed |
| [O. Field Mode](#o--field-mode-working-offline) | When the network fails |
| [P. Multi-tenancy](#p--multi-tenancy-and-isolation) | One system, many hospitals |
| [Q. Security](#q--security-and-audit) | What protects it |
| [R. Public site & sign-up](#r--public-site-and-self-service-sign-up) | The front door |
| [S. Subscriptions](#s--subscriptions-and-plan-limits) | Being paid for |
| [T. Settings & onboarding](#t--settings-and-onboarding) | Making it yours |
| [U. Notifications](#u--notifications) | Telling people |
| [V. API](#v--the-api) | Machine access |
| [W. Scheduled work](#w--scheduled-work) | What runs by itself |
| [X. Correctness](#x--how-correctness-is-enforced) | Why the numbers are right |
| [Y. Limits](#y--what-it-does-not-do) | What it does *not* do |
| [Z. Index](#z--index-of-every-capability) | Everything, alphabetically |

---

## A — What it is

A **multi-tenant hospital management system**. One installation serves many
hospitals; each sees only its own records, enforced in the database layer
rather than by convention.

It covers a patient from the moment they arrive at the gate to the moment the
bill is settled: registration, triage, consultation, laboratory, imaging,
pharmacy, admission, discharge and payment — as **one connected record**, with
nothing re-typed between departments and nothing to reconcile at the end of the
day.

**Built on** Laravel 12, Livewire 3, MySQL 8 / MariaDB, PHP 8.3.
**Runs on** anything from shared cPanel hosting to a dedicated server.
**Works offline** through Field Mode (§O).

---

## B — Who uses it (roles and permissions)

Nine staff roles, each with its own menu, its own screens and its own fields.
The same rules are enforced in the web panel and in the API, from one
definition ([`RbacSeeder`](../database/seeders/RbacSeeder.php) — 10 roles, 49
permissions).

| Role | Can do | Notably cannot |
| --- | --- | --- |
| **Hospital admin** | Everything in their hospital | See another hospital |
| **Doctor** | Diagnose, prescribe, order labs and imaging, admit, manage visits | Record vitals as triage, dispense, process lab work |
| **Nurse** | Vitals, nursing notes, medication administration, ward rounds, treatments | Record a diagnosis, prescribe |
| **Receptionist** | Register patients, book appointments, open visits, take payments, issue cards | Open clinical notes, diagnose |
| **Pharmacist** | Dispense against prescriptions, manage stock | Diagnose, bill |
| **Lab technician** | Collect specimens, process and report lab orders | Order tests, see billing |
| **Radiologist** | Perform and report imaging studies | Order studies, see billing |
| **Accountant** | Invoices, payments, insurance, price list, financial years, reports | Open clinical notes |
| **Records officer** | Register, update and archive patient records | Anything clinical or financial |

A tenth role, **super admin**, sits above all hospitals and administers the
platform itself (§P). It belongs to no hospital and never appears in a
hospital's staff list.

Each role also gets **its own dashboard** — eleven variants, so a pharmacist
opens onto low stock and a doctor onto their queue.

→ [docs/rbac.md](rbac.md)

---

## C — Patients

**The register.** Every person the hospital has ever seen.

- **Registration** with a generated, gap-free patient number (`PT-2026-000123K`,
  including a check character) allocated under a row lock so two clerks
  registering at once cannot collide.
- **Demographics** — names, sex, date of birth, phone numbers, email, address,
  district.
- **Clinical background** — blood type, allergies, chronic conditions, notes
  that surface on every screen a clinician opens.
- **Next of kin** and emergency contact.
- **Dependents and guardians** — a child's record linked to the adult
  responsible for it.
- **Documents** — scans and photographs on a private disk, streamed through an
  authorization policy, never served directly.
- **Patient cards** — prepaid and insurer member cards (§L).
- **Insurance coverage** — one patient, several policies.
- **Treatment records** — procedures performed outside a visit, with photographs.
- **QR ID cards**, printable, for fast lookup at the desk.
- **Status** — active, inactive or deceased; an inactive patient is findable but
  not bookable.
- **Duplicate detection** on registration.

→ [docs/patients.md](patients.md)

---

## D — Appointments and scheduling

**The diary.**

- **Doctor availability** — recurring weekly windows per doctor, with a slot
  length and an optional room.
- **Booking with conflict detection** — a doctor or a room cannot be
  double-booked. Concurrent bookings are serialised by an atomic lock per
  doctor per day, because row locks on an empty range do not prevent a
  simultaneous insert.
- **Slot alignment** — a booking must sit on the grid the availability window
  defines.
- **Sources** — walk-in, phone, online, referral.
- **A check-in queue** for the front desk.
- **Status flow**, enforced and never skippable:
  `Scheduled → Confirmed → Checked in → In progress → Completed`,
  with `No-show` and `Cancelled` reachable from the appropriate points.
- **An appointment cannot be completed without an outcome.** Completing it
  *is* saving the write-up, which opens the visit and raises the consultation
  order. There is no way to mark an attendance finished that records nothing.
- **Follow-ups booked from inside a visit**, carrying that visit with them, so
  the appointment and the visit can never name different patients.
- **Reminders** sent the day before.

→ [docs/scheduling.md](scheduling.md)

---

## E — Visits: the spine of the system

A **visit** is one patient attendance, and everything that happens that day
hangs off it. It is the single thing the rest of the system is organised
around.

Each visit carries a generated number (`V-20260914-001`) and moves through an
**enforced stage machine**:

```
Ongoing  ──▶  Billing  ──▶  Payment  ──▶  Completed
```

Each arrow has a **gate that must be open**, and the screen always says why one
is shut:

| Move | Requires |
| --- | --- |
| Ongoing → Billing | No order still open |
| Billing → Payment | A live (non-void) invoice exists |
| Payment → Completed | Balance is zero |

Two of those are a judgement and stay a button. The last is nobody's decision:
**a visit completes itself the moment the balance reaches zero**, and a visit
can never close owing money.

A visit also records:

- **Vitals** — temperature, blood pressure, pulse, respiratory rate, SpO₂,
  weight, height, and **BMI computed** rather than typed.
- **Complaints, examination, diagnosis and plan.**
- **Separate remark fields** for the receptionist, the doctor and the patient.
- **Full status history**, append-only, never edited in place — including who
  moved it and why.
- **Role-specific views over the same visit** — the nurse, the doctor, the
  cashier and the pharmacist each see their own panel of one record.
- **Outcome** on close.

→ [docs/visits.md](visits.md) · [docs/consultations.md](consultations.md)

---

## F — Orders: how work is raised

Everything a visit generates is an **order** on that visit. One mechanism, six
types: `Consultation`, `Lab`, `Imaging`, `Pharmacy`, `Procedure`, `Admission`.

- Each order has a **status** (`Pending → In progress → Completed`, or
  `Cancelled`) and a full history.
- Each order carries **items** — a price-list service or a stock product —
  which become invoice lines.
- **The charge is raised when the order is**, not at the end. There is no
  reconciliation step where somebody tries to remember what was done.
- **Items can be corrected** with a reason recorded, and taking a product line
  off puts the stock back in the same transaction.
- **Attachments** — reports, scans and photographs on a private disk.
- **Stock reconciliation** runs when a visit moves on: the shelf is checked
  against the bill, and a difference is posted only if one exists.

→ [docs/orders.md](orders.md)

---

## G — Laboratory and radiology

**Ordered from inside the visit, worked on the bench, and the result comes back
to the same visit.**

**Laboratory**
- A **test catalogue** with specimen type, unit, reference range and price.
- Orders move `Ordered → Collected → Processing → Completed` (or `Cancelled`).
- **Results per item** with a value, an **abnormal flag** (normal, low, high,
  abnormal) and technician notes.
- **Attachments** for machine printouts.
- A **printable report** on the hospital's own letterhead.
- **Result-ready notifications.**

**Radiology**
- A **study catalogue** with modality, body part and price.
- Orders move `Ordered → Scheduled → Performed → Reported` (or `Cancelled`).
- **Findings and impression** recorded separately, as a radiologist writes them.
- **Attachments** for images.
- A **printable report.**

→ [docs/lab-radiology.md](lab-radiology.md)

---

## H — Pharmacy and stock

**The shelf, and what comes off it.**

- **Stock items** with unit, cost price, sale price, reorder level and expiry
  date, grouped into categories.
- **Live valuation** — what the shelf is worth, maintained as stock moves.
- **An append-only movement ledger.** Eleven reasons, every one recorded:
  opening stock, received, returned, adjustment in, dispensed, wastage,
  adjustment out, expired, damaged, lost, returned to supplier.
- **Dispensing against a prescription**, off the shelf, **with the stock
  movement in the same database transaction as the sale** — a balance and the
  ledger that explains it can never disagree because something failed halfway.
- **Low-stock alerts** when an item reaches its reorder level.
- **Expiry alerts** at 90 days.
- **Adjustments require a reason**; stock never changes silently.

→ [docs/pharmacy.md](pharmacy.md)

---

## I — Prescriptions and medication

- **Structured prescriptions** — drug, dosage, days, and the slots in the day
  it is taken (morning, afternoon, evening, night).
- **A dosing schedule generated** from that: one row per dose per day for the
  length of the course.
- **Administration tracking** — each dose recorded as given, missed or refused,
  by whom and when.
- **Missed doses are visible rather than inferred** from an absence.
- Prescribing and administering are **separate permissions**: a doctor
  prescribes, a nurse administers, and neither can do the other's half.

---

## J — Inpatient care

**A stay is an order on a visit**, not a separate thing floating beside it.

- **Wards and beds**, each bed with its own nightly charge, and a ward-level
  default that can be applied to every bed in it at once.
- **A live occupancy board** — which beds are free, occupied or out of service.
- **Admission** places an order on the visit, in progress from the moment the
  patient is in the bed, and it holds the visit's gate shut until discharge.
- **Per-night billing.** Each night is billed the following morning **at the
  rate in force that night**, as a separate line naming the bed and the date:
  `Bed charge — B-04, night of Tue 15 Sep 2026`. Not estimated at discharge and
  not rate × duration — because a patient moved between wards mid-stay has no
  single rate to multiply by.
- **Bed transfers**, recorded.
- **Nursing notes** and **vital rounds** on the ward.
- **Medication administration** at the bedside.
- **Discharge** with an outcome (discharged, deceased, absconded) and a
  **printable discharge summary**.

→ [docs/inpatient.md](inpatient.md)

---

## K — Billing and payments

- **A price list** per hospital, with tax-exempt items marked.
- **Invoices built from what was actually done** — every order item already on
  them, nothing re-entered.
- **Discounts** at the invoice level.
- **Tax** — configurable label and rate per hospital, on or off.
- **Invoice statuses**: draft, issued, partially paid, paid, void.
- **Six payment methods**: cash, card (a patient's prepaid card), mobile money,
  bank transfer, Flutterwave (online), and insurance (settled by a claim).
- **Part payments**, with the balance tracked.
- **Overpayment is refused**, not absorbed.
- **Every posting runs inside a database transaction with the rows locked** —
  a payment and the invoice balance it changes can never half-update.
- **Money is exact decimal arithmetic**, never floating point. A hundred
  invoices add to the same total whichever order you add them in.
- **Financial years** that can be **closed to stop back-posting** into a period
  already reported on.
- **Printable invoices and receipts.**
- **Online payment** through Flutterwave for patient invoices, with a verified
  webhook.

→ [docs/billing.md](billing.md) · [docs/financial-years.md](financial-years.md)

---

## L — Insurance and patient cards

Two different arrangements, both supported, because hospitals use both.

**Insurance claims**
- **Providers** with a code and a default credit limit.
- **Patient coverage** — which policies a patient holds.
- **Claims against an invoice**, moving `Draft → Submitted → Approved → Paid`,
  or `Rejected` / `Cancelled`, with full status history.
- **A printable usage statement per insurer.**

**Patient cards**
- **Prepaid cards** with a proper ledger — every top-up and debit recorded.
- **Insurer member cards** that draw against a float the insurer deposited.
- **Family cards** — several people may spend one balance, and each debit is
  stamped with *who it was spent on*, not just whose card it is. A mother's
  card can pay for her child.
- **Credit limits** per card, or inherited from the insurer.
- **Card numbers encrypted at rest** and additionally stored as a hash for
  lookup — never held in the clear, and never in a URL.
- **Bulk clearing** of members in debt against the insurer's float.

→ [docs/insurance.md](insurance.md) · [docs/cards.md](cards.md)

---

## M — Reports and dashboards

Any date range, shareable as a link, and **printable as a single PDF** for a
board meeting.

| Report | Shows |
| --- | --- |
| **Revenue** | Total received, by payment method, and day by day |
| **Outstanding** | What is owed, **aged** into 0–7 / 8–30 / 31–90 / over 90 days |
| **Inpatient nights** | Nights and revenue by ward, read from what was actually charged |
| **Service revenue** | What was sold, how often, for how much |
| **Doctor activity** | Visits and appointments per doctor |
| **Stock valuation** | Shelf value, low-stock count, expiring count |
| **Bed occupancy** | Occupied, free, and the rate |
| **Demographics** | The register by sex and by age band |

Period figures and standing positions are **labelled apart** throughout —
revenue happened inside the window; occupancy is how things are right now.

Eleven **role-specific dashboards**, so each member of staff opens onto their
own work.

→ [docs/reports.md](reports.md) · [docs/dashboards.md](dashboards.md)

---

## N — Printable documents

Nine documents, all on **the hospital's own letterhead** — its logo, address,
phone, email and licence number, set once in settings and applied to all of
them.

Invoice · Receipt · Lab report · Radiology report · Discharge summary ·
Patient ID card (with QR) · Insurance usage statement · Visit report ·
Board report

Every one **opens in a tab rather than downloading**, because a document is
read before it is kept.

→ [docs/documents.md](documents.md)

---

## O — Field Mode: working offline

The part that separates this from a system that needs the network to exist.

An outreach clinic, a ward on a bad line, a power cut at the exchange — the
work does not stop for any of them.

- **A local copy** of what that member of staff is allowed to see, held on the
  device.
- **Capture offline**: registrations, vitals, clinical notes, lab results, ward
  rounds.
- **An outbox** that sends when the connection returns.
- **Three-way merge** on sync, with revision cursors so a device asks "what has
  changed since?" and gets an answer that includes what the online panel did.
- **Conflicts are shown to a person, never guessed at.**
- **Nothing unsent is ever deleted automatically**, and the screen always says
  how much is waiting.
- **No password is ever stored on the device.**
- **The device is never treated as trusted** — the server re-checks every
  permission when work is sent back, and the client-side permission check is a
  convenience, not a boundary.
- **A readiness screen** that tells non-technical staff, before they leave,
  whether the device is actually ready: storage, clock, cached shell, pending
  work, session.
- **The clock corrects itself** from the server rather than asking anybody to
  fix it.

→ [docs/OFFLINE_ARCHITECTURE.md](OFFLINE_ARCHITECTURE.md) ·
[docs/OFFLINE_SYNC_PROTOCOL.md](OFFLINE_SYNC_PROTOCOL.md) ·
[docs/OFFLINE_SECURITY.md](OFFLINE_SECURITY.md)

---

## P — Multi-tenancy and isolation

- **Every record carries its hospital** and is filtered by a global scope
  applied at the database layer — not by a `where` clause each developer has to
  remember to write.
- **The tenant is resolved before route-model binding**, so a URL cannot be
  made to bind another hospital's record.
- **A dedicated test suite exists solely to try to read across that boundary
  and fail.**
- **Per-hospital settings**: currency, decimals, tax label and rate,
  consultation fee, invoice prefix, letterhead, timezone.
- **A super-admin panel** above all tenants: hospitals, plans and subscriptions.

→ [docs/tenancy.md](tenancy.md) · [docs/super-admin-panel.md](super-admin-panel.md)

---

## Q — Security and audit

- **No default passwords.** The first administrator is created with a random
  password printed once to the console and a forced change at first sign-in.
  Staff are invited the same way.
- **Password policy**: minimum 8 characters, letters and numbers.
- **Rate limiting** on sign-in — five attempts, then a timed lockout.
- **Sessions last 90 days** when "keep me signed in" is ticked (it is, by
  default). The cookie is HttpOnly, tied to one user, and cycled the moment
  anybody signs out of that account anywhere.
- **Session fixation** prevented — a new session id on privilege change,
  issued only after the account checks pass.
- **Encryption at rest** for card numbers, bank details and protected clinical
  fields.
- **Change auditing** — who changed a patient or visit record, when, and what
  the value was before. **Read access is not logged** (see §Y).
- **Append-only status histories** on every workflow — a visit's stages, an
  appointment's attendance, a claim's progress, an order's status — never
  edited in place.
- **One API authentication mechanism** — Sanctum, token-based, versioned,
  rate-limited, origin-locked, with one response envelope. No second path and
  no legacy bypass.
- **Secrets live only in server configuration**, never in code, and a CI
  secrets scan fails the build if one is ever staged.
- **A published vulnerability disclosure policy.**

→ [docs/auth.md](auth.md) · [docs/OFFLINE_SECURITY.md](OFFLINE_SECURITY.md)

---

## R — Public site and self-service sign-up

Seven public pages — home, product, pricing, security, contact, privacy, terms
— plus a generated `sitemap.xml`.

- **Self-service sign-up**: a hospital creates its own account and starts a
  **14-day trial with every module on and no card taken**.
- **Currency by region.** Plan prices are stored in shillings; a reader outside
  East Africa is quoted in US dollars at a fixed platform rate. Four signals in
  order — a currency the visitor chose, a country header from the CDN, an
  optional IP lookup (off by default, because it sends visitor addresses to a
  third party), and the browser's language region.
- **A currency switch on every page**, so a wrong guess is always fixable in
  one click, and it works with JavaScript blocked.
- **A working contact form** — validated, rate-limited, honeypot-protected,
  emailing a real address.
- **A live demonstration** at `/test-login`, listing seeded hospital roles —
  and existing **only** where those accounts exist, so a real deployment gives
  no hint such a door can exist. The platform super admin is never on it.
- **Structured data** (SoftwareApplication, FAQPage) for search engines.
- **Motion that cannot hide content** — reveals are scoped to a class added by
  script and removed again if the script never arrives; `prefers-reduced-motion`
  removes them rather than shortening them.

→ [docs/public-site.md](public-site.md) · [docs/self-onboarding.md](self-onboarding.md)

---

## S — Subscriptions and plan limits

- **Three plans** — Starter, Professional, Enterprise — each with limits on
  staff accounts, patients and beds. A missing limit means unlimited.
- **Priced per hospital, not per seat.** Adding the night nurse does not change
  the bill.
- **A 14-day trial** on every plan.
- **Limits enforced in code** (`PlanLimit`), not just advertised.
- **A grace period** after a subscription lapses rather than cutting off at
  midnight; then access pauses. **Nothing is deleted**, and data can still be
  exported.
- **Online checkout** through Pesapal, settled in shillings.
- **Trial-ending and activation notifications.**

→ [docs/subscriptions.md](subscriptions.md) · [docs/plan-limits.md](plan-limits.md)

---

## T — Settings and onboarding

**A guided setup checklist** for a new hospital — eight steps, each of which
can be done and re-done: profile, departments, rooms, wards and beds, services
price list, catalogues (lab tests and imaging studies), billing configuration,
and staff accounts.

**Settings** cover the hospital profile and letterhead, billing (currency,
decimals, tax label and rate, consultation fee, invoice prefix), and staff
management.

→ [docs/onboarding.md](onboarding.md) · [docs/staff-org.md](staff-org.md)

---

## U — Notifications

Appointment reminders · Lab result ready · Low stock alert · Trial ending ·
Subscription activated · Welcome to trial · Email verification · Password reset ·
Public enquiry received

**Every notification is queued**, so a mail provider having a bad afternoon
cannot turn somebody's sign-up into a 500. A scheduled worker drains the queue
each minute.

→ [docs/notifications.md](notifications.md)

---

## V — The API

One versioned, token-authenticated REST API under `/api/v1`, with a published
**OpenAPI description** at `/api/v1/openapi.json`.

| Area | Endpoints |
| --- | --- |
| Auth | `login`, `logout`, `me` |
| Patients | list, create, show, update, delete |
| Visits | list, create, show, vitals, clinical, transition |
| Appointments | list, create, show, transition |
| Lab orders | list, show |
| Invoices | list, show |
| Stock | list, show |
| Sync | `register`, `status`, `pull`, `push`, `ack`, `operations`, `reference` |

Every response uses **one envelope** — `{success, code, message, data, errors}`
— and clients branch on the stable machine-readable `code`, never on the
message. Rate limited at 60 requests a minute.

→ [docs/api.md](api.md)

---

## W — Scheduled work

Six jobs, driven by a single cron entry:

| When | What |
| --- | --- |
| Every minute | Drain the queue (short-lived worker, not a daemon) |
| 00:05 daily | Add last night to every open inpatient stay's bill |
| 07:00 daily | Appointment reminders |
| 08:00 daily | Trial-ending reminders |
| Daily | Flush expired password-reset tokens |
| Weekly | Prune failed jobs older than a week |

---

## X — How correctness is enforced

A hospital buying clinical software is buying the promise that the numbers are
right. That rests on:

1. **Money never half-moves.** Every payment, refund, dispensation and stock
   adjustment runs inside a database transaction with the affected rows locked.
2. **No floating-point money.** Exact decimal arithmetic throughout.
3. **Gap-free, race-free numbering.** Patient, visit, invoice and claim numbers
   are allocated under a row lock, not by counting existing rows.
4. **States cannot be skipped.** Visits, orders, claims, appointments and
   admissions each move through a defined set of transitions, refused at the
   service layer so no screen, controller or API can bypass them.
5. **Append-only histories.** Status trails are never edited in place.
6. **~1,900 automated tests** run against every change, including a suite whose
   only job is to prove one hospital cannot read another's records.

→ [docs/testing.md](testing.md) · [docs/decisions.md](decisions.md)

---

## Y — What it does not do

A capability list that claims everything is worth nothing. As of today:

- **No certification.** Not audited against HIPAA, ISO 27001 or SOC 2, and it
  is not described as compliant with any of them.
- **No uptime SLA.** Reasonable measures, honestly reported outages, no
  contractual guarantee.
- **Two-factor authentication is not shipped.** Planned for administrator
  accounts. Until then: forced password change, per-attempt rate limiting, and
  a revocable session.
- **No data-residency guarantee** unless arranged — where the application and
  its backups live depends on the deployment.
- **Not a medical device.** It is a record-keeping and administration system.
  It does not practise medicine or make clinical decisions; clinical judgement
  remains entirely with the practitioner.
- **Three Field Mode workflows are not yet built** — dispensing intents,
  appointment check-in, and opening a visit offline.
- **Offline attachments** are not yet supported.
- **Read access is not audited.** Changes are recorded with who made them and
  what the value was before, and every workflow keeps an append-only history —
  but a member of staff opening a patient record and closing it again leaves no
  entry. If a regulator requires read auditing, this is a real gap.

---

## Z — Index of every capability

**A** — Admissions (§J) · Allergies (§C) · Appointments (§D) · Attachments
(§F, §G) · Audit trail (§Q) · API (§V)
**B** — Beds (§J) · Bed transfers (§J) · Billing (§K) · BMI (§E) · Blood type (§C)
**C** — Cards, patient (§L) · Check-in queue (§D) · Claims (§L) · Conflicts,
sync (§O) · Currency, per hospital (§P) · Currency, by visitor region (§R)
**D** — Dashboards (§M) · Demographics (§M) · Dependents (§C) · Discharge (§J) ·
Discharge summary (§N) · Discounts (§K) · Dispensing (§H) · Documents, patient
(§C) · Documents, printable (§N) · Dosing schedule (§I) · Doctor availability (§D)
**E** — Encryption at rest (§Q) · Expiry alerts (§H)
**F** — Family cards (§L) · Field Mode (§O) · Financial years (§K) ·
Flutterwave (§K)
**G** — Grace period (§S)
**I** — ID cards (§C, §N) · Insurance (§L) · Inpatient (§J) · Invoices (§K) ·
Isolation, tenant (§P)
**L** — Laboratory (§G) · Letterhead (§N) · Low-stock alerts (§H)
**M** — Medication administration (§I) · Mobile money (§K) · Multi-tenancy (§P)
**N** — Notifications (§U) · Nursing notes (§J)
**O** — Occupancy board (§J) · Offline (§O) · Onboarding (§T) · OpenAPI (§V) ·
Orders (§F) · Outstanding debt, aged (§M)
**P** — Patients (§C) · Payments (§K) · Per-night bed billing (§J) ·
Permissions (§B) · Pharmacy (§H) · Plans and limits (§S) · Prescriptions (§I) ·
Price list (§K) · Public site (§R)
**Q** — QR codes (§C) · Queue (§U, §W)
**R** — Radiology (§G) · Rate limiting (§Q, §V) · Reference ranges (§G) ·
Reports (§M) · Roles (§B)
**S** — Scheduled jobs (§W) · Sessions, 90-day (§Q) · Settings (§T) ·
Sitemap (§R) · Stock (§H) · Stock movements (§H) · Subscriptions (§S) ·
Super admin (§P) · Sync (§O)
**T** — Tax (§K) · Treatment records (§C) · Trial (§S)
**V** — Valuation, stock (§H) · Visits (§E) · Vitals (§E) · Vital rounds (§J)
**W** — Wards (§J)

---

*Everything above is implemented and covered by tests. For how a module is
built rather than what it does, follow the link at the end of its section.*
