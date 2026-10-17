# Maternity and child health

Roadmap 04 §4: the pregnancy record, the immunisation register and the growth chart. Three tabs on the patient record, shown only where they apply, and one list page. The API is in `docs/api/mother-child.md`.

## Where it lives

| Piece | File |
|---|---|
| Rules | `app/Services/PregnancyService.php`, `ImmunisationService.php`, `GrowthService.php` |
| Country lists (ANC contacts and checklist, risk factors, UNEPI schedule, MUAC cut-offs) | `config/mother_child.php`, each with its source and the date checked |
| WHO growth standards (LMS, 0 to 60 months) and z-scores | `app/Support/MotherChild/WhoGrowth.php` |
| Tabs | `app/Livewire/Patients/Tabs/{Pregnancy,Immunisation,Growth}.php` |
| Due list | `app/Livewire/Immunisations/Index.php` (Patient care → Immunisations due) |
| API | `app/Http/Controllers/Api/V1/MotherChildController.php` |
| Demo | `database/seeders/DemoScenarios/MotherAndChild.php` |

Tables: `pregnancies` (editable, edits logged), `anc_contacts`, `immunisations` and `growth_measurements` (append-only; a wrong dose is voided with a reason, never edited).

## Who sees what

Reading needs `visits.view` (the clinical history); writing needs `visits.vitals` or `visits.diagnose`, so the nurse or midwife at triage records, the desk only reads.

- **Pregnancy** tab: a woman aged 12 to 55 (date of birth known) in a practice with the maternity pack, or anyone who already has a pregnancy on record.
- **Immunisation** tab: a child under 15 (the HPV dose is at ten) in a practice with the paediatrics or maternity pack, or anyone with a dose on record.
- **Growth** tab: a child under five, same packs, or anyone with a measurement on record.
- **Immunisations due** in the menu: a practice with either pack.

A dental clinic sees none of it.

## Pregnancy

- The EDD is the LMP plus 280 days unless the clinician gives another (a scan); gestation on any day is read from the EDD.
- Gravida counts this pregnancy; para (births before it) must be below gravida. One open pregnancy at a time. Closing (delivered or ended) is final.
- The eight-contact schedule (12, 20, 26, 30, 34, 36, 38, 40 weeks) comes from config. A contact counts against the first scheduled contact at or after its gestation week, so a woman booking at 18 weeks shows contact 1 as missed.
- The checklist: each check is due from the latest contact that asks for it until it is done (a booking test done late still counts). A check with an `until` contact (the dating scan, deworming) is missed once that has passed.
- Flags: the recorded risks, plus under 18 or over 35, five or more births, a BP of 140/90 or more at the last contact, past 41 weeks.

## Immunisation

- The UNEPI schedule from config: vaccine, dose, age, the series and the minimum interval. A dose falls due at its age, or later when the previous dose of its series is too recent.
- Due for 14 days from that date, then overdue; past the dose's last age (OPV 0 at two weeks, rotavirus at two years, the rest at five) it is missed and no longer chased.
- Recording refuses a dose not on this child's schedule, already given, in the future or before birth, given more than four days too young, or too soon after the previous dose. "Given elsewhere" copies the child health card.
- The malaria vaccine is given only in some districts: a hospital there adds it to the schedule in config.

## Growth

- Points are the clinic's measurements and the weights and heights taken as vitals on visits, one per day (the clinic's wins).
- Weight-for-age and length/height-for-age z-scores from the WHO standards (interpolated between months; the WHO's restricted method beyond ±3 for weight). Bands: below −3 severe, below −2 underweight or stunted; MUAC read from 6 to 59 months (below 11.5 cm severe, below 12.5 moderate). Oedema is shown as severe acute malnutrition.
- Faltering: no weight gained since a weighing at least 28 days before the latest, or a fall of 0.67 z or more since the previous weighing.

## Not done here

Delivery and postnatal records, the baby's own record linked to the mother, the partograph, caregiver reminders and the monthly register export are the next steps of §4.
