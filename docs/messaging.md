# Messages to patients

Text messages from a clinic to its patients (roadmap 05). The code is in `app/Services/Messaging/`.

## What goes out

| Event | When | Wording (`lang/en/patient_messages.php`) |
|---|---|---|
| `appointment_booked` | An appointment is booked for a time still ahead | ":clinic: your appointment with :doctor is on :date at :time. To change it, call :clinic_phone." |
| `appointment_day_before` | The day before, from 07:00 (`appointments:remind`, hourly) | ":clinic: a reminder of your visit tomorrow at :time. To change it, call :clinic_phone." |
| `receipt` | A bill is paid in full | ":clinic: thank you. We received :amount for invoice :invoice. Your receipt: :link" (the 7-day share link) |
| `dental_recall` | The patient's next check-up date, in a dental practice (`messages:due`, hourly; up to a week late if the server was down) | ":clinic: it is six months since your dental check-up. Call :clinic_phone to book your next visit." |

Each can be switched off in **Settings → Messages to patients**, which also sets the quiet hours (21:00 to 07:00 by default) and shows every message with its status.

## The rules for every message (`PatientMessenger`)

1. The hospital has messages on, and this event switched on. Otherwise nothing is written at all.
2. Written down once per event: `dedupe_key` is unique per hospital (`appointment:<uuid>:booked`, `invoice:<uuid>:paid`, `dental_recall:<patient>:<date>`). The same event never messages twice.
3. The patient said yes to messages about their care by SMS (`patient_consents`, latest answer per channel and purpose). Marketing needs its own yes.
4. A number to send to: the patient's, in E.164 for the hospital's country (`PhoneNumber`), or for a child under 16 with none, the guardian's.
5. Held until the quiet hours end, then queued (`SendPatientMessage`).
6. The job claims the message (queued → sending), takes the credit (online service only), sends, and records the gateway's id and cost. A gateway or network failure is retried three times with backoff; a final failure gives the credit back.

A message that cannot go is still written down with the reason (`no_consent`, `no_phone`, `no_credit`, `event_off`), so "why did my patient not get a reminder?" has an answer.

## Consent

- Recorded at the desk (patient form, walk-in registration), by the app (`messaging` on `POST/PUT /api/v1/patients` and on an offline `patients` create), or by a reply.
- Append-only. A new patient's three answers are written when the desk saves them; editing a patient who was never asked records nothing unless a box is ticked.
- Nothing was inferred for patients registered before this existed: `consent_given` was consent to keep their records.
- **STOP** (or UNSUBSCRIBE, CANCEL, END, QUIT, STOPALL, OPTOUT) from a number stops texts to every patient on that number, in every clinic.

## Credit (online service)

`message_credits` is a ledger: a top-up adds, a send takes its segments (160 characters a part, 153 when longer; 70/67 once a non-GSM character is in it), a final failure refunds. It can never go below zero. Until bundles are sold through the subscription checkout, the super admin adds credit (**SaaS central → Message credit**) once a clinic has paid, with the payment reference. A hospital's own server pays its gateway directly and has no ledger.

## Going live

1. In Africa's Talking: an API key, and a sender ID once approved.
2. `.env`: `SMS_DRIVER=africastalking`, `AT_USERNAME`, `AT_API_KEY`, `AT_SENDER_ID`, `SMS_WEBHOOK_TOKEN` (a long random string).
3. Callbacks: delivery reports to `https://<host>/gateway/sms/delivery/<SMS_WEBHOOK_TOKEN>`; for a two-way number, incoming messages to `https://<host>/gateway/sms/inbound/<SMS_WEBHOOK_TOKEN>` and `SMS_REPLY_STOP=true`.
4. A queue worker must be running (`php artisan queue:work`): messages are sent from the queue.

A production server left on `log` never lets a clinic switch messages on, so no clinic believes reminders are going out when they are not.
