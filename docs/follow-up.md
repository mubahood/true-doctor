# Follow-up: reviews and recalls

Built 2026-10-17 for a dental clinic's review (item D). Service: `App\Services\FollowUpService`.

## A review booked from the visit

A review is an ordinary appointment that remembers the visit it was arranged in
(`appointments.origin_visit_id`), with the reason **Review** and 15 minutes. It is booked
through `AppointmentService::book`, so the doctor's availability, clashes, the booking text
and the day-before reminder all apply.

The visit page's **Book a review** panel (`Visits\Panels\Review`, needs `appointments.manage`)
suggests the day from the dental procedures done on the visit (`FollowUpService::RULES`):
extraction or surgery 7 days, root canal 7, denture 7, crown or bridge 14, braces 28,
otherwise 14. Only the visit doctor's free times are offered (`DoctorSlots::open`).

## Recalls and reviews due

**Scheduling → Recalls and reviews due** (`Appointments\Recalls`, `appointments.view`):

- **Reviews this week**: reviews booked from today to seven days ahead, not yet attended.
- **Missed reviews**: reviews in the last 60 days marked no-show or left booked with the day
  gone, with no visit since and nothing booked ahead.
- **Dental check-ups due**: `patients.next_recall_on` in the next seven days or up to 180 days
  overdue, with no visit since and nothing booked.

The search box finds a patient by name, number or phone, every word somewhere (the patient
search used everywhere else). The flag beside a row (so many days overdue, did not come, not
attended) is worded once in `FollowUpService::flag`, for the page and the app.

Each row has **Call** (a `tel:` link) and **Text** (`appointments.manage`). The text is the
message event `follow_up_due`, sent through `PatientMessenger`, so consent, the number, credit
and quiet hours all apply; its dedupe key is the thing due, so a second press reports the
first text rather than sending another.

The app's Recalls screen reads the same rows (`GET follow-ups`), with each patient's answer about
texts and their last visit, offers **Text** only to a patient who agreed, and books from a missed
review or a check-up due with the diary's own booking form.

API: [docs/api/follow-up.md](api/follow-up.md).
