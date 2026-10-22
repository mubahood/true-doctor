# Remove test records (Settings)

A clinic that learned the system on its real database removes the patients it
made while practising, before it starts for real. Manual: chapter 02, "Remove
test records".

## Who

The hospital admin of the open hospital only (`TestRecordsService::refusal()`):
not another role whatever Staff and access hands out, not a platform account
with no hospital, and never on the demonstration hospital (`Demo::enabled()`
and its slug). The menu item uses the `role` and `demo` keys in `Navigation`.

## The page

`App\Livewire\Settings\TestRecords` (route `admin.settings.test-records`). It
lists patients with their visit count, billed and paid totals and created date
(`TestRecordsService::candidates()`), filtered by **Name contains**, **Created
before** and **No payments**. Ticks are kept across pages; **Select all shown**
ticks the current page. **Remove** asks for the hospital's name, typed.

## What removing does

`TestRecordsService::remove()`:

1. Refuses a closed financial year today (`assertPostingAllowed`).
2. On a standalone copy, makes a full backup first (`MakesArchive`); if that
   fails nothing is removed.
3. Each patient in a transaction of its own, so one refusal does not stop the
   rest. A patient is kept (with the reason) when they have an insurance claim
   or any entry of their money lies in a closed financial year.
4. Stock taken for them (order lines, old-style dispensations) goes back
   through `StockService::returnFor()`, which posts its own entry.
5. Every journal entry their money made (invoices, payments, credit notes and
   refunds, their cards' movements) that is not already reversed is reversed
   by `LedgerService::reverse(..., system: true)`, dated today. Journal lines
   are never deleted.
6. Appointments are deleted, each visit is force-deleted as a model (audited,
   tombstoned for the phones), then the patient. Orders, invoices, payments,
   chart entries, notes, documents and messages go with them through the
   database's cascades. Document files are deleted after the commit.

Staff accounts are removed on User accounts (`StaffService::delete()`), which
keeps the last active hospital admin.

Tests: `tests/Feature/ClinicDeskAndCleanupTest.php`.
