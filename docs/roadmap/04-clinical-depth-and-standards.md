# 04. Clinical depth and standards

**Goal:** the record speaks the world's languages underneath (ICD, LOINC, FHIR) and the country's on top (HMIS forms, UNEPI schedule, EFRIS, local insurers). On screen, the clinician still sees plain words and picks from short, smart lists.

**Principle:** coded underneath, plain on screen.

- Every structured field keeps a free-text companion, and nothing a clinician typed before is lost.
- A code is a choice made with the clinician's help, never a form they must fight.

**Verify before building:** schedules, form layouts and code lists change. Every country list in this file is loaded from config or seeded reference data, with its source and date recorded. Before release, it is checked against the current Ministry of Health (Uganda) tool, the WHO list, or the URA specification.

---

## 1. Coded diagnoses (P1, the foundation for §2)

**Today:** `consultations.diagnosis` is one text column.

**Build:**

- **A `visit_diagnoses` table:**
  - visit, code system (`icd10` now, `icd11` ready), code and label;
  - primary or secondary, and confirmed or suspected;
  - the free-text note, recorded by and at.
  - It is append-only, with corrections as new rows (same pattern as the visit status history).
- **A reference catalogue, seeded and shipped with the app's reference data** (the app already refreshes reference data every 12 hours):
  - the WHO ICD-10 list;
  - the HMIS 105 disease list mapped to ICD-10 codes;
  - local synonyms in English, Luganda and Swahili ("omusujja" → malaria).
- **The picker:**
  - type plain words and see the five best matches;
  - the practice's most-used diagnoses come first;
  - one tap to choose.
  - Reuse the writing-aid pattern (`app/Support/VisitPhrases.php`, `visits/phrases` endpoint) so the same list works offline in the app.
- **Keep the free-text diagnosis.** Show "not coded yet" visits on a small worklist so a records officer can code them later.
- **Backfill:** a command that suggests codes for past free-text diagnoses. A person confirms each one; nothing is coded automatically.

## 2. Ministry reports: HMIS and DHIS2 (P1)

- **HMIS 105** (monthly outpatient): attendances by age band and sex, new and re-attendance, diagnoses by the HMIS list, referrals, lab tests done, maternal and child health counts.
- **HMIS 108** (monthly inpatient): admissions, discharges, deaths, bed days and diagnoses.
- **HMIS 033b** (weekly surveillance): the notifiable conditions counted per week.

Each report:

- is a method in `app/Services/ReportService.php`;
- prints as a PDF laid out like the paper form (new templates in `resources/views/pdf/`);
- exports as CSV;
- uses the age bands and the list version in config (`config/hmis.php`);
- has a "missing data" panel (visits without age, sex or a coded diagnosis) with links to fix them.

**Notifiable disease alert:** when a coded diagnosis is on the notifiable list, the facility in-charge gets an alert to report it to the district surveillance focal person. This is a new Insight rule in `app/Support/Insights/Rules/`.

**DHIS2 push (P2):** for facilities that have credentials, send the monthly data set to the district DHIS2 through its API (`dataValueSets`). Show a preview and require a person's confirmation; never send silently.

## 3. Dental (P0, our first standalone client is a dental clinic)

**Today:** `Packs/Dental.php` is a price list, drugs and phrases. The `treatment_records.meta` column was meant for a tooth map and is unused.

**Build a real dental chart:**

- **Odontogram in FDI notation:**
  - adult teeth 11 to 48, child teeth 51 to 85, with a switch for mixed dentition;
  - tap a tooth, then a surface (M, O, D, B or L, I).
- **Per-tooth findings:** caries, filled, missing, to extract, crown, bridge, implant, root canal, fracture, mobility grade, and notes.
- **History:** a `dental_chart_entries` table that is append-only, with date and author. The chart shows the current state and any earlier date.
- **Treatment plan:**
  - planned procedures per tooth and surface, chosen from the clinic's price list (`services`), including price ranges (already supported);
  - statuses: planned → accepted → in progress → done;
  - a quote PDF for the patient.
- **Billing link:** completing a procedure raises the charge through the existing `OrderService` and `BillingService`, so nothing is billed twice and nothing is forgotten.
- **Attachments:** X-rays and photos per tooth, on the private disk (the documents pattern that exists).
- **Consent:** a printable consent form per procedure type, with the signature captured on the tablet (P1).
- **Recall:** a "next check-up" date per patient (default 6 months) that feeds patient messages (05).
- **Reports:** procedures by dentist, by type and by month; treatment plans accepted vs done; revenue per chair.
- **The app:** the chart works on a tablet in landscape and offline (the read-through copy, writes through the outbox).
- **Perio chart (P2):** pocket depths, bleeding and recession.

## 4. Maternity and child health (P1)

**Pregnancy record:**

- LMP, EDD (calculated, editable), gravida and para;
- risk factors;
- a birth plan;
- the ANC contacts schedule (from config, following the current Uganda ANC guideline), with a checklist per contact (BP, weight, fundal height, tests, supplements, vaccination).

The record shows what is due at this visit and what was missed.

**Delivery and postnatal:**

- the delivery record (mode, outcome, baby details);
- creating the baby's own patient record linked to the mother;
- the postnatal visits schedule;
- **partograph (P2).**

**Immunisation:**

- the national schedule loaded from config (UNEPI), with the vaccine, dose, age due and the minimum interval;
- per child, a due, overdue and given list;
- a daily "due today" list;
- reminders to the caregiver (05);
- the register export for the monthly report.

**Growth:** weight, height and MUAC for children under 5, plotted against the WHO growth standards, with a flag for faltering.

## 5. Other specialties: forms, not just price lists (P1 to P2)

| Practice | Structured record | Tag |
|---|---|---|
| Eye | Visual acuity (each eye, with and without correction), refraction (sphere, cylinder, axis, add), intraocular pressure, spectacle prescription PDF | P1 |
| Chronic care (hypertension, diabetes) | BP and glucose trends with targets, refill due dates, missed review list | P1 |
| Physiotherapy | Treatment plan in sessions, package billing, progress score | P2 |
| ENT, dermatology, mental health | Short structured exam templates per pack, with sensitive notes protected (07) | P2 |

**Pattern:** a specialty form is a template registered by its pack (`app/Support/Practice/Packs/*`) and stored as structured JSON with a schema version. It is never a new table per specialty unless it needs its own queries (as dental and immunisation do).

## 6. Laboratory and imaging (P1)

- **Codes:** an optional LOINC code on each test in the catalogue, filled for the common tests at seeding.
- **Critical values:** a result outside the critical range alerts the ordering clinician at once (a notification with high priority), and the alert is logged.
- **Results to the patient:** a released result can be sent by WhatsApp or SMS as a signed link (05), with a QR on the PDF for verification.
- **Analyser link (P2):** a small bridge that reads results from common analysers (HL7 v2 or ASTM over serial or TCP) into the lab bench. Results are never posted unreviewed.
- **Lab tombstones:** close the known gap so deleted lab items sync correctly to the app.

## 7. Pharmacy and stock (P1)

- **Procurement:** suppliers, purchase orders, goods received with batch and expiry, supplier invoices posted to the books, and returns to supplier. Today "supplier" is only a ledger account.
- **Medicine codes:** the Uganda essential medicines list as reference data, with its level of care.
- **Safety:** an allergy check at prescribing (allergies already exist on the patient), and a basic interaction list (P2).
- **Controlled drugs register (P2):** a separate register with running balance and two-person sign-off.

## 8. Billing, fiscal and insurance (P1)

- **Refunds and credit notes:** a credit note against an invoice, a refund as a payment out, and both posted to the books with reasons and approval. Today there are none.
- **EFRIS (URA e-invoicing)** for VAT-registered facilities:
  - send fiscal invoices and receipts, and print the fiscal number and QR on the receipt;
  - most medical services are exempt but some items are not, so the tax class sits per service.
  - **Reuse Budget Pro's fiscal framework** (`htdocs/budget-pro`: the EFRIS connector and the manual adapter) as the pattern: a connector interface, a manual adapter for facilities not on EFRIS, and queued submission with retries.
- **Insurers:**
  - claim form PDFs per insurer (the common Ugandan insurers first);
  - pre-authorisation requests;
  - batch submission;
  - rejected claims with reasons and resubmission.
  - Smart card integration where insurers use it (P2).
- **National health insurance:** keep the insurance model general enough that a national scheme becomes one more payer when it starts.

## 9. Interoperability (P2)

- **FHIR R4 read API** behind its own permission and token scope: Patient, Encounter, Condition, Observation, MedicationRequest, DiagnosticReport. Every read is logged in the audit.
- **Export one patient's record** as a FHIR bundle and as a PDF, for the patient's right of access (07) and for referrals.
- **Structured referral letter:**
  - reason, coded diagnosis, findings and treatment given;
  - a QR to verify it;
  - received referrals open as a pre-filled registration.

## 10. Clinical safety (P0 to P1)

- An **allergy and alert banner** on every clinical screen, web and app (P0).
- **Weight-based dose help for children** at prescribing (P2).
- **"Results ready, not seen"** list per doctor (the next-actions service already has part of it) (P1).
- Every clinical form **keeps drafts** and never loses text. The app already does this; make the drafts encrypted (08).

## 11. Definition of done for this file

- Coded diagnoses live, with offline pickers and a backfill worklist.
- HMIS 105, 108 and 033b print and export from real data with a missing-data panel.
- The dental chart is in use at Divine Dental, with treatment plans billing correctly.
- Pregnancy, immunisation and growth records work for maternity and children's practices.
- Refunds, credit notes and procurement are in the books.
- The EFRIS connector works, with the manual adapter.
- Every new tenant table has an isolation test, every money path is idempotent and in a transaction, and each module has its doc in `docs/`.
