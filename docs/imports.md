# Importing data (Settings, Import data)

A hospital administrator brings in what the clinic kept before, from an .xlsx or .csv file: **patients**, the **price list**, **stock items** (when the practice keeps stock), **appointments** already booked and past **visits**. The Visits page has an **Import** button that opens this page with Visits chosen (`?kind=visits`). Manual: chapter 02, "Importing your data".

## Flow

| Step | Status | Where |
|---|---|---|
| Upload; every row kept as it was, columns guessed | `mapping` | `DataImportService::start()`, `guess()` |
| Columns confirmed; rows typed twice in the sheet flagged | `checking` | `map()` |
| Each row checked (problems in plain words, likely duplicates) | `checking` then `checked` | `step()` |
| Choices for likely duplicates: skip, add anyway, update | `checked` | `decide()`, `decideAll()` |
| Saved through the owning service, a chunk at a time | `importing` then `done` | `import()`, `step()` |
| Undo within 24 hours of finishing | `undoing` then `undone` | `undo()`, `step()` |
| Thrown away before saving | `cancelled` | `cancel()` |

Nothing is saved before **Import**. Every button that changes a batch takes the same cache lock as a step, and a finished step only moves the batch on from the status it was working in, so a click during a poll waits instead of racing it. The long steps run a chunk per request (`CHECK_CHUNK` 200, `IMPORT_CHUNK` and `UNDO_CHUNK` 40), driven by `wire:poll` on `Imports\Show` while the batch is working, behind a cache lock per batch so two tabs take turns. A closed page only pauses the work; reopening the import from the log carries on. This needs no queue worker, shell or long request, so it works on shared hosting and on a standalone copy whose queue runs from cron.

## Pieces

- `App\Support\Imports\SpreadsheetReader`: first sheet of an .xlsx (ZipArchive + XMLReader, streamed; date-formatted cells become `Y-m-d`), or a .csv (delimiter guessed, BOM stripped, Windows-1252 converted). `.xls` is refused with how to save it as .xlsx. 20,000 rows at most.
- `App\Support\Imports\XlsxWriter`: the templates (columns, one example row, a Notes sheet; text-formatted cells so a phone keeps its 0).
- `App\Services\Imports\*Import` (one per kind, `ImportHandler`): fields with the headings people use (`ImportField::aliases`), `check()`, `save()`, `undoBlocker()`, `undo()`, and the date, time, money, yes/no and list readers.
- `import_batches` (audited as "Data import") and `import_rows` (cells as JSON, status, problems, match, decision, the record made, `extra` for what else it made).

## What each kind does

- **Patients** go through `PatientService::register()` (patient number, tenant) or `update()`. A match is the same old file number, then `DuplicateFinder::registered()`: the same phone by `PhoneNumber::tail`, or the same name and date of birth. Update fills in what the sheet gives and never blanks a field; it never changes the name, and fills sex, date of birth and old file number only where empty (a shared family phone can match the wrong person); allergies and conditions are merged. A row with no phone, date of birth or old file number is matched on the exact name, so a sheet uploaded twice is recognised. `patients.old_file_no` is searched by `Patient::search()`, so web and API find "PT 1043". A balance owed becomes `BillingService::openingBalance()`: an invoice flagged `opening_balance`, on a visit of its own opened and cancelled at once (every bill belongs to a visit; "cancelled" keeps it out of attendance counts), booked Dr receivables / Cr opening balance equity by `Bookkeeper::invoiceIssued()`. It is outstanding like any bill. An update adds one only if the patient has none.
- **Price list** through `CatalogueStore::persist()` (an archived name comes back). Match by name, then code. Update never renames.
- **Stock items** through `CatalogueStore::persist()` and `StockService::adjust(OpeningStock)` for the quantity (opening equity, not a supplier debt). Update changes details and prices, never quantity.
- **Visits** through `VisitService::recordPast()`: numbered for their own day, dated on it (09:00) and closed (outcome Closed). Patients are found as for appointments (`Concerns\FindsPatientsAndStaff`), the dentist (optional) among this hospital's staff. The treatment is a finished Procedure order: names on the price list become lines when their prices add up to the amount billed, otherwise one line carries the whole amount. The bill is `BillingService::billPastVisit()`: an invoice flagged `opening_balance` dated on the visit, `amount_paid` and `paid_before` set to what was paid then, no Payment row (so never today's takings), and only the balance still owed booked Dr receivables / Cr opening equity, dated the day of the import. A patient with a visit that day already is a likely duplicate (skip or add). Future dates are problems.
- **Appointments** through `AppointmentService::book(..., imported: true)`: the doctor's hours are not imposed (the booking predates them), a clash still is, and nobody is texted. Patients by patient number or old file number, then phone, then name; doctors among this hospital's active staff by name or email. Past dates are problems.

## Undo

Only within `ImportBatch::UNDO_HOURS` (24) of finishing, only rows the batch **added**, and only if nothing has happened to the record since; otherwise the row is `kept` with the reason. Patients: no visit (other than the balance's own), appointment, other bill, payment against the balance, card, document, dental chart entry or edit since; the balance is voided (`voidOpeningBalance()`, its entry reversed) and the patient archived. Prices: not charged or edited since; archived. Stock: no movement but the opening one; the quantity goes back out as `OpeningUndone` (against opening equity, so the books return to where they were) and the item is archived. Appointments: still scheduled and unchanged; deleted. Visits: nothing paid or credited since and no other bill or work added; deleted through `VisitService::delete()` (orders withdrawn, the bill voided and its entry reversed). Updated records keep their changes; a balance brought forward that an update added is voided if nothing was paid or credited on it.

## Who

`manage-settings` (the hospital administrator) with a hospital open; every other role, and a platform account with no hospital, gets 403. Batches and rows are tenant-scoped (`BelongsToHospital`); rows are bulk-inserted with the batch's hospital. Tests: `tests/Feature/DataImportTest.php`. Demo: `database/seeders/DemoScenarios/Imports.php`.
