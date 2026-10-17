# 09. Intelligence: rules first, AI as a careful helper

**Goal:** True-Doctor notices what a busy clinic misses: money not billed, results not seen, patients not coming back, stock running out. It says so in plain words, with the next action attached. AI helps with writing and coding, but never decides about care, and never sends patient data anywhere without the hospital's say.

**What exists and stays:**

- 22 Insight rules (`app/Support/Insights/Rules/*`) behind `InsightEngine` (cached 60 seconds), at `GET /api/v1/insights`;
- `NextActionsService` and `AgendaService`;
- `PatientSummaryService`;
- the role dashboards.

---

## 1. More rules where the money and the patients are (P1)

Each new rule is one class in `app/Support/Insights/Rules/`, with a test, a severity, a plain message and a link to the screen that fixes it.

| Area | Rule |
|---|---|
| Money leaks | Orders done but not billed; dispensed but not billed; bed nights not charged (after the scheduler fix, 07 §1); discounts above the practice's limit; payments voided today; cash counted different from expected at day close |
| Debt | Patients owing more than a set amount for more than 30 days; insurer claims older than 45 days without a decision |
| Clinical follow-up | Abnormal results not opened by the ordering clinician within 24 hours; vaccines overdue (04 §4); ANC contacts missed; chronic patients past their review date; dental recalls due (04 §3) |
| Operations | Average wait per stage today against the last 4 weeks; a doctor with patients waiting and no visit opened for 30 minutes; the no-show rate rising |
| Stock | Days until stock-out from the last 30 days of use. Borrow the stock-out forecast from Budget Pro (its `forecasting` plan feature) as the pattern. Also expiring batches with no use in the last 30 days |
| Notifiable disease | A coded notifiable diagnosis today (04 §2) |
| Messages | The message balance will run out in under 7 days (05 §1) |

## 2. The owner's day on WhatsApp (P1)

- **A daily summary at the owner's chosen time** (default 20:00). It is the pattern Budget Pro sells as "Daily summary and stock alerts on WhatsApp". It covers:
  - patients seen;
  - money in by method;
  - new debt and recovered debt;
  - the top three alerts;
  - tomorrow's appointments.
- **Sent through the channel seam** (05 §1), with the owner's consent, as a short message with a link to the dashboard. It counts against the message balance or is included in higher plans.

## 3. Simple forecasting (P2)

- **No-show chance per appointment,** from the patient's own history and the clinic's (day, time, lead time, past no-shows). Shown as "likely to miss" with a suggestion to call. A simple model, retrained monthly per hospital, with no data shared between hospitals.
- **Revenue and patient trends:** this month against the same days last month and last year, with the main reasons (services up or down).
- **Bed occupancy** for the next 7 days from admissions and the usual length of stay.

## 4. AI helpers (P2, behind a switch per hospital)

**Rules for every AI feature, with no exceptions:**

- **Off by default.** The owner switches it on in Settings → Assistant, after reading what is sent where. It is off on standalone copies unless the owner switches it on there too.
- **The clinician always confirms.** AI drafts and suggests. It never saves a diagnosis, a prescription, a result or a message by itself.
- **Data minimisation:**
  - names, phone numbers, IDs and addresses are removed before anything leaves the server;
  - only the text needed for the task is sent;
  - the provider must have a data processing agreement and must not train on the data.
- **Show where an answer came from** (which visits, which results). Say "not sure" rather than guess.
- **Log every use** (who, what feature, when, which record), without storing the sent text outside the hospital's own database.
- **Cost control:** AI use draws on a credit balance like messages, so a hospital is never surprised by a bill.
- **Never for patients directly.** No chatbot gives medical advice to patients.

**The helpers, in order of value:**

1. **"Before you see this patient":** a short summary of the last visits, allergies, chronic conditions, pending results and current medicines. Start it **rule-based from `PatientSummaryService`** (no AI needed). Add AI wording only if it helps.
2. **Code suggestions:** from the free-text diagnosis, suggest the three most likely ICD-10 codes (04 §1). The clinician taps one. The local synonym list does most of the work; AI covers the rest.
3. **Drafts:** the discharge summary, the referral letter and the sick note, drafted from the visit and edited by the clinician.
4. **Notes from short text or voice:** turn "fever 3d, cough, chest clear, malaria RDT pos" into a structured note (complaint, findings, tests, plan) for the clinician to check.
5. **Translation of patient messages** into Luganda and Swahili, reviewed by a person once per template (05 §3).
6. **Questions about the numbers for the owner,** such as "How much did dental bring in last month?". It answers from report aggregates only, never from raw records, and shows the report it used.

## 5. Benchmarks between clinics (P2)

Only with each hospital's consent, and only aggregated and anonymised (at least 10 hospitals per group). For example: "Your average wait is 42 minutes; similar clinics average 25". This gives owners a reason to stay and a reason to improve.

## 6. Tests

- Every rule has a test with a hospital that triggers it, one that does not, and an isolation test (no counting across hospitals).
- AI features: the switch off means no outbound call; the redaction removes names, phones and IDs (tested against examples); nothing is saved without a confirming request; every use is logged.
