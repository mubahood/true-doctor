# Data protection impact assessment

An assessment of the risks to patients' personal data from {clinic}'s use of the {product} hospital management system, and how each is controlled. Prepared {date}. Review it once a year and whenever a new part of the system that handles patient data is switched on.

## 1. What the processing is

The clinic records its patients' identity, contacts and health (visits, vitals, diagnoses, prescriptions, laboratory and radiology results, admissions, nursing records, dental charts), and their bills, payments and insurance. Staff sign in with their own accounts. Patients may agree to receive text messages. The data is special personal data under the Data Protection and Privacy Act 2019.

## 2. Why it is needed and proportionate

The record is needed to treat patients safely and continuously, to bill for care and to make the reports the Ministry of Health requires. Only what care, billing and reporting need is collected. Messages go only to patients who agreed. Reports to the Ministry are counts, not names.

## 3. Risks and controls

| Risk | Likelihood before controls | Controls in the system | What the clinic does |
|---|---|---|---|
| Another clinic sees our patients | Low | Every record carries its hospital and is filtered at the database layer; tested on every change | None needed |
| A member of staff sees more than their job needs | Medium | Roles: each sees only its own modules; changes to patient and visit records are logged with who and when | Give each person their own account and the right role; remove leavers the day they go |
| A password is guessed or shared | Medium | Strong password rule; limited sign-in attempts; two-step sign-in for administrators; sessions can be ended everywhere | Never share accounts; switch on two-step sign-in |
| A device with an offline copy is lost | Medium | Offline copies are tied to a device and can be revoked from the server | Revoke a lost device at once (Offline devices) |
| Data is lost (fault, deletion, ransomware) | Low | Nightly encrypted backups off the server, restore tested monthly (online service); a nightly backup on a standalone server | On a standalone server, copy the backup off the server regularly |
| Data is sent to the wrong person | Medium | Messages go to the number on the record, only with consent; shared invoice links expire | Check the number and the patient before sending anything |
| A printout or screen is seen by others | Medium | Screens show only what the role needs | Keep printouts out of public areas; lock screens |
| Data kept longer than needed | Medium | Retention schedule; export files deleted after {export_hours} hours | Review old records yearly against the schedule |
| A patient cannot exercise their rights | Low | Access export, correction with history, consent switches, a request log with due dates | Log every request the day it arrives |
| A supplier misuses data | Low | Suppliers receive only what they need; a processing agreement covers the online service | Accept the processing agreement |

## 4. Result

With these controls in place, the remaining risk is low to medium, and acceptable for the purpose of providing care. The highest remaining risks depend on how staff use the system: shared accounts, lost devices and misdirected messages. Training on this procedure and the breach procedure is the main way to lower them.

## 5. Sign-off

Assessed by: ______________________ Date: ____________

Approved by (person in charge of the clinic): ______________________ Date: ____________
