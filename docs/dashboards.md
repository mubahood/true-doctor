# True-Doctor — Role-Based Dashboards Plan

Status: **v1 delivered** — all 10 roles + super-admin + custom-role fallback are
live (DashboardService + widget components + per-role views + tests: 394 pass,
PHPStan/Pint clean). Owner: HMS restructure. Replaced the Phase-0 placeholder
dashboard (a single "Staff accounts" stat + "restructure in progress" note).

Delivered: `app/Support/Dashboard/DashboardService.php` (metrics), rewritten
`DashboardController`, `resources/views/admin/dashboard/` (index + roles/* +
`components/dash/*`), dashboard CSS block in `public/css/td-admin.css`, and
`DashboardServiceTest` + `DashboardHttpTest`. Remaining/optional: live `wire:poll`
islands (§3), user-customisable layout (§10).

## 0. The admin cockpit, rebuilt around visits, orders and cards

The hospital-admin dashboard was written before visits had stages, before work
became orders, and before cards had an insurer behind them. It opened on the
size of the patient REGISTER — a number that barely moves and that nobody acts
on — and the only work it could see was the two kinds that happen to have their
own module, lab and radiology.

It now answers the three questions an administrator actually walks in with.

**How much is in the building?** `admin.visit-flow` is the pipeline in the order
a visit moves through it — Not started → In care → Billing → Payment — each step
a link into the visits list filtered to exactly that stage. It is a flow, not a
bar chart: bars sorted by size hide the thing you are looking for, which is
where people are piling up. `visitsOpen()` also returns **blocked**: visits that
cannot reach billing because an order on them is still open. That is the one
figure on the page anybody can act on before lunch.

**What is holding it up?** `admin.work-queue` counts every open order by type,
busiest first, and says how long the oldest has been waiting. A dispensing
nobody has picked up holds a visit out of billing exactly as a lab order does,
and until now it was invisible.

**Is the money keeping up?** `admin.money-trend` draws TWO lines on one scale —
what was billed and what was collected. A single line of payments answers "did
money come in"; it cannot answer whether it keeps up with the work going out.
The space between the two lines is the outstanding balance, accruing in front of
you, and the legend names it.

`admin.cards` adds the card desk: money **held** on behalf of patients, money
**owed** by members in debt, and the insurers' float. Held and owed are opposite
sides of one instrument — the old `cardFloat()` summed them and said neither.

The headline row leads with **Open visits** and ends with **Outstanding**; the
patient register moved to the side stats, where reference numbers belong.

`awaitingTriage()`/`triageQueue()` were the last code carrying a word the
product retired; they are `notStarted()`/`notStartedQueue()`, and the nurse
widget is `nurse.not-started`. `AdminDashboardTest` asserts no dashboard widget
prints the word.

## 1. Problem & objective

The current `/admin` dashboard is identical for every user and shows almost
nothing. Each role logs in to do a specific job; the dashboard should be the
**cockpit for that job** — the numbers they watch, the work waiting for them, and
one-click access to what they do most.

Objective: a distinct, data-rich dashboard per role, every figure pulled live from
the real modules, tenant-scoped, permission-gated, and consistent with the
existing squared `tb-*` design system (no emojis; FontAwesome icons).

## 2. Design principles

1. **Permission-driven, not role-hardcoded.** Every widget declares the permission
   it needs. A role sees a widget only if it holds that permission. This keeps
   custom/edited roles correct automatically and means one widget catalogue serves
   all roles. Role only decides *layout & emphasis* (which widgets are "hero").
2. **Real data only.** Every metric maps to a confirmed column/enum (see §5). No
   mock numbers, no placeholder charts. Empty states are designed, not blank.
3. **Tenant-safe by construction.** Clinical/billing/stock models are auto-scoped
   by `HospitalScope`. `User` is scoped manually (`User::currentHospital()`).
   Super-admin (null hospital) gets the SaaS overview, not tenant widgets.
4. **Money via bcmath + `HospitalSettings::format()`.** Never float-sum money;
   follow `ReportService` (decimal strings, `bcadd`/`bccomp`). Display in the
   hospital's currency (UGX default).
5. **Fast & lean.** Aggregate queries only (counts, sums, small `limit` lists).
   No N+1 — eager-load the few relations shown in queue lists. Target < ~15
   lightweight queries per dashboard.
6. **One design language.** Reuse `tb-stat-card`, `tb-card`, `tb-stats-grid`.
   Add a small set of new squared components: quick-action tile, queue list,
   mini bar-chart, donut (CSS `conic-gradient`), trend sparkline (inline SVG).
   Charts are dependency-free (no Chart.js) to stay on-CSP-safe and on-design.

## 3. Architecture

```
app/Support/Dashboard/
  DashboardService.php     # role-agnostic metric methods; tenant-scoped; bcmath
  Widget.php               # value object: key, title, icon, permission, data
  DashboardBuilder.php     # assembles the per-role bundle (hero/sections/actions)
app/Http/Controllers/Admin/DashboardController.php   # thin: build bundle -> view
resources/views/admin/dashboard/
  index.blade.php          # shell: greeting + renders the bundle
  _partials/
    stat.blade.php         # hero KPI card (value, label, icon, optional delta)
    quick-actions.blade.php
    queue.blade.php        # compact list widget (title + rows + "view all")
    bars.blade.php         # horizontal mini bar-chart (label + value + bar)
    donut.blade.php        # CSS conic-gradient donut + legend
    sparkline.blade.php    # inline-SVG 14/30-day trend line
  roles/                   # one thin include per role, composing the partials
    admin.blade.php  doctor.blade.php  nurse.blade.php  receptionist.blade.php
    pharmacist.blade.php  lab.blade.php  radiology.blade.php  accountant.blade.php
    records.blade.php  super.blade.php  fallback.blade.php
```

- **`DashboardService`** holds one public method per metric (returns a small
  array/scalar). Reuses `ReportService` for `occupancy()`, `stockValuation()`,
  `revenue()` where they already fit; new methods for role queues.
- **`DashboardController@index`**: resolve `$user`; if `isSuperAdmin()` → super
  bundle; else pick the role view by `$user->role`, defaulting to `fallback`
  (renders whatever widgets the user's permissions allow). Every widget method is
  still permission-checked in the view via `@can`, so the role file can't leak a
  widget the user isn't allowed.
- **Live updates (phase 2, optional):** the heavy queue widgets can later become
  Livewire `wire:poll` islands. v1 is server-rendered on load with a manual
  refresh; structure the partials so they can be lifted into Livewire without
  rewrite.

## 4. Shared UI components (new, squared, on-palette)

| Component | Use | Notes |
|---|---|---|
| `stat.blade.php` | Hero KPI | value (big), label (caps), FA icon in tinted square, optional sub-line ("3 today") and click-through route |
| `quick-actions.blade.php` | Row of action tiles | each tile = icon + label + route; only rendered if `@can(permission)` |
| `queue.blade.php` | "Work waiting" list | title, count badge, up to N rows (patient/time/status badge), "View all →" link |
| `bars.blade.php` | Categorical breakdown | e.g. payments by method, appts by status; value + proportional bar |
| `donut.blade.php` | Part-to-whole | e.g. bed occupancy, patient sex; CSS conic-gradient, legend list |
| `sparkline.blade.php` | Trend over time | 14-day revenue / registrations; inline SVG polyline, no lib |

## 5. Metric catalogue (every figure → real query)

Confirmed columns from the domain audit. All queries below are tenant-scoped by
the global scope unless noted. "today" = `whereDate(<col>, today())`.

### Patients
- New patients today / this week — `Patient::whereDate('created_at', today())` / week range.
- By status — `Patient::groupBy('status')` (`PatientStatus`: active/inactive/deceased).
- Demographics (sex/age bands) — reuse `ReportService::demographics()`.
- Prepaid card float — `PatientCard::where('status','active')->sum('balance')`.

### Appointments (doctor FK `doctor_user_id`, time `scheduled_at`)
- Today's appointments (all / mine) — `Appointment::forDate(today())` [+ `->where('doctor_user_id',$id)`].
- By status today — group on `status` (`AppointmentStatus`).
- Awaiting start (checked-in) — `status = checked_in`.
- No-shows this week — `status = no_show`, week range.

### Visits / visits (doctor FK `doctor_user_id`; "today" = `created_at`)
- My open visits — `where('doctor_user_id',$id)->where('status','consultation')`.
- Awaiting triage — `status = triage` (nurse/receptionist relevance).
- Pipeline funnel — group on `status` (`VisitStatus`).
- Visits today — `whereDate('created_at', today())`.

### Prescriptions / dosing / treatments
- Rx written today (mine) — `Prescription::where('prescribed_by',$id)->whereDate('created_at',today())`.
- Doses due today (pending) — `DoseItemRecord::whereDate('scheduled_date',today())->where('status','pending')` (by `DoseSlot`).
- Missed doses today — `status = missed`, today.
- Procedures today — `TreatmentRecord::whereDate('performed_at',today())` [+ `performed_by`].

### Billing / finance (money = bcmath; format via HospitalSettings)
- Revenue today / this month — `Payment::whereBetween('created_at',[...])` → `sum('amount')` (bcmath).
- Revenue by method — group `method` (`PaymentMethod`), bcadd `amount`.
- 14-day revenue trend — per-day `Payment` sums (sparkline).
- Outstanding invoices — `Invoice::whereIn('status',['issued','partially_paid'])` → count + `sum('balance')`.
- Invoices by status — group `status` (`InvoiceStatus`).
- Pending insurance claims — `InsuranceClaim::whereIn('status',['submitted','approved'])` → count + `sum('amount')`.
- Service revenue leaderboard — reuse `ReportService::serviceRevenue()`.

### Pharmacy (stock)
- Low-stock items — `StockItem::lowStock()` (scope: `current_quantity <= reorder_level`).
- Expiring ≤90d — `StockItem::expiringBefore(now()->addDays(90))`.
- Stock valuation — reuse `ReportService::stockValuation()` (value/items/low/expiring).
- Dispensations today — `Dispensation::whereDate('created_at',today())` count; top drugs via `DispensationItem` group.

### Laboratory (status `LabOrderStatus`)
- Pending worklist — `LabOrder::whereIn('status',['ordered','collected','processing'])` count (+ by stage).
- Results in progress — `status = processing`.
- Abnormal results (recent) — `LabOrderItem::whereIn('result_flag',['low','high','abnormal'])`.
- Completed today — `status = completed`, `whereDate('completed_at',today())`.

### Radiology (status `RadiologyOrderStatus`)
- Pending orders — `whereIn('status',['ordered','scheduled'])`.
- Awaiting report — `status = performed`.
- Reported today — `status='reported'`, `whereDate('reported_at',today())` [+ `reported_by`].

### Inpatient / IPD
- Current inpatients — `Admission::where('status','admitted')` count.
- Admissions / discharges today — `admitted_at` / (`status=discharged` + `discharged_at`) today.
- Bed occupancy — reuse `ReportService::occupancy()` (total/occupied/available/rate); per-ward donut via `Ward`→`beds`.
- Nursing activity today — `NursingNote` / `VitalRound` / `MedicationAdministration` counts (by `created_at`).

### Staff / org (User scoped manually)
- Staff accounts — `User::where('hospital_id',$hid)->count()`.
- Staff by role — group `role`.

### Super-admin (SaaS, cross-tenant — NOT tenant-scoped; super context)
- Total hospitals / by `HospitalStatus`; active vs trialing subscriptions
  (`SubscriptionStatus`); trials expiring ≤7 days; subscribers per plan
  (`Plan::withCount('subscriptions')`); MRR estimate from active subs × plan price.

## 6. Per-role dashboards

Layout = **Hero KPI row** (3–5 stat cards) → **Primary work** (queues/tables the
role acts on) → **Quick actions** → **Charts/analytics**. Each item lists its
gating permission.

### 6.1 hospital_admin — "Hospital at a glance" (has almost every permission)
- Hero: Patients (total) · Today's appointments · Current inpatients · Revenue today · Outstanding balance.
- Charts: 14-day revenue sparkline; bed occupancy donut; appointments-by-status bars; revenue-by-method bars.
- Sections: Low-stock alerts (pharmacy.view) · Pending lab/radiology counts · Pending insurance claims (finance.view) · Staff-by-role.
- Quick actions: Register patient, Book appointment, New invoice list, Occupancy board, Reports, Invite staff.

### 6.2 doctor — "My clinic today" (`visits.*`, `appointments.view`, `lab.order`, `radiology.order`, `prescriptions.prescribe`, `ipd.*`)
- Hero: My appointments today · My open visits · Rx written today · My inpatients (admitting_doctor_id).
- Primary: **My open visits** queue (`doctor_user_id` + `status=visit`) with patient + reason → open visit. **Today's diary** (`Appointment::forDate` mine) with times + status.
- Sections: My pending lab/radiology orders (ordered_by = me, non-terminal).
- Quick actions: New visit (intake), My appointments, Patients.

### 6.3 nurse — "Ward & triage" (`visits.vitals`, `prescriptions.administer`, `ipd.*`, `treatments.manage`)
- Hero: Awaiting triage (vitals) · Doses due today · Current inpatients · Vital rounds today.
- Primary: **Triage queue** (`visit.status=triage`) → record vitals. **Medication round** — doses due today by `DoseSlot` (pending) → administer.
- Sections: Missed doses today; nursing notes logged today.
- Quick actions: Occupancy board, Record vitals (from queue), Patients.

### 6.4 receptionist — "Front desk" (`patients.create`, `appointments.manage`, `visits.create`, `billing.manage`, `patients.card.manage`)
- Hero: New patients today · Today's appointments · Checked-in waiting · Payments taken today.
- Primary: **Today's appointment queue** (all doctors) with check-in status. **Awaiting payment** visits (`status=payment`)/outstanding invoices.
- Quick actions: Register patient, Book appointment, Intake (register+visit), Take payment (invoices), Issue/top-up card.

### 6.5 pharmacist — "Dispensary" (`pharmacy.*`)
- Hero: Low-stock items · Expiring ≤90d · Stock value · Dispensations today.
- Primary: **Low-stock worklist** (item, qty, reorder level) → receive stock. **Expiring items** (item, expiry, days left).
- Charts: Top dispensed drugs today (bars).
- Quick actions: Stock list, Receive stock, Stock alerts, Categories.

### 6.6 lab_technician — "Lab worklist" (`lab.*`)
- Hero: Pending orders · Collected (awaiting processing) · Processing · Completed today.
- Primary: **Worklist** (`whereIn status ordered/collected/processing`) — patient, tests, ordered time, status → open order/record result.
- Sections: Recent abnormal results (flag in low/high/abnormal).
- Quick actions: Lab worklist, Test catalogue.

### 6.7 radiologist — "Imaging worklist" (`radiology.*`)
- Hero: Pending orders · Awaiting report (performed) · Reported today.
- Primary: **Report queue** (`status=performed`) — patient, study/modality, time → write report. **Scheduled/ordered** list.
- Quick actions: Radiology worklist, Study catalogue.

### 6.8 accountant — "Finance" (`finance.*`, `billing.*`, `insurance.*`, `services.manage`, `reports.view`)
- Hero: Revenue today · Revenue this month · Outstanding balance · Pending claims (amount).
- Charts: 14-day revenue sparkline; revenue-by-method bars; invoices-by-status bars.
- Sections: Outstanding invoices list (patient, balance, age); pending insurance claims (provider, amount, status); service-revenue leaderboard; open financial year.
- Quick actions: Invoices, Insurance claims, Financial years, Reports.

### 6.9 records_officer — "Records" (`patients.*`)
- Hero: Total patients · New today · New this week · Deceased/inactive.
- Charts: Registrations 14-day sparkline; patients-by-status donut; demographics bars.
- Primary: Recently registered patients list.
- Quick actions: Register patient, Patients list.

### 6.10 super_admin — "SaaS central"
- Hero: Total hospitals · Active subscriptions · Trials expiring ≤7d · Estimated MRR.
- Charts: Hospitals-by-status donut; subscriptions-by-status bars; subscribers-per-plan bars.
- Sections: Hospitals signed up recently; subscriptions needing attention (past-due/trialing).
- Quick actions: Hospitals, Plans, Subscriptions, Site settings.

### 6.11 fallback (custom roles / unknown)
- Render the permission-gated widget set: any hero KPI + queue whose permission
  the user holds. Guarantees a useful dashboard for any RBAC configuration.

## 7. Permission & tenancy rules

- Each widget wrapped in `@can('<permission>')`; the `DashboardService` method is
  only called when the permission holds (avoid wasted queries — the builder checks
  permission before computing).
- Super-admin path never runs tenant widgets; it runs the SaaS aggregates in the
  super (cross-tenant) context.
- `User` counts always `->where('hospital_id', CurrentHospital::id())`.
- Money formatted with `app(HospitalSettings::class)->format()`.

## 8. Implementation order (one by one)

1. **Infra**: `DashboardService` (metrics), `DashboardBuilder`, controller rewrite,
   new Blade partials + CSS for the new components. Ship with **hospital_admin**
   dashboard first (exercises the most widgets).
2. doctor → 3. nurse → 4. receptionist → 5. pharmacist → 6. lab_technician →
   7. radiologist → 8. accountant → 9. records_officer → 10. super_admin →
   11. fallback.

Each step: implement widgets + role view, add tests, run Pint/PHPStan/suite, commit.

## 9. Testing

- `DashboardServiceTest` (unit-ish, RefreshDatabase): each metric returns correct
  numbers on seeded data; money sums exact; **tenant isolation** (a second
  hospital's rows never counted).
- `DashboardHttpTest`: each role logs in → `/admin` 200, sees its hero labels &
  quick actions, does **not** see widgets it lacks permission for; super-admin sees
  SaaS overview; onboarding gate interaction respected (gate off in tests).
- Reuse existing factories; assert exact counts for at least one metric per role.

## 10. Out of scope (v1)

- Real-time push / websockets (later: `wire:poll` islands).
- User-customisable widget layout / drag-drop.
- Historical warehousing; all metrics computed live from operational tables.
