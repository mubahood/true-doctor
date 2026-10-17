<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Enums\InvoiceStatus;
use App\Enums\StockMovementReason;
use App\Livewire\Imports\Index;
use App\Livewire\Imports\Show;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\DataImportService;
use App\Services\Finance\LedgerService;
use App\Support\CurrentHospital;
use App\Support\Imports\SpreadsheetReader;
use App\Support\Imports\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Settings, Import data (docs/imports.md): templates, reading sheets, matching
 * columns, the check, importing through the owning services, undo within a
 * day, permissions and tenancy.
 */
class DataImportTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital();
        $this->admin = $this->actingAsRole('hospital_admin', $this->hospital);
    }

    private function service(): DataImportService
    {
        return app(DataImportService::class);
    }

    /** Write a CSV to a temporary file and start an import from it. */
    private function start(ImportKind $kind, string $csv, string $name = 'sheet.csv'): ImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $csv);

        return $this->service()->start($kind, $path, $name, $this->admin->id);
    }

    /** Run every chunk of whatever is going on. */
    private function drive(ImportBatch $batch): ImportBatch
    {
        for ($i = 0; $i < 100 && $batch->status->isWorking(); $i++) {
            $batch = $this->service()->step($batch, $this->admin->id);
        }

        return $batch;
    }

    /** Map as guessed, check, and return the checked batch. */
    private function checked(ImportKind $kind, string $csv): ImportBatch
    {
        $batch = $this->start($kind, $csv);
        $batch = $this->service()->map($batch, $batch->column_map ?? []);

        return $this->drive($batch);
    }

    private function row(ImportBatch $batch, int $line): ImportRow
    {
        return $batch->rows()->where('line', $line)->firstOrFail();
    }

    // ── Templates and reading files ──────────────────────────────────────

    public function test_every_template_is_a_workbook_we_can_read_back_with_its_columns_and_example(): void
    {
        foreach ($this->service()->kinds() as $kind) {
            $bytes = $this->service()->template($kind);
            $path = tempnam(sys_get_temp_dir(), 'tpl');
            file_put_contents($path, $bytes);

            $rows = SpreadsheetReader::read($path, 'template.xlsx');
            $labels = array_map(fn ($f) => $f->label, $this->service()->handler($kind)->fields());

            $this->assertSame($labels, $rows[1], $kind->value.' headings');
            $this->assertArrayHasKey(2, $rows, $kind->value.' has an example row');
            // The guess recognises its own template completely.
            $this->assertNotContains(null, $this->service()->guess($kind, $rows[1]), $kind->value.' maps itself');
        }
    }

    public function test_the_template_downloads_from_the_page(): void
    {
        Livewire::test(Index::class)->call('template', 'patients')->assertFileDownloaded('patients-template.xlsx');
    }

    public function test_a_csv_with_semicolons_a_byte_order_mark_and_old_windows_letters_reads_cleanly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "\xEF\xBB\xBFName;Phone\nAm\xE9lie Nakato;0772 123456\n;\n");

        $rows = SpreadsheetReader::read($path, 'list.csv');

        $this->assertSame(['Name', 'Phone'], $rows[1]);
        $this->assertSame(['Amélie Nakato', '0772 123456'], $rows[2]);
        $this->assertCount(2, $rows, 'an empty row is left out');
    }

    public function test_an_xlsx_with_shared_strings_numbers_and_date_cells_reads_as_people_see_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xl');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="List" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/data.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Name</t></si><si><t>DOB</t></si><si><t>Balance</t></si><si><r><t>Grace </t></r><r><t>Achieng</t></r></si></sst>');
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/data.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
            .'<row r="3"><c r="A3" t="s"><v>3</v></c><c r="B3" s="1"><v>33283</v></c><c r="D3"><v>25000.000000001</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $rows = SpreadsheetReader::read($path, 'list.xlsx');

        $this->assertSame(['Name', 'DOB', 'Balance'], $rows[1]);
        $this->assertSame(['Grace Achieng', '1991-02-14', '', '25000'], $rows[3], 'row numbers are the sheet\'s own; dates read as dates');
    }

    public function test_an_old_xls_file_is_refused_with_how_to_save_it_instead(): void
    {
        $this->expectExceptionMessage('Save as');
        SpreadsheetReader::read(__FILE__, 'old.xls');
    }

    public function test_headings_people_actually_use_are_matched_to_the_right_fields(): void
    {
        $map = $this->service()->guess(ImportKind::Patients, ['File No', 'Names', 'Sex', 'D.O.B', 'Age', 'Tel', 'NOK', 'NOK Phone', 'Allergies', 'Balance', 'Village']);

        $this->assertSame(0, $map['old_file_no']);
        $this->assertSame(1, $map['full_name']);
        $this->assertSame(3, $map['dob']);
        $this->assertSame(4, $map['age']);
        $this->assertSame(5, $map['phone']);
        $this->assertSame(6, $map['next_of_kin']);
        $this->assertSame(7, $map['next_of_kin_phone']);
        $this->assertSame(9, $map['opening_balance']);
        $this->assertSame(10, $map['address']);

        $this->assertSame(0, $this->service()->guess(ImportKind::Patients, ['Phone No'])['phone']);
        $this->assertSame(0, $this->service()->guess(ImportKind::Patients, ['Contact'])['phone']);
    }

    // ── Patients ─────────────────────────────────────────────────────────

    private function patientsCsv(): string
    {
        return implode("\n", [
            'File No,Full name,Sex,DOB,Age,Phone,Next of kin,NOK phone,Allergies,Conditions,Balance',
            'PT 1043,Amina Nakato,F,14/02/1991,,0772 123456,Joseph Okello,0701 987654,"Penicillin, Sulfa",Hypertension,"25,000"',
            'PT 1044,John Okello,M,,34,0700 111222,,,None,,',
            'PT 1045,Grace,F,,,,,,,,',
            'PT 1046,Peter Mugisha,M,31/31/1990,,,,,,,',
            'PT 1047,Sarah Atim,F,,,+256 782 555 666,,,,,',
            'PT 1048,Amina Nakato,F,14/02/1991,,0772123456,,,,,',
        ]);
    }

    public function test_the_check_saves_nothing_and_says_what_it_found_in_plain_words(): void
    {
        $registered = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Sarah', 'last_name' => 'Atim', 'phone_1' => '0782555666']);
        $before = Patient::query()->count();

        $batch = $this->checked(ImportKind::Patients, $this->patientsCsv());

        $this->assertSame(ImportStatus::Checked, $batch->status);
        $this->assertSame($before, Patient::query()->count(), 'checking saves nothing');

        $this->assertSame(ImportRow::READY, $this->row($batch, 2)->status);
        $this->assertSame(ImportRow::READY, $this->row($batch, 3)->status);
        $this->assertSame(['Only one name is given. Add the other name.'], $this->row($batch, 4)->problems);
        $this->assertStringContainsString('is not a date we can read', $this->row($batch, 5)->problems[0]);

        $dup = $this->row($batch, 6);
        $this->assertSame(ImportRow::DUPLICATE, $dup->status, 'same phone however it is written');
        $this->assertSame($registered->id, $dup->match_id);
        $this->assertStringContainsString('Same phone', (string) $dup->match_reason);
        $this->assertSame('skip', $dup->decision, 'a likely duplicate is skipped unless told otherwise');

        $this->assertSame(['The same as row 2 of this sheet.'], $this->row($batch, 7)->problems);
    }

    public function test_patients_are_registered_through_the_service_with_numbers_old_file_numbers_and_their_details(): void
    {
        $batch = $this->checked(ImportKind::Patients, $this->patientsCsv());
        $batch = $this->drive($this->service()->import($batch));

        $this->assertSame(ImportStatus::Done, $batch->status);
        $this->assertSame(3, $batch->added_count);
        $this->assertSame(3, $batch->skipped_count, 'the three rows with problems are left out');

        $amina = Patient::query()->where('old_file_no', 'PT 1043')->firstOrFail();
        $this->assertSame($this->hospital->id, $amina->hospital_id);
        $this->assertMatchesRegularExpression('/^PT-\d{4}-\d{6}[A-Z0-9]$/', $amina->patient_no);
        $this->assertSame('Amina', $amina->first_name);
        $this->assertSame('Nakato', $amina->last_name);
        $this->assertSame('1991-02-14', $amina->dob->toDateString());
        $this->assertSame(['Penicillin', 'Sulfa'], $amina->allergies);
        $this->assertSame(['Hypertension'], $amina->chronic_conditions);
        $this->assertSame('Joseph Okello', $amina->emergency_contact_name);
        $this->assertSame('0701 987654', $amina->emergency_contact_phone);
        $this->assertSame($this->admin->id, $amina->registered_by);

        $john = Patient::query()->where('old_file_no', 'PT 1044')->firstOrFail();
        $this->assertSame(34, $john->age(), 'an age becomes a date of birth that far back');
        $this->assertNull($john->allergies, '"None" is no allergy');

        // "PT 1043 in our old book" still finds her, on the web and the API.
        $this->assertSame([$amina->id], Patient::query()->search('PT 1043')->pluck('id')->all());
        $this->getJson('/api/v1/patients?q=1043')->assertOk()->assertJsonPath('data.0.old_file_no', 'PT 1043');
    }

    public function test_a_balance_owed_becomes_a_balance_brought_forward_in_the_books_against_opening_equity(): void
    {
        $batch = $this->checked(ImportKind::Patients, $this->patientsCsv());
        $this->drive($this->service()->import($batch));
        $amina = Patient::query()->where('old_file_no', 'PT 1043')->firstOrFail();

        $invoice = Invoice::query()->where('patient_id', $amina->id)->sole();
        $this->assertTrue($invoice->opening_balance);
        $this->assertNotNull($invoice->visit, 'every bill belongs to a visit');
        $this->assertTrue($invoice->visit->wasCancelled(), 'one that is not counted as an attendance');
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('25000.00', (string) $invoice->balance);
        $this->assertSame(1, Invoice::query()->outstanding()->where('patient_id', $amina->id)->count(), 'it is owed like any other bill');

        $entry = app(LedgerService::class)->postedFor($invoice, 'issued');
        $this->assertNotNull($entry);
        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->with('account')->get();
        $this->assertSame('25000.00', (string) $lines->firstWhere('account.system_key', 'receivable')?->debit);
        $this->assertSame('25000.00', (string) $lines->firstWhere('account.system_key', 'opening_equity')?->credit);
        $this->assertNull($lines->first(fn ($l) => str_starts_with((string) $l->account->system_key, 'revenue')), 'never income');

        // John owed nothing: no invoice for him.
        $this->assertSame(0, Invoice::query()->whereHas('patient', fn ($q) => $q->where('old_file_no', 'PT 1044'))->count());
    }

    public function test_a_likely_duplicate_can_update_the_registered_patient_or_be_added_anyway(): void
    {
        $sarah = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Sarah', 'last_name' => 'Atim', 'phone_1' => '0782555666', 'allergies' => ['Latex']]);
        $csv = "Name,Phone,Allergies,File No,Balance\nSarah Atim,+256 782 555 666,Aspirin,PT 2001,5000\nJoseph Atim,0782 555 666,,PT 2002,";

        $batch = $this->checked(ImportKind::Patients, $csv);
        $this->service()->decide($batch, $this->row($batch, 2)->id, 'update');
        $this->service()->decide($batch, $this->row($batch, 3)->id, 'add');
        $batch = $this->drive($this->service()->import($batch));

        $sarah->refresh();
        $this->assertSame('PT 2001', $sarah->old_file_no);
        $this->assertSame(['Latex', 'Aspirin'], $sarah->allergies, 'allergies are added to, never replaced');
        $this->assertSame(1, Invoice::query()->where('patient_id', $sarah->id)->where('opening_balance', true)->count());
        $this->assertSame(1, $batch->updated_count);
        $this->assertSame(1, Patient::query()->where('old_file_no', 'PT 2002')->count(), 'added anyway');

        // Importing the same balance again does not owe it twice.
        $again = $this->checked(ImportKind::Patients, $csv);
        $this->service()->decideAll($again, 'update');
        $this->drive($this->service()->import($again));
        $this->assertSame(1, Invoice::query()->where('patient_id', $sarah->id)->where('opening_balance', true)->count());
    }

    public function test_an_import_is_undone_within_a_day_except_what_has_been_used_since(): void
    {
        $batch = $this->checked(ImportKind::Patients, $this->patientsCsv());
        $batch = $this->drive($this->service()->import($batch));
        $amina = Patient::query()->where('old_file_no', 'PT 1043')->firstOrFail();
        $john = Patient::query()->where('old_file_no', 'PT 1044')->firstOrFail();
        Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $john->id]);
        $invoice = Invoice::query()->where('patient_id', $amina->id)->sole();

        $batch = $this->drive($this->service()->undo($batch, $this->admin->id));

        $this->assertSame(ImportStatus::Undone, $batch->status);
        $this->assertSame($this->admin->id, $batch->undone_by);
        $this->assertSoftDeleted($amina);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status, 'the balance brought forward is taken back');
        $this->assertNotNull(app(LedgerService::class)->postedFor($invoice, 'voided'), 'and out of the books');
        $this->assertNotSoftDeleted($john);
        $kept = $batch->rows()->where('record_id', $john->id)->sole();
        $this->assertSame(ImportRow::KEPT, $kept->status);
        $this->assertSame('Kept: Has had a visit since.', $kept->message);
        $this->assertSame(2, $batch->undone_count);
    }

    public function test_an_update_never_changes_who_the_patient_is_and_its_balance_is_taken_back_on_undo(): void
    {
        $mother = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Esther', 'last_name' => 'Namuli', 'phone_1' => '0784610233', 'dob' => '1990-03-01', 'sex' => 'female', 'address' => null]);
        // Her child, on her phone, in the sheet: the phone matches the mother.
        $batch = $this->checked(ImportKind::Patients, "Name,Sex,Age,Phone,Address,Balance\nPrecious Nakimuli,F,7,0784 610 233,Kireka,30000");
        $this->assertSame($mother->id, $this->row($batch, 2)->match_id);
        $this->service()->decideAll($batch, 'update');
        $batch = $this->drive($this->service()->import($batch));

        $mother->refresh();
        $this->assertSame('Esther', $mother->first_name, 'a name is never overwritten by a sheet');
        $this->assertSame('1990-03-01', $mother->dob->toDateString(), 'nor a date of birth already recorded');
        $this->assertSame('Kireka', $mother->address, 'what was missing is filled in');
        $invoice = Invoice::query()->where('patient_id', $mother->id)->sole();

        $batch = $this->drive($this->service()->undo($batch, $this->admin->id));

        $this->assertSame(ImportStatus::Undone, $batch->status);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status, 'the balance the update added is taken back');
        $this->assertNotSoftDeleted($mother);
        $this->assertSame('Kireka', $mother->fresh()->address, 'the update itself stays');
    }

    public function test_a_sheet_with_only_names_is_recognised_when_uploaded_again(): void
    {
        $csv = "Name,Balance\nGrace Achieng,10000";
        $this->drive($this->service()->import($this->checked(ImportKind::Patients, $csv)));

        $again = $this->checked(ImportKind::Patients, $csv);

        $this->assertSame(ImportRow::DUPLICATE, $this->row($again, 2)->status);
        $this->assertStringContainsString('Same name', (string) $this->row($again, 2)->match_reason);
    }

    public function test_a_birth_year_alone_is_read_as_that_year_and_a_unicode_text_file_reads(): void
    {
        $batch = $this->checked(ImportKind::Patients, "Name,DOB\nJoseph Ssemwogerere,1968");
        $this->drive($this->service()->import($batch));
        $this->assertSame('1968-01-01', Patient::query()->where('last_name', 'Ssemwogerere')->value('dob')?->toDateString());

        $path = tempnam(sys_get_temp_dir(), 'u16');
        file_put_contents($path, "\xFF\xFE".mb_convert_encoding("Name\tPhone\nAmélie Nakato\t0772123456\n", 'UTF-16LE', 'UTF-8'));
        $this->assertSame(['Amélie Nakato', '0772123456'], SpreadsheetReader::read($path, 'list.txt')[2]);
    }

    public function test_a_balance_brought_forward_is_owed_but_not_counted_as_billed_today(): void
    {
        $this->drive($this->service()->import($this->checked(ImportKind::Patients, "Name,Phone,Balance\nAmina Nakato,0772123456,25000")));

        $figures = app(\App\Support\Dashboard\DashboardService::class)->billedAndCollected();

        $this->assertSame('0.00', $figures['billedTotal']);
        $this->assertSame(1, Invoice::query()->outstanding()->count());
    }

    public function test_an_import_cannot_be_undone_after_a_day(): void
    {
        $batch = $this->checked(ImportKind::Patients, $this->patientsCsv());
        $batch = $this->drive($this->service()->import($batch));
        $batch->update(['finished_at' => now()->subHours(25)]);

        $this->assertStringContainsString('24 hours', (string) $this->service()->undoBlocker($batch));
        $this->expectException(\RuntimeException::class);
        $this->service()->undo($batch, $this->admin->id);
    }

    public function test_a_name_column_is_needed_before_checking(): void
    {
        $batch = $this->start(ImportKind::Patients, "Phone\n0772123456");

        $this->expectExceptionMessage('Choose the column for "Full name" or "First name"');
        $this->service()->map($batch, $batch->column_map ?? []);
    }

    // ── Price list, stock, appointments ──────────────────────────────────

    public function test_a_price_list_is_added_and_updated_and_undo_keeps_what_was_charged(): void
    {
        $existing = Service::query()->create(['name' => 'Scaling and polishing', 'price' => '40000', 'is_active' => true]);
        $batch = $this->checked(ImportKind::Services, "Procedure,Fee,Category\nTooth extraction,\"50,000\",Dental\nFilling,USh 80000,Dental\nscaling and polishing,45000,Dental\nCrown,abc,Dental");

        $this->assertSame(ImportRow::DUPLICATE, $this->row($batch, 4)->status);
        $this->assertSame(ImportRow::PROBLEM, $this->row($batch, 5)->status);
        $this->service()->decide($batch, $this->row($batch, 4)->id, 'update');
        $batch = $this->drive($this->service()->import($batch));

        $this->assertSame('50000.00', (string) Service::query()->where('name', 'Tooth extraction')->value('price'));
        $this->assertSame('80000.00', (string) Service::query()->where('name', 'Filling')->value('price'));
        $this->assertSame('45000.00', (string) $existing->fresh()->price);

        // The filling is charged on a visit before the undo: it stays.
        $filling = Service::query()->where('name', 'Filling')->firstOrFail();
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id]);
        app(\App\Services\BillingService::class)->orderService($visit, $filling->id, 1, $this->admin->id);

        $this->drive($this->service()->undo($batch, $this->admin->id));

        $this->assertSame(0, Service::query()->where('name', 'Tooth extraction')->count());
        $this->assertSame(1, Service::query()->where('name', 'Filling')->count());
        $this->assertSame('45000.00', (string) $existing->fresh()->price, 'updated records keep their changes');
    }

    public function test_stock_comes_in_as_opening_stock_and_an_undo_takes_exactly_that_back_out(): void
    {
        if (! in_array(ImportKind::Stock, $this->service()->kinds(), true)) {
            $this->markTestSkipped('This practice keeps no stock.');
        }

        $batch = $this->checked(ImportKind::Stock, "Item,Qty,Unit,Cost,Selling price,Expiry\nLidocaine 2% cartridge,200,cartridge,1500,3000,31/12/2027\nGauze,0,pack,500,1000,");
        $batch = $this->drive($this->service()->import($batch));

        $item = StockItem::query()->where('name', 'Lidocaine 2% cartridge')->firstOrFail();
        $this->assertSame('200.00', (string) $item->current_quantity);
        $this->assertSame('300000.00', (string) $item->current_stock_value);
        $this->assertSame('2027-12-31', $item->expiry_date?->toDateString());
        $this->assertSame(StockMovementReason::OpeningStock, $item->movements()->sole()->reason, 'opening stock, not a delivery owed to a supplier');

        $this->drive($this->service()->undo($batch, $this->admin->id));

        $this->assertSoftDeleted($item);
        $reasons = \App\Models\StockMovement::query()->where('stock_item_id', $item->id)->pluck('reason')->map->value->all();
        $this->assertSame(['opening_stock', 'opening_undone'], $reasons);
    }

    public function test_appointments_are_booked_for_registered_patients_without_texting_them(): void
    {
        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Ruth Namata', 'is_active' => true]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'old_file_no' => 'PT 1043']);
        $date = now()->addDays(5)->format('d/m/Y');
        $past = now()->subDays(5)->format('d/m/Y');

        $batch = $this->checked(ImportKind::Appointments, "File No,Date,Time,Doctor,Reason\nPT 1043,{$date},2:30 pm,Ruth Namata,Review\nPT 9999,{$date},10:00,Ruth Namata,\nPT 1043,{$past},10:00,Ruth Namata,\nPT 1043,{$date},11:00,Dr Nobody,");

        $this->assertSame(ImportRow::READY, $this->row($batch, 2)->status);
        $this->assertStringContainsString('No registered patient has the number "PT 9999"', $this->row($batch, 3)->problems[0]);
        $this->assertStringContainsString('has passed', $this->row($batch, 4)->problems[0]);
        $this->assertStringContainsString('No staff member here is called', $this->row($batch, 5)->problems[0]);

        $this->drive($this->service()->import($batch));

        $appointment = Appointment::query()->sole();
        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertSame($doctor->id, $appointment->doctor_user_id);
        $this->assertSame('14:30', $appointment->scheduled_at->format('H:i'));
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame(0, \App\Models\PatientMessage::query()->count(), 'the patient already knows');

        // The same sheet again: the booking is recognised.
        $again = $this->checked(ImportKind::Appointments, "File No,Date,Time,Doctor\nPT 1043,{$date},14:30,Ruth Namata");
        $this->assertSame(ImportRow::DUPLICATE, $this->row($again, 2)->status);
    }

    // ── Screens ──────────────────────────────────────────────────────────

    public function test_the_whole_flow_runs_on_the_screens(): void
    {
        $file = UploadedFile::fake()->createWithContent('register.csv', "Name,Tel,File No\nAmina Nakato,0772123456,PT 1043\n");

        $component = Livewire::test(Index::class)->set('kind', 'patients')->set('file', $file)->call('upload')->assertHasNoErrors();
        $batch = ImportBatch::query()->sole();
        $component->assertRedirect(route('admin.imports.show', $batch));

        $show = Livewire::test(Show::class, ['batch' => $batch])
            ->assertSee('Match your columns')
            ->assertSee('Amina Nakato')
            ->call('saveMap');
        for ($i = 0; $i < 5; $i++) {
            $show->call('step');
        }
        $show->assertSee('Will be added')->call('startImport');
        for ($i = 0; $i < 5; $i++) {
            $show->call('step');
        }
        $show->assertSee('Imported')->assertSee('Open Amina Nakato');

        $this->assertSame(1, Patient::query()->where('old_file_no', 'PT 1043')->count());

        $this->get(route('admin.imports.index'))->assertOk()->assertSee('register.csv')->assertSee('Import data');
        $this->get(route('admin.imports.show', $batch))->assertOk();
    }

    public function test_an_excel_workbook_uploads_and_its_columns_are_guessed(): void
    {
        $bytes = XlsxWriter::make([['name' => 'Register', 'rows' => [['Names', 'Tel', 'File No'], ['Amina Nakato', '0772123456', 'PT 1043']]]]);
        $file = UploadedFile::fake()->createWithContent('register.xlsx', $bytes);

        Livewire::test(Index::class)->set('kind', 'patients')->set('file', $file)->call('upload')->assertHasNoErrors();

        $batch = ImportBatch::query()->sole();
        $this->assertSame(['Names', 'Tel', 'File No'], $batch->headings);
        $this->assertSame(0, $batch->column_map['full_name']);
        $this->assertSame(1, $batch->column_map['phone']);
        $this->assertSame(2, $batch->column_map['old_file_no']);
    }

    public function test_an_unreadable_file_says_why_on_the_page(): void
    {
        $file = UploadedFile::fake()->createWithContent('old.xls', 'binary');

        $component = Livewire::test(Index::class)->set('kind', 'patients')->set('file', $file)->call('upload')
            ->assertHasErrors('file');
        $this->assertStringContainsString('Excel Workbook (.xlsx)', (string) $component->errors()->first('file'));
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_a_draft_can_be_thrown_away_and_nothing_was_saved(): void
    {
        $batch = $this->checked(ImportKind::Patients, $this->patientsCsv());

        Livewire::test(Show::class, ['batch' => $batch])->call('cancel')->assertRedirect(route('admin.imports.index'));

        $this->assertSame(ImportStatus::Cancelled, $batch->fresh()->status);
        $this->assertSame(0, $batch->rows()->count());
        $this->assertSame(0, Patient::query()->count());
    }

    // ── Who may, and whose ───────────────────────────────────────────────

    public function test_only_the_hospital_admin_may_import(): void
    {
        $batch = $this->start(ImportKind::Patients, "Name\nAmina Nakato");

        foreach (['doctor', 'receptionist', 'accountant', 'nurse', 'records_officer'] as $role) {
            $this->actingAsRole($role, $this->hospital);
            $this->get(route('admin.imports.index'))->assertForbidden();
            $this->get(route('admin.imports.show', $batch))->assertForbidden();
        }
    }

    public function test_another_hospital_cannot_see_or_match_against_this_ones_records(): void
    {
        $batch = $this->start(ImportKind::Patients, "Name\nAmina Nakato");
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Sarah', 'last_name' => 'Atim', 'phone_1' => '0782555666', 'old_file_no' => 'PT 7']);

        $other = $this->hospital();
        $this->actingAsRole('hospital_admin', $other);

        $this->get(route('admin.imports.show', $batch))->assertNotFound();
        $this->get(route('admin.imports.index'))->assertOk()->assertDontSee('sheet.csv');

        $theirs = $this->checked(ImportKind::Patients, "Name,Phone,File No\nSarah Atim,0782555666,PT 7");
        $this->assertSame(ImportRow::READY, $this->row($theirs, 2)->status, 'another hospital\'s patient is never a match');
        $this->assertSame($other->id, $theirs->hospital_id);
        $this->assertSame([$other->id], $theirs->rows()->pluck('hospital_id')->unique()->values()->all());
    }

    public function test_a_platform_account_with_no_hospital_open_is_refused(): void
    {
        $this->actingAsSuperAdmin();
        app(CurrentHospital::class)->set(null);

        $this->get(route('admin.imports.index'))->assertForbidden();
    }

    public function test_the_demo_shows_one_finished_import_in_the_log_and_runs_twice_safely(): void
    {
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Ruth', 'last_name' => 'Nambi', 'phone_1' => '0700 456 789']);

        \Database\Seeders\DemoScenarios\Imports::run($this->hospital);
        \Database\Seeders\DemoScenarios\Imports::run($this->hospital);

        $batch = ImportBatch::query()->sole();
        $this->assertSame(ImportStatus::Done, $batch->status);
        $this->assertSame(4, $batch->added_count);
        $this->assertSame(2, $batch->skipped_count, 'one unreadable date, one patient already registered');
        $this->assertTrue($batch->finished_at->lt(now()->subDays(10)));
        $this->assertNotNull($this->service()->undoBlocker($batch), 'too old to undo');

        $joseph = Patient::query()->where('old_file_no', 'OLD 0412')->firstOrFail();
        $this->assertSame(58, $joseph->age());
        $this->assertSame('45000.00', (string) Invoice::query()->where('patient_id', $joseph->id)->value('balance'));
        $this->assertSame(7, Patient::query()->where('old_file_no', 'OLD 0414')->firstOrFail()->age());

        $this->get(route('admin.imports.index'))->assertOk()->assertSee('old-patient-register.csv');
    }

    public function test_the_writer_keeps_text_exactly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'w');
        file_put_contents($path, XlsxWriter::make([['name' => 'A', 'rows' => [['Phone', 'Note'], ['0772 123456', 'Fish & chips <ok>']]]]));

        $this->assertSame(['0772 123456', 'Fish & chips <ok>'], SpreadsheetReader::read($path, 'a.xlsx')[2]);
    }
}
