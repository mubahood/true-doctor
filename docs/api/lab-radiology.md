# Lab and radiology API

The two benches over the API (module doc: [../lab-radiology.md](../lab-radiology.md)). All under `/api/v1`, Sanctum bearer, the usual envelope; another hospital's order is a 404. Rules live in `LabService`, `RadiologyService`, `LabBench` and `RadiologyBench`, the same classes the web worklists use.

| Method | Path | Permission | What |
|---|---|---|---|
| GET | `lab-orders` | `lab.view` | The lab worklist. `?q=` (patient name, number or phone), `?status=`, `?outstanding=1` (oldest first), `?page=`, `?per_page=` (20, at most 100), `?updated_since=` |
| GET | `lab-orders/{uuid}` | `lab.view` | One order with its tests and results |
| POST | `lab-orders/{uuid}/transition` | `lab.process` | Body `{status}`: collected, processing, completed or cancelled where `LabOrderStatus` allows it; 422 otherwise |
| GET | `lab-orders/{uuid}/pdf` | `lab.view` | The laboratory report, `application/pdf`, inline: the web's own document (`Admin\LabOrderController::pdf`, template `pdf/lab-result`) |
| GET | `radiology-orders` | `radiology.view` | The radiology worklist, the same filters as the lab's |
| GET | `radiology-orders/{uuid}` | `radiology.view` | One order |
| POST | `radiology-orders/{uuid}/transition` | `radiology.report` | Body `{status}`: scheduled, performed, reported or cancelled where `RadiologyOrderStatus` allows it |
| PUT | `radiology-orders/{uuid}/report` | `radiology.report` | Body `{findings?, impression?, sign_off?}`; `sign_off: true` also marks it Reported, in one press |
| GET | `radiology-orders/{uuid}/pdf` | `radiology.view` | The radiology report, `application/pdf`, inline: the web's own document (`Admin\RadiologyOrderController::pdf`, template `pdf/radiology-report`) |

Lab results are written through offline sync (entity `lab_items`), so a bench with no signal still reports.

## Paging

Both lists page like every list (`PagesAndDeltas`): `meta.current_page`, `per_page`, `total` and `last_page`, beside the bench's own `meta.tally` (to collect / to schedule, collected / scheduled, processing / performed, reported today, and `oldest`, the hours the oldest outstanding order has waited) and `meta.old_hours`. The app says "50 of 212" from `total` and asks for the next page while `current_page < last_page`.

## The report PDFs

The phone prints and shares exactly what the web prints: the API routes call the web controllers, so the template, the letterhead, the permission and the tenant scope are one. The document is rendered from the order as it is now (results entered on a device and not yet sent are not in it). A refused document is a 403 envelope and is never answered from a copy saved on a device; a hospital's server older than this endpoint answers 404 and the app says it cannot produce the document.
