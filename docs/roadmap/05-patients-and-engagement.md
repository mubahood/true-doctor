# 05. Patients and engagement

**Goal:** a patient feels the clinic's system without installing anything. In Uganda the patient's app is WhatsApp, with SMS as the safety net. They get:

- the booking confirmed;
- a reminder the day before;
- the receipt on their phone;
- the result when it is ready;
- a nudge when a review, recall or vaccine is due.

Every message is the clinic's (its name and number), with consent, and costs the clinic a known amount.

**What exists and stays:**

- `app/Services/Channels/*`: the channel seam, with `LogChannel` and an unwired `AfricasTalkingChannel`.
- `SendAppointmentReminders`: hourly, quiet hours, idempotent stamps.
- `InvoiceShareService` and `SharedDocumentController`: signed, expiring, revocable links for invoices and receipts.
- `DoctorSlots` and `GET /api/v1/booking/availability`.
- `AppointmentSource::online`.
- Flutterwave patient payments.
- `NotificationPreference` and quiet hours for staff.

**Not building:** a patient mobile app or patient accounts with passwords. Links and WhatsApp reach more people for less.

---

## 1. The channels (P0 for SMS, P1 for WhatsApp)

- **SMS, live (P0):**
  - wire the Africa's Talking driver through config and env (`SMS_DRIVER=africastalking`, `AT_USERNAME`, `AT_API_KEY`, `AT_SENDER_ID`);
  - keep `log` as the default in local and testing;
  - take delivery reports from a signed webhook.
  - Turning live SMS on becomes a setting, not a code change.
- **WhatsApp Business (P1):** one driver for the WhatsApp Cloud API, or a provider in front of it. It sends approved templates (§3) for:
  - the clinic's own registered number (premium); or
  - True-Doctor's shared number with the clinic's name in the message (standard).
- **Fallback:** WhatsApp first; SMS when the number is not on WhatsApp or the template fails within 10 minutes. The clinic sees which channel delivered.
- **Rules for every send:**
  - queued;
  - idempotent (the same event never messages twice; stamp per event and channel);
  - quiet hours respected;
  - retried with backoff;
  - logged with status (queued, sent, delivered, failed, read when the channel reports it).
- **Credits:**
  - each hospital has a message balance (a ledger, bcmath, never edited, only posted);
  - bundles are bought with the subscription or alone through the existing Pesapal checkout;
  - a low-balance warning goes to the owner (an Insight rule).
  - This is also a revenue line (02 §5).

## 2. Consent, per channel and per purpose (P0 with the first live message)

- **Replace the single `consent_given` with a consent record:**
  - patient, channel (SMS, WhatsApp, email), purpose (care, billing, reminders, marketing), yes or no;
  - when, who recorded it, and how (desk, form, reply).
  - Care messages (results, appointments) need the care consent; marketing needs its own yes.
- **At registration:** one plain sentence the receptionist reads, with three switches, all on by default for care and off for marketing.
- **Opt-out:** "Reply STOP" on SMS and the WhatsApp opt-out. Both are written straight to the consent record and honoured before every send.
- **For children:** the consent and the messages go to the guardian's number.

This is part of the Data Protection and Privacy Act work (07).

## 3. The message catalogue (P1)

Each template:

- is the clinic's (`:clinic`, `:clinic_phone`);
- is short, in English, Luganda and Swahili (`lang/*/patient_messages.php`);
- has its WhatsApp twin approved;
- can be switched on, off and timed per hospital in Settings → Messages, with a preview and a test send to the owner.

| Event | Message (English) | Default |
|---|---|---|
| Appointment booked | :clinic: your appointment with :doctor is on :date at :time. Reply 1 to confirm, 2 to change. | on |
| Day before (exists, goes live) | :clinic: a reminder of your visit tomorrow at :time. Call :clinic_phone to change. | on |
| Visit paid | :clinic: thank you. Your receipt: :link | on |
| Balance due | :clinic: you have a balance of :amount. Pay here: :pay_link or at the counter. | off (weekly when on) |
| Result ready | :clinic: your :test result is ready. View it here: :link (code: :code) | on, after release by the lab |
| Review due | :clinic: it is time for your review with :doctor. Book: :book_link | on |
| Dental recall | :clinic: it has been 6 months since your check-up. Book your next visit: :book_link | on for dental |
| ANC next contact | :clinic: your next antenatal visit is on :date. | on for maternity |
| Vaccine due | :clinic: :child is due for :vaccine on :date. | on for children's practices |
| Refill due (chronic) | :clinic: your :medicine refill is due on :date. | on for chronic care |
| Discharge | :clinic: your discharge summary: :link | on |
| After visit (one question) | :clinic: how was your visit today? Reply 1 to 5. | off |
| Birthday | :clinic wishes you a happy birthday. | off (marketing consent) |

## 4. Patient links: a portal without passwords (P1)

**Extend the existing share links** (`SharedDocumentController`, nonce revocation) from invoices and receipts to:

- lab and radiology results;
- the discharge summary;
- the prescription;
- the next appointment;
- the dental treatment plan or quote.

**Opening a link:**

- a short code sent separately, or the patient's date of birth, unlocks it;
- 5 wrong tries lock it;
- every open is logged (who opened, from where), visible to the clinic.
- **Expiry:** 7 days by default and configurable. The clinic can revoke a link at any time, and a revoked link shows the clinic's number.

**Verification QR on every document** (P1):

- each PDF carries a QR and a short code that open `/v/{code}`;
- that page confirms "This is a genuine :document from :clinic, issued on :date for a patient with initials :initials". It never shows the contents.
- This stops fake receipts and fake results, and builds trust with employers, schools and insurers.
- The sister product in `htdocs/true-doctor-verify` ("Scan. Verify. Trust.") shows the idea; reuse its approach, not its code.

## 5. Online booking and the clinic's public page (P2, high reach)

- **`/h/{slug}` for every hospital that opts in:**
  - name, logo and brand colour (from `HospitalBrand`);
  - services with "from" prices (optional);
  - hours, location map link;
  - the clinic's phone and WhatsApp;
  - doctors (each one opts in).
- **Book an appointment:**
  - free slots from `DoctorSlots`;
  - the patient's phone confirmed by a one-time code (reuse `HumanCheck` and the verification code service);
  - creates an appointment with source `online` and sends the "booked" message.
- **SEO:**
  - `MedicalClinic` or `Dentist` schema, address, opening hours and `sameAs`;
  - listed in `sitemap-clinics.xml`;
  - a small "Powered by True-Doctor" link (02 §9).
  - Each clinic page is a new door into True-Doctor.
- **Embeddable booking button** for the clinic's own website or Facebook page (an iframe or a plain link) (P2).

## 6. In the waiting room (P2)

- A TV queue screen: a page with a hospital token showing the numbers being called. It is driven by `CheckInQueue` and works on any smart TV browser.
- **"You are next" SMS** when two patients are ahead (optional per clinic).

## 7. Payments from patients (P1)

- Every balance message carries a pay link (Flutterwave hosted checkout exists; mobile money inside it).
- **Direct mobile money prompts** (MTN MoMo, Airtel Money) through a gateway that supports push-to-phone, behind the existing `PaymentGateway` interface (P2).
- The receipt goes out automatically the moment a gateway payment is confirmed.

## 8. Measures shown to the owner (P1)

On the dashboard and in reports:

- messages sent, delivered and failed;
- opt-out rate;
- no-show rate before and after reminders;
- debt recovered after balance reminders;
- results opened;
- online bookings;
- the after-visit score.

## 9. Tests

- **Channels:** driver contract tests; delivery report webhook signature; fallback from WhatsApp to SMS; idempotent stamps (the same event never sends twice); quiet hours.
- **Consent:** every send path refuses without the right consent; STOP is honoured immediately.
- **Links:** expiry, revocation, the unlock code, the lock after 5 tries, and the open log; the verification page never leaks contents.
- **Booking:** the slot lock is respected; the one-time code is required; isolation between hospitals on public pages.
- **Credits:** ledger postings are bcmath and append-only, and a send with no balance is refused with a clear reason.
