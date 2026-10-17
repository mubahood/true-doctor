# Divine Dental Clinic: their review of the system, and what we do about it

Received 17 October 2026 ("Review of the clinic system", 4 pages, with a sample receipt and visit report). Divine Dental Clinic, Ntinda, runs its own copy at version 1.0.7. This file records what they said, what they actually need, what the system already has, and the plan. Read it before working on anything dental.

## 1. What they tested and what they said

They put **two sample patients** through the normal work of a dental clinic: registration, history and examination, tooth conditions, diagnoses, treatment plans, appointments (book, move, cancel), charges and payments, and the reports.

| Area | Their verdict |
|---|---|
| Patient registration | Works: captured, saved, updated, found |
| Dental charting | **Does not** record the condition of individual teeth accurately |
| Treatment management | **Does not** record diagnoses, planned procedures, completed treatment and follow-up instructions correctly |
| Appointments | Works |
| Billing and payments | Works, including discounts |
| Reports | Work (patient, appointment, treatment, visit) |
| Security | Access controls protect confidential information |

Their recommendations, in their words:

1. Improve the doctor's clinical note so that it presents the **patient history** and the **oral examination**.
   - History: **complaint, dental history, medical history, surgical history, financial history**.
   - Oral examination: **hard tissues, soft tissues, X-ray, lab test, diagnosis, treatment done**.
2. Be able to **import data from Excel** or from outside the system.
3. **Limit access** to specific roles of the staff.

## 2. Reading between the lines

**The two failures are features they have never had.** Version 1.0.7 has no dental chart and no treatment plan. Both were built on 17 October and ship in 1.0.8 (FDI chart with surfaces, child chart, plan, quote, billing on the open visit, recall). They tested a general clinic's visit screen and, correctly, found it does not do dentistry. The first fix is to **deliver 1.0.8 and walk them through the chart** (section 5), not to rebuild it.

**What they really want is a dental record that reads like a dental record.** The recommendation lists the headings of the standard dental case sheet taught in dental school and used on paper in Ugandan practices: history (presenting complaint, dental, medical, surgical, social or financial), examination (extra-oral, intra-oral soft tissues, hard tissues), investigations (X-ray, lab), diagnosis, treatment done, and plan. Today our visit has three free boxes (complaints, diagnosis, doctor's remarks). A dentist cannot put a case sheet into three boxes, and the printed visit report shows it: "Reason: Toothache. Complaints: Toothache. Diagnosis: Grossly carious 35." The tooth number is typed as text, so nothing links it to tooth 35 on a chart.

**"Financial history" in a private dental clinic** means: who pays (self, family, employer, insurer), what they owe from before, how they paid in the past (in full, in instalments, defaulted), and what was quoted and agreed for this course of treatment. Most of it is already in the system as billing data; it only needs to be shown in the history, not typed again.

**"Import from Excel"** almost certainly means their existing patient list, and probably the price list and stock they keep in spreadsheets. A clinic that cannot bring its patients in starts every returning patient as new, which is the first thing that makes staff give up on a system.

**"Limit access to specific roles"** while also writing "security: access controls protect confidential information" suggests the controls work but are too broad for them: for example the receptionist can open the doctor's clinical notes, or a doctor can see the money. Our roles are fixed in code; the clinic cannot change them.

## 3. Problems visible in the samples they attached

| Seen | Cause | Status |
|---|---|---|
| "Page 1 of 0" in the footer of the receipt and the visit report | the PDF library cannot count total pages | **Fixed** (now "Page 1") |
| Em dash in the receipt's closing sentence | our wording | **Fixed** |
| "No department recorded" beside the doctor on the visit report | the visit was opened without a department; a dental clinic has one | Default the clinic's only clinical department on new visits (section 6, item F) |
| "Status Payment" under the visit number | internal stage name printed as is | Print "Stage: at the cashier" style wording (item F) |
| "Diagnosis: Grossly carious 35" as free text | no tooth link, no code | Coded diagnosis per tooth (item A) |

## 4. What the system already has, mapped to their list

| They asked for | In 1.0.8 already | Missing |
|---|---|---|
| Complaint | Reason and complaints on the visit | History of the complaint in a dental shape |
| Dental history | Dental chart history per tooth | A "dental history" section (last visit, past treatment, habits) |
| Medical history | Allergies and chronic conditions on the patient, with alerts | Current medicines, pregnancy, bleeding disorders, structured |
| Surgical history | none | Past operations and complications, especially past extractions and bleeding |
| Financial history | Statement, balances, payments, cards, insurance | Shown together in the history; payment arrangement note |
| Hard tissues | Dental chart (FDI, surfaces, findings) | Shown inside the examination, not only on its own tab |
| Soft tissues | none | Structured intra-oral soft tissue findings |
| X-ray | Radiology orders and attached images | Linking an X-ray to teeth, and taking one in the chair without an order |
| Lab test | Lab orders | Shown inside the examination |
| Diagnosis | Coded diagnoses (ICD-10), free text | Dental codes per tooth (K02 caries, K04 pulp, K05 periodontal, K08) |
| Treatment done | Plan items marked done, billed automatically | Shown as "treatment done today" on the note and the report |
| Follow-up instructions | Recall date | Post-treatment instructions to the patient, review appointment |
| Import from Excel | Starter data importers for services and stock samples only | Patient import, price list import, stock import, opening balances |
| Limit access by role | Fixed roles; audit of every change; record of who opened each patient | Clinic-editable role settings; hiding clinical notes from non-clinical roles |

## 5. The plan

Ordered by what unblocks them first. Sizes are rough working days.

### Step 0. Deliver 1.0.8 and show them the chart (half a day, after the live server is reachable)

- Update their copy through **Back up and update**.
- A 20-minute session on WhatsApp or on site with the dentists, using **their two test patients**: chart a carious 35, plan an extraction, mark it done, see it billed, print the visit report.
- Give them the user manual chapter 10 (Dental) as a PDF.
- Ask them to repeat their test and tell us, per item, what is still wrong.

### Item A. The dental case sheet (5 to 7 days)

A **Dental consultation** panel on the visit, shown instead of the general clinical note when the practice is a dental clinic or the visit is in a dental department. The general note stays for everyone else. One screen, top to bottom, in the order a dentist works:

1. **History**
   - *Presenting complaint*: what and where, with quick picks (toothache, swelling, bleeding gums, broken tooth, loose tooth, sensitivity, check-up, cleaning, ortho review), plus how long and what makes it worse. Free text always allowed.
   - *Dental history*: last dental visit, previous treatment (fillings, extractions, root canals, braces, dentures), brushing and flossing, habits (tobacco, alcohol, sugary drinks, bruxism).
   - *Medical history*: belongs to the **patient**, not the visit. Shown filled from last time with a single **Still correct** confirmation, or edited. Covers allergies (existing), conditions (existing), current medicines, pregnancy or breastfeeding (only for women of the right age), and a short dental red-flag checklist: bleeding disorder or blood thinners, heart condition or valve, diabetes, hypertension, HIV or hepatitis, bisphosphonates, epilepsy. A ticked red flag shows on the patient's alert bar like allergies do today.
   - *Surgical history*: past operations and past extractions, with "any problem with bleeding or healing". Also patient-level, confirmed each visit.
   - *Financial history*: read-only summary from billing (owed before, last payments, how they usually pay, insurer or card, accepted quote for this course of treatment), plus one editable line, **Payment arrangement** (for example "Pays in three instalments, employer pays half").
2. **Examination**
   - *Extra-oral*: face symmetry, swelling, lymph nodes, TMJ, mouth opening. Normal by default with one tap; details when not normal.
   - *Intra-oral soft tissues*: gingiva, mucosa, tongue, floor of mouth, palate, oral hygiene (good, fair, poor), calculus and plaque, periodontal pockets if measured. Normal by default.
   - *Hard tissues*: the **dental chart itself**, embedded here, so charting is part of the examination, not a separate tab to remember.
   - *Investigations*: X-ray taken (periapical, bitewing, OPG) with the image attached **to the tooth** and a one-line finding, and lab tests ordered with their results when they arrive. An X-ray taken in the chair can be recorded without first raising a radiology order, and is billed the same way.
3. **Diagnosis**: per tooth or for the mouth, picked from a short dental list (caries by depth, reversible or irreversible pulpitis, periapical abscess, retained root, gingivitis, periodontitis, fractured tooth, impacted tooth, malocclusion), each mapped to its ICD-10 code so the Ministry forms count it (oral diseases line on HMIS 105). Choosing "Caries, tooth 35" also marks 35 on the chart.
4. **Treatment done today**: the plan items marked done in this visit, with tooth, surfaces, materials and anaesthetic used, and the price. They bill themselves as today.
5. **Plan and follow-up**: the treatment plan (existing), **instructions given to the patient** from templates (after extraction, after root canal, after scaling, new denture), a **review appointment** booked from here (suggested by the procedure: an extraction suggests 7 days), and the recall date (existing).

The **visit report** prints in the same headings, with the chart drawing and the X-ray findings, so the printed case sheet is the one a dentist would write by hand. The instructions can be printed for the patient or sent by text (with their consent), and with a patient link from 1.0.8.

Behind it: the history and examination are stored as structured, versioned sections on the visit (append-only, audited), with a template per practice type, so a maternity or general clinic can get its own case sheet later from the same machinery.

### Item B. Import from Excel (3 to 4 days)

**Settings, Import data**, for the hospital admin, in four kinds, patients first:

1. **Patients**: name, sex, date of birth or age, phone, address, next of kin, allergies, conditions, the old file number, and an opening balance owed.
2. **Price list** (services and procedures with prices).
3. **Stock** (items, units, quantity, cost, expiry).
4. **Appointments** already booked.

How it works, the same for each:

1. **Download the template** (an .xlsx with the columns, an example row and notes on each column) or upload their own sheet.
2. **Match the columns**: we guess from the headings ("Tel", "Phone No", "Contact" all mean phone) and they correct it.
3. **Check**: every row is validated before anything is saved. The page shows rows that will be added, rows that look like a patient already registered (same phone, or same name and date of birth) and what to do with each, and rows with problems, with the reason in plain words. Nothing is saved yet.
4. **Import**: in batches, so a 10,000-row sheet works on shared hosting. Old file numbers are kept on the patient and searchable, so "PT 1043 in our old book" still finds them.
5. **Undo** the whole batch within 24 hours if it went wrong, and a log of every import with who did it.

Accepts .xlsx, .xls and .csv. It also works in the online service, so a new clinic can bring its list on day one. We also offer to do the import for them from their file, as part of installation help.

### Item C. Access by role, set by the clinic (3 days)

1. **Tighten the defaults now**, for every clinic, in code:
   - Receptionist and cashier see that a visit happened and what it cost, but not the clinical note, the diagnosis or the chart. They keep the appointment reason.
   - A doctor sees money only for their own patients' bills.
   - The nurse records vitals and gives treatment but cannot change the diagnosis.
2. **Settings, Staff and access**: a table of the clinic's roles against plain-language abilities ("See clinical notes", "Take payments", "Give discounts", "Approve refunds", "Change prices", "See reports and money", "Manage staff"). The hospital admin ticks and unticks, within limits that keep the clinic safe: nobody can remove the last admin, and the admin role keeps everything. The settings are stored per hospital on top of the default matrix, so updates never undo a clinic's choices.
3. **Per person**: one extra ability for one person (for example the senior nurse who also takes payments) without creating a new role.
4. Each screen that a role cannot see shows "You do not have access to this. Ask your hospital admin", never an empty page.

Their existing protection stays: two-step sign-in for admins, the audit log of every change, and **who opened this record** on every patient, which can show them that the limits work.

### Item D. Follow-up that runs itself (2 days)

- Review appointments suggested by the procedure and booked from the case sheet.
- The day-before reminder (exists) and, for recalls, a text at six months (exists in 1.0.8 when SMS is set up).
- A **Recalls and reviews due** list for the receptionist: who is due this week, who missed a review, one tap to call or text.

### Item E. Dental extras worth offering next (after A to D, as they ask)

- **Consent for procedures**: an extraction or surgery consent form, signed on the tablet or printed, kept on the visit.
- **Lab work tracking**: crowns, bridges and dentures sent to an outside dental lab, with dates sent and due, the lab's cost, and a reminder when the work is late.
- **Materials used** per procedure, taken from stock (anaesthetic cartridges, filling material), so stock and cost per procedure are right.
- **Clinical photos** before and after, attached to the tooth.
- **Periodontal chart** (pocket depths, bleeding, mobility per tooth), already listed as "on the way" on the dental page.
- **Chair schedule**: appointments by dentist and chair, with procedure length.
- **Dentist's day report**: procedures done, by dentist and by type, with income, which also helps them pay dentists on commission.

### Item F. Small fixes from their samples (half a day)

- New visits take the clinic's only clinical department by default, so the report never says "No department recorded".
- The visit report prints a human stage ("At the cashier", "Seen", "Closed") instead of the internal word.
- The visit report shows the tooth chart and the coded diagnoses once item A exists.

## 6. Order of work

1. Step 0: deliver 1.0.8, walk them through the chart, get their second round of testing.
2. Item F and item C part 1 (tightened defaults): quick and visible.
3. Item A, the case sheet: the biggest gain for their dentists.
4. Item B, patient import, so they can bring their list in.
5. Item C parts 2 to 4, and item D.
6. Item E, one feature at a time, as they choose.

Each item ships in a release they install with **Back up and update**, with its user manual chapter updated and a short note in plain English of what changed.

## 7. Questions to ask them

1. In "financial history", do you mean who pays and what they owe, or something else (for example the patient's ability to pay)?
2. Which examination findings do you write on paper today? A photo of your current case sheet would let us match it exactly.
3. What is in your Excel files: patients only, or also prices, stock, debts and appointments? How many patients?
4. Which staff should **not** see what? (For example: should the receptionist see the diagnosis?)
5. Which X-rays do you take (periapical, OPG), and do you want the image itself in the system or only the finding?
6. Do the two dentists need separate schedules and separate income reports?

## 8. Reply to send them (short)

> Thank you for the careful review. Two of the problems you found, the tooth chart and the treatment plan, are not in the version you tested; they are ready in the next update, which we will install with you and show your dentists. We are now building a dental case sheet with exactly the headings you listed (history, oral examination, X-ray, lab, diagnosis, treatment done), importing your patients from Excel, and letting you choose what each staff role can see. We have a few short questions for you, attached, so we build it the way you work.
