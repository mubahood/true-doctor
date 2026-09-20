# Inpatient / IPD (Phase 3, Step 15)

Wards, beds, admissions with a state machine, bed transfers, an occupancy board and daily
bed-charge billing. Tenant-scoped. This is **Step 15a** (admissions core); nursing rounds/MAR
+ discharge summary consolidation land in **15b** (the discharge summary PDF is already here).

## Files (15a)

| Concern | File |
|---|---|
| Tables | `2026_07_19_230000_create_wards_table.php`, `…_230010_create_beds_table.php`, `…_230020_create_admissions_table.php`, `…_230030_create_bed_transfers_table.php` |
| Models | `app/Models/{Ward,Bed,Admission,BedTransfer}.php` |
| Enums | `app/Enums/BedStatus.php`, `app/Enums/AdmissionStatus.php` |
| Service | `app/Services/AdmissionService.php` |
| Exception | `app/Exceptions/BedUnavailableException.php` |
| Requests | `app/Http/Requests/{Ward,Bed,Admission,BedTransfer,Discharge}Request.php` |
| Policies | `app/Policies/{Ward,Bed,Admission}Policy.php` |
| Controllers | `app/Http/Controllers/Admin/{Ward,Bed,Admission}Controller.php` |
| Views / PDF | `resources/views/admin/{wards,beds,admissions}/…`, `resources/views/pdf/discharge-summary.blade.php` |

## Beds never double-occupy

`AdmissionService::admit/transfer/discharge` each run in a transaction with a `lockForUpdate`
read on the bed(s) — a bed can never be assigned twice under concurrency (constraint F). A
transfer locks both beds in **id order** (deadlock-safe), frees the old, occupies the new, and
appends an append-only `bed_transfers` row. The catalogue bed form can't flip an occupied bed's
status or delete it — only the service moves bed state.

## Admission lifecycle & bed charges

`AdmissionStatus`: `admitted → discharged / deceased / absconded` (a bed transfer is an event,
not a status change). On **discharge**, the service frees the bed, computes the charge as
**nights × the bed's nightly rate** in bcmath (minimum one night), stores it on the admission,
and — if the admission links a visit — **bills it** via `BillingService::addCustomLine`,
so IPD charges consolidate onto the same invoice. Double-discharge is rejected.

## Occupancy board

`/admin/occupancy` (`AdmissionController@board`) renders each active ward's beds with their
status and current patient — the live bed map.

## Discharge summary PDF

`pdf.discharge-summary` — patient, stay length, bed charge and notes, in the hospital's currency.

## RBAC

`ipd.view` (read board/admissions) · `ipd.manage` (admit/transfer/discharge — doctor, nurse) ·
`wards.manage` (ward/bed setup). Hospital admin holds all.

## Tests (15a)

`AdmissionServiceTest` (admit occupies, can't double-occupy, transfer frees+occupies, discharge
frees+bills nights, can't discharge twice, min-one-night); `AdmissionHttpTest` (nurse
admit→transfer→discharge, occupied-bed rejected, receptionist can't admit, summary PDF, tenant
isolation).

---

## Step 15b — nursing rounds (notes, vitals rounds, MAR)

Append-only nursing logs on an active admission, entered by a nurse (`ipd.manage`) and rendered
on the admission timeline.

| Concern | File |
|---|---|
| Tables | `2026_07_19_240000_create_nursing_notes_table.php`, `…_240010_create_vital_rounds_table.php`, `…_240020_create_medication_administrations_table.php` |
| Models | `app/Models/{NursingNote,VitalRound,MedicationAdministration}.php` (all `UPDATED_AT = null` — append-only) |
| Enum | `app/Enums/MedicationAdminStatus.php` (given / withheld / refused) |
| Requests | `app/Http/Requests/{NursingNote,VitalRound,MedicationAdministration}Request.php` |
| Controller | `app/Http/Controllers/Admin/NursingController.php` |

- **Nursing notes** — free-text observations.
- **Vitals rounds** — temp/BP/pulse/resp/SpO₂ taken during a round (append-only, distinct from
  the one-off triage vitals on a visit).
- **MAR** — each medication given/withheld/refused with dose + route.

Only an **active** admission accepts new entries (a closed one returns 422). All three are
tenant-scoped and role-gated (`ipd.manage`).

Tests (`NursingRoundTest`): nurse records all three, closed-admission rejects entries,
receptionist can't record, tenant isolation.
