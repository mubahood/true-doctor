# Laboratory & Radiology (Phase 2, Step 13)

Order-from-visit diagnostics with a catalogue, a status machine, per-item results and a
report PDF. Tenant-scoped. This is **Step 13a (Laboratory)**; Radiology (13b) mirrors it.

## Files (13a — Lab)

| Concern | File |
|---|---|
| Tables | `2026_07_19_200000_create_lab_tests_table.php`, `…_200010_create_lab_orders_table.php`, `…_200020_create_lab_order_items_table.php` |
| Models | `app/Models/{LabTest,LabOrder,LabOrderItem}.php` |
| Enums | `app/Enums/LabOrderStatus.php`, `app/Enums/ResultFlag.php` |
| Service | `app/Services/LabService.php` |
| Requests | `app/Http/Requests/{LabTest,LabOrder,LabResult}Request.php` |
| Policy | `app/Policies/LabTestPolicy.php` |
| Controllers | `app/Http/Controllers/Admin/{LabTest,LabOrder}Controller.php` |
| Views / PDF | `resources/views/admin/{lab-tests,lab-orders}/…`, `resources/views/pdf/lab-result.blade.php` |

## Flow

- **Catalogue** (`lab_tests`): name (unique per hospital), specimen, result unit, reference
  range, price — the hospital's own price list. Managed under `lab.manage`.
- **Order** (`LabService::order`): a doctor (`lab.order`) selects tests on a visit; in
  one transaction each test is **snapshotted** onto a `lab_order_item` (name/price/range) AND
  **billed** on the visit (`BillingService::addCustomLine`) — so an order and its charges
  never diverge. Status starts `ordered`.
- **Results** (`LabService::recordResult`, `lab.process`): the lab technician enters a
  `result_value`, a `ResultFlag` (normal/low/high/abnormal) and notes per item — the result is
  inline on the order item (1:1), stamping who + when.
- **Status machine** (`LabOrderStatus`): `ordered → collected → processing → completed`, cancel
  from any pre-completion state. Only `LabService::transition` moves it (locked, guarded).
- **Report PDF** (`pdf.lab-result`): the result sheet, abnormal values highlighted.

## RBAC

`lab.view` · `lab.manage` (catalogue) · `lab.order` (doctor) · `lab.process` (technician).
The technician also holds `visits.view` to open the visit behind an order.

## Tests (13a)

`LabServiceTest` (order snapshots + bills, result + flag, status machine incl. illegal jumps &
terminal, skip-ahead rejected); `LabHttpTest` (doctor orders→tech results→completes, PDF
renders, receptionist can't order, tenant isolation).

---

## Step 13b — Radiology

Mirrors Lab, with a **per-order narrative report** (findings + impression) instead of
per-value results.

| Concern | File |
|---|---|
| Tables | `2026_07_19_210000_create_radiology_studies_table.php`, `…_210010_create_radiology_orders_table.php`, `…_210020_create_radiology_order_items_table.php` |
| Models | `app/Models/{RadiologyStudy,RadiologyOrder,RadiologyOrderItem}.php` |
| Enum | `app/Enums/RadiologyOrderStatus.php` |
| Service | `app/Services/RadiologyService.php` |
| Requests | `app/Http/Requests/{RadiologyStudy,RadiologyOrder,RadiologyReport}Request.php` |
| Policy | `app/Policies/RadiologyStudyPolicy.php` |
| Controllers | `app/Http/Controllers/Admin/{RadiologyStudy,RadiologyOrder}Controller.php` |
| Views / PDF | `resources/views/admin/{radiology-studies,radiology-orders}/…`, `resources/views/pdf/radiology-report.blade.php` |

- **Catalogue** (`radiology_studies`): name, modality (X-ray/CT/MRI/Ultrasound), body part, price.
- **Order** (`RadiologyService::order`, `radiology.order`): same atomic snapshot-and-bill as lab.
- **Report** (`RadiologyService::recordReport`, `radiology.report`): the radiologist writes
  `findings` + `impression` (one narrative per order) and stamps `reported_at`.
- **Status machine** (`RadiologyOrderStatus`): `ordered → scheduled → performed → reported`,
  cancel pre-report. Only `transition` (locked, guarded) moves it.
- **Report PDF** (`pdf.radiology-report`).

RBAC: `radiology.view/manage/order/report`; the radiologist also holds `visits.view`.

Tests (`RadiologyTest`): order snapshots+bills, doctor-orders→radiologist-reports→reported,
skip-ahead rejected, PDF renders + tenant isolation.

## The benches, rebuilt

Both worklists predated the visit module and showed it: a flat list behind an
eye icon, every result two page loads away, nothing saying how long anything had
been waiting, and — the part that mattered most — **nowhere to put the file**.
The analyser's printout and the X-ray film, the two documents a hospital most
needs to keep, had no home at all.

### One attachment store, three kinds of work

`order_attachments.order_id` pointed at `orders` and nothing else. It is now
`attachments`, polymorphic, and `Order`, `LabOrder` and `RadiologyOrder` all
implement `App\Models\Contracts\HoldsAttachments`. Existing rows were copied
across keeping their uuid, path and uploader, so download links in old records
go on working.

The table was CREATED and the old one dropped rather than altered: making
`order_id` nullable on SQLite means rebuilding the table, which is the cascade
that once deleted every order and bill line in the system (`App\Support\SchemaKeys`).

Files are collected by `App\Livewire\Concerns\CollectsAttachments`, shared by
both benches — the host says what the files belong to and who may add them, and
everything else is `OrderAttachmentService`, unchanged. They stay on the PRIVATE
disk and are streamed behind a permission: `lab.view` for a lab result,
`radiology.view` for a film, and never through the wrong order.

### What each bench now shows

The same shape as the rest of the system: what is outstanding across the top —
with **the oldest thing still waiting**, because a count of orders is not a
workload — then one row per order, sortable, with a designed empty state and a
row menu. An order left too long carries its age beside its status.

The result is entered **over the list**, never on another page:

- **Lab** — every test's value, flag and note in one dialog, saved in one press
  rather than one field at a time on blur, plus the files. "Save and report
  back" writes the results and hands the order back together.
- **Radiology** — findings and impression with the images beside them, and
  "Save and sign off" does both.

Both dialogs also ask **what the work used**. A test is not only its own fee —
it uses reagents, tubes, sometimes a repeat run; a study uses contrast and film
— and the benches could record none of it, so the hospital did the work and
billed the list price whatever it actually cost.

The lines go onto the order the work is ALREADY billed through. `LabService`
and `RadiologyService` place one when the work is ordered, of type `Lab` or
`Imaging`, with the record as its `subject`, and bill the tests onto it;
`OrderService::forSubject()` finds that order (or places one, for a record made
before the companion existed) so one piece of work stays one set of charges.

That question is asked the same way everywhere it comes up —
`App\Livewire\Concerns\ChargesWorkDone` and one Blade partial, shared with the
appointment outcome dialog. A service is sold; a product also leaves the shelf,
in the same transaction as the money, which is `OrderService::syncLines()` and
not three copies of it. Quantities are whole units, and a line taken off comes
off the bill and puts its stock back.

`LabService` and `RadiologyService` still own every write and every status move,
so an illegal transition is refused whatever asks for it.
