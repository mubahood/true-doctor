# Visits / visits (Phase 1, Step 10)

The OPD visit — rebuilt from the legacy's most bug-ridden flow (a 792-line fat
`Visit` model with cross-entity save hooks) as a thin model + a service and one
**enforced state machine** (HMS_PLAN.md §2.3, A5). Tenant-scoped via `BelongsToHospital`.

This is **Step 10a** (visit core). Prescriptions + `DosageScheduleGenerator`, medical
service orders, and inline new-patient intake ship in **10b**. Billing rollups
(subtotal/total_due/…) are added by **Step 11** as an additive migration — this table is
clinical-only.

## Files (10a)

| Concern | File |
|---|---|
| Tables | `2026_07_19_150000_create_visits_table.php`, `…_150010_create_visit_status_histories_table.php` |
| Models | `app/Models/Visit.php`, `app/Models/VisitStatusHistory.php` |
| Enum / machine | `app/Enums/VisitStatus.php` |
| Service | `app/Services/VisitService.php` |
| Exception | `app/Exceptions/InvalidVisitTransitionException.php` |
| Requests | `app/Http/Requests/Visit{,Vitals,Clinical,Transition}Request.php` |
| Policy | `app/Policies/VisitPolicy.php` |
| Controller | `app/Http/Controllers/Admin/VisitController.php` |
| Views | `resources/views/admin/visits/{index,create,show}.blade.php` |

## Visit number

`C-<Ymd>-<seq3>` (e.g. `C-20260718-003`), sequence per hospital per day. Generated in
`VisitService` inside a transaction with a **locking read** on the day's rows (same
pattern as patient numbers), retried on the composite-unique violation; `(hospital_id,
visit_no)` is the final guard.

## State machine (the pipeline)

`VisitStatus` owns the legal moves — the role screens are *filtered views* of this
one machine, never parallel workflows:

```
registration → triage → visit → orders → billing → payment → completed
(any non-terminal stage → cancelled)
visit → billing        (skip orders when nothing was ordered)
completed / cancelled          (terminal)
```

Only `VisitService::transition()` moves a visit: it locks the row, checks the
machine, stamps `completed_at` on completion, and appends an **append-only**
`visit_status_histories` row (opening recorded as `null → registration`, atomically
with the create). Illegal jumps throw `InvalidVisitTransitionException`; the UI
derives its buttons from `transitionsTo()`.

## Vitals & BMI

`recordVitals()` writes temperature/BP/weight/height/pulse/SpO₂/resp-rate and computes
**BMI = kg / m²** once, in the service (never a model save-hook — §2.3). BMI is never
accepted from the client. The value is null unless both weight and a positive height are
given.

## RBAC

`visits.view` · `visits.create` (open — receptionist) · `visits.vitals`
(triage — nurse) · `visits.diagnose` (clinical narrative — doctor) ·
`visits.manage` (advance/cancel). Enforced ability-by-ability in `VisitPolicy`;
tenancy by the global scope.

## Tests

`VisitServiceTest` (numbering + per-day sequence, BMI incl. null cases, full pipeline
+ history, skip-ahead & terminal rejection); `VisitHttpTest` (open, nurse vitals→BMI,
nurse-can't-diagnose, doctor-diagnoses, illegal-transition flash); `VisitTenancyIsolationTest`
(§2.1 + cross-hospital patient rejected).

---

## Step 10b — prescriptions, dosing schedule, inline intake

### Files (10b)

| Concern | File |
|---|---|
| Tables | `2026_07_19_160000_create_prescriptions_table.php`, `…_160010_create_dose_items_table.php`, `…_160020_create_dose_item_records_table.php` |
| Models | `app/Models/{Prescription,DoseItem,DoseItemRecord}.php` |
| Enums | `app/Enums/DoseSlot.php`, `app/Enums/DoseRecordStatus.php` |
| Services | `app/Services/DosageScheduleGenerator.php`, `app/Services/PrescriptionService.php` |
| Requests | `app/Http/Requests/{Prescription,DoseAdminister,VisitIntake}Request.php` |
| Policy | `app/Policies/PrescriptionPolicy.php` |
| Controller | `app/Http/Controllers/Admin/PrescriptionController.php` (+ `VisitController@intake`) |

### Prescriptions & the dosing schedule

A prescription (header + one or more `dose_items`) is written on a visit via
`PrescriptionService::prescribe()` in a single transaction. Each dose item declares which
daily **slots** (Morning/Afternoon/Evening/Night), for how many **days**, from a **start
date**. `DosageScheduleGenerator` — the legacy Morning/Afternoon/Evening/Night dosing model
rebuilt as a deterministic, tested service (never model save-hooks) — expands each item into
`dose_item_records`, one per (date, slot), emitted in canonical slot order. The unique index
`(dose_item_id, scheduled_date, slot)` makes regeneration idempotent. Invalid/duplicate slots
are dropped. Nursing staff mark each scheduled dose `administered`/`missed` via
`markDose()` (stamping who + when only on administration).

### Inline new-patient intake

`VisitService::intake()` registers a brand-new patient (via `PatientService`) **and**
opens their visit in one transaction — the legacy's most bug-ridden flow, rebuilt as an
explicit `VisitIntakeRequest` DTO (`patientData()` / `visitData()`) instead of
transient patient attributes stashed on the visit model (§2.3, constraint 3). Atomic:
invalid input creates neither the patient nor the visit.

### RBAC (10b)

`prescriptions.prescribe` (doctor) · `prescriptions.administer` (nurse); both held by
hospital admin. Inline intake requires both `visits.create` and `patients.create`.

### Tests (10b)

`DosageScheduleGeneratorTest` (slots×days expansion, date/slot ordering, invalid-slot drop +
dedupe, zero-days/no-slots, idempotent regeneration); `PrescriptionHttpTest` (doctor
prescribes→schedule generated, nurse can't prescribe, nurse administers/doctor can't,
tenant isolation); `VisitIntakeTest` (register+open in one step, atomic on invalid input).
