# The ten daily journeys, measured

Roadmap 06 §1. Scripted on the web against the demo hospital by `scripts/journeys.mjs` (real clicks and typing; see the script header for what each column counts). Seconds are machine time, a floor, not what a person takes. Run: 17 October 2026.

| # | Journey | Role | Clicks | Fields | Screens | Dialogs | Requests | Seconds |
|---|---|---|---|---|---|---|---|---|
| 1a | Register a new patient, then open a visit (Register patient page) | reception | 5 | 5 | 4 | 1 | 12 | 3.2 |
| 1b | Register a new patient and open a visit in one dialog (Visits, New visit, New patient) | reception | 5 | 5 | 2 | 1 | 10 | 2.9 |
| 2 | Book an appointment | reception | 8 | 4 | 2 | 1 | 22 | 4.8 |
| 3a | Consult, part 1: vitals (nurse) | nurse | 3 | 5 | 2 | 1 | 16 | 1.8 |
| 3b | Consult, part 2: complaint, diagnosis, a lab test, a prescription (doctor) | doctor | 12 | 7 | 3 | 4 | 42 | 7.3 |
| 4 | Enter a lab result and release it | lab | 13 | 2 | 3 | 2 | 17 | 6.3 |
| 5 | Dispense a prescription | pharmacy | 6 | 3 | 3 | 2 | 28 | 5.3 |
| 6a | Take a payment in cash (raise the invoice, then pay in full) | reception | 14 | 2 | 2 | 5 | 42 | 9.8 |
| 6b | Take a payment by mobile money on an outstanding invoice | accounts | 6 | 2 | 3 | 2 | 12 | 2.5 |
| 7a | Admit a patient (occupancy board) | doctor | 6 | 1 | 2 | 2 | 14 | 2.8 |
| 7b | Record a ward round (vitals round on the admission) | nurse | 4 | 5 | 3 | 1 | 14 | 2 |
| 7c | Discharge (occupancy board) | doctor | 5 | 1 | 2 | 2 | 8 | 2 |
| 8 | Receive stock (a delivery of one item) | pharmacy | 3 | 4 | 2 | 1 | 12 | 3.7 |
| 9 | Close the cashier's day (see the day, print it) | accounts | 3 | 0 | 3 | 0 | 4 | 1.7 |
| 10a | Find a patient from a name | reception | 2 | 1 | 2 | 1 | 6 | 2.1 |
| 10b | Find a patient from a phone number (as registered, 07...) | reception | 2 | 1 | 2 | 1 | 6 | 2 |
| 10c | Find a patient from a patient number | reception | 2 | 1 | 2 | 1 | 6 | 2.1 |
| 10d | Find a patient from a phone number in the international form (+256...) | reception | 1 | 1 | 2 | 0 | 4 | 1.8 |

## What the run found, and what was done

- **10d: a phone number typed as +256… found nothing** when the patient was registered as 07…. Fixed: patient search matches any way of writing the same number (the last nine digits).
- **4: "Save and report back" refused an order still marked Ordered.** Fixed: entering the results completes the order, stepping it through Collected and Processing.
- **4: after "Move to" the row's menu would not open again** until a reload (Livewire put `x-cloak` back on the popup). Fixed in the menu component.
- **6a: taking cash is the longest journey (14 clicks, 5 dialogs).** The visit has to be marked ready for billing, and two orders from earlier journeys were still open. Billing waits for open orders on purpose (nothing is invoiced half done), so this stays; the target for Phase 3 is one "Bill and take payment" action once orders are closed.
