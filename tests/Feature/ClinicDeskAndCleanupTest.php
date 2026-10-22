<?php

namespace Tests\Feature;

use App\Enums\ImportKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\VisitOutcome;
use App\Enums\VisitStatus;
use App\Livewire\Imports\Index as ImportsIndex;
use App\Livewire\Settings\TestRecords;
use App\Livewire\Stock\Index as StockIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Visits\DeleteVisit;
use App\Livewire\Visits\Index as VisitsIndex;
use App\Models\AuditEntry;
use App\Models\DentalChartEntry;
use App\Models\Hospital;
use App\Models\ImportRow;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\DataImportService;
use App\Services\OrderService;
use App\Services\PatientService;
use App\Services\StockService;
use App\Services\TestRecordsService;
use App\Services\VisitService;
use App\Support\BirthDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A dental clinic's requests, October 2026: the age in place of the date of
 * birth, deleting a visit, importing past visits, optional stock prices, and
 * removing test records (docs/decisions.md).
 */
class ClinicDeskAndCleanupTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital(['name' => 'Smile Dental']);
        $this->admin = $this->actingAsRole('hospital_admin', $this->hospital);
    }

    private function patient(array $attributes = []): Patient
    {
        return app(PatientService::class)->register(array_merge(['first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0772123456'], $attributes), $this->admin->id);
    }

    private function item(string $quantity = '20'): StockItem
    {
        $item = StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Lidocaine', 'cost_price' => '1000', 'sale_price' => '2000']);
        app(StockService::class)->receive($item, $quantity, '1000', $this->admin->id);

        return $item->fresh();
    }

    /** A visit with a product given, a chart entry and an unpaid invoice. */
    private function workedVisit(Patient $patient, StockItem $item): Visit
    {
        $visit = app(VisitService::class)->open(['patient_id' => $patient->id, 'reason' => 'Toothache'], $this->admin->id);
        $order = app(OrderService::class)->place($visit, OrderType::Procedure, 'Extraction', [], $this->admin->id);
        app(OrderService::class)->addProductItem($order, $item, '2', $this->admin->id, true);
        DentalChartEntry::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'patient_id' => $patient->id, 'visit_id' => $visit->id,
            'tooth' => 36, 'finding' => 'caries', 'recorded_by' => $this->admin->id, 'recorded_at' => now()]);
        app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);

        return $visit->fresh();
    }

    // ── 1. The age in place of the date of birth ─────────────────────────

    public function test_an_age_becomes_1_july_of_the_year_that_makes_it_right_today(): void
    {
        $this->assertSame('1995-07-01', BirthDate::fromAge(30, null, Carbon::parse('2026-03-01')));
        $this->assertSame('1996-07-01', BirthDate::fromAge(30, null, Carbon::parse('2026-10-22')));
        $this->assertSame('2025-07-01', BirthDate::fromAge(0, null, Carbon::parse('2026-03-01')));
        // Months given: that far back exactly.
        $this->assertSame('2025-04-22', BirthDate::fromAge(1, 6, Carbon::parse('2026-10-22')));
        $this->assertNull(BirthDate::fromAge(null, null));
    }

    public function test_registering_with_an_age_stores_an_estimated_date_and_editing_keeps_it(): void
    {
        $patient = $this->patient(['age_years' => '34']);
        $this->assertTrue($patient->dob_estimated);
        $this->assertSame(34, $patient->age());

        // Saving the same age again does not move the estimate.
        $before = $patient->dob->toDateString();
        $patient = app(PatientService::class)->update($patient, ['age_years' => '34', 'phone_1' => '0700111222']);
        $this->assertSame($before, $patient->dob->toDateString());

        // A real date replaces it and is exact.
        $patient = app(PatientService::class)->update($patient, ['dob' => '1992-02-14']);
        $this->assertFalse($patient->dob_estimated);
        $this->assertSame('1992-02-14', $patient->dob->toDateString());
    }

    public function test_the_patient_form_takes_an_age_and_switches_to_the_date(): void
    {
        Livewire::test(\App\Livewire\Patients\Index::class)
            ->call('create')
            ->assertSet('dob_mode', 'age')
            ->set('first_name', 'Baby')->set('last_name', 'Okello')
            ->set('age_years', '1')->set('age_months', '3')
            ->call('save')
            ->assertHasNoErrors();

        $baby = Patient::query()->where('first_name', 'Baby')->sole();
        $this->assertTrue($baby->dob_estimated);
        $this->assertSame(today()->subYear()->subMonthsNoOverflow(3)->toDateString(), $baby->dob->toDateString());

        Livewire::test(\App\Livewire\Patients\Index::class)
            ->call('create')
            ->set('first_name', 'Grace')->set('last_name', 'Atim')
            ->set('dob_mode', 'date')->set('dob', '1988-05-09')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertFalse(Patient::query()->where('first_name', 'Grace')->sole()->dob_estimated);
    }

    public function test_the_new_visit_form_and_the_api_take_an_age(): void
    {
        Livewire::test(VisitsIndex::class)
            ->call('create')
            ->call('setTab', 'intake')
            ->set('first_name', 'Peter')->set('last_name', 'Mugisha')
            ->set('age_years', '40')
            ->call('save')
            ->assertHasNoErrors();
        $peter = Patient::query()->where('first_name', 'Peter')->sole();
        $this->assertTrue($peter->dob_estimated);
        $this->assertSame(40, $peter->age());

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/patients', ['first_name' => 'Ruth', 'last_name' => 'Akello', 'age_years' => 25])
            ->assertCreated()
            ->assertJsonPath('data.dob_estimated', true)
            ->assertJsonPath('data.age', 25);
        $this->postJson('/api/v1/visits/intake', ['first_name' => 'Sam', 'last_name' => 'Opio', 'age_years' => 1, 'age_months' => 2])
            ->assertSuccessful();
        $this->assertTrue(Patient::query()->where('first_name', 'Sam')->sole()->dob_estimated);
    }

    // ── 2. Deleting a visit ─────────────────────────────────────────────

    public function test_deleting_a_visit_reverses_its_work_and_hides_it(): void
    {
        $item = $this->item();
        $patient = $this->patient();
        $visit = $this->workedVisit($patient, $item);
        $this->assertSame('18.00', (string) $item->fresh()->current_quantity);
        $invoice = $visit->invoices()->sole();

        Livewire::test(DeleteVisit::class)
            ->call('open', $visit->id)
            ->assertSet('show', true)
            ->set('reason', 'Opened for the wrong patient')
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertNull(Visit::query()->find($visit->id));
        $gone = Visit::withTrashed()->find($visit->id);
        $this->assertSame('Opened for the wrong patient', $gone->delete_reason);
        $this->assertSame($this->admin->id, (int) $gone->deleted_by);
        $this->assertSame('20.00', (string) $item->fresh()->current_quantity);
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->assertNotNull(JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->where('source_event', 'issued')->value('reversed_by_id'));
        $this->assertSame(OrderStatus::Cancelled, $visit->orders()->sole()->status);
        $this->assertSame(0, DentalChartEntry::query()->where('visit_id', $visit->id)->count());
        $this->assertTrue(AuditEntry::query()->where('subject_type', $visit->getMorphClass())->where('subject_id', $visit->id)->where('event', 'deleted')->exists());
    }

    public function test_a_visit_with_money_paid_is_refused_with_the_reason(): void
    {
        $patient = $this->patient();
        $visit = $this->workedVisit($patient, $this->item());
        app(BillingService::class)->recordPayment($visit->invoices()->sole(), PaymentMethod::Cash, '1000', [], $this->admin->id);

        try {
            app(VisitService::class)->delete($visit, $this->admin->id, 'Mistake');
            $this->fail('A paid visit was deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refund it first', $e->getMessage());
        }
        $this->assertNotNull(Visit::query()->find($visit->id));

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/visits/'.$visit->uuid.'/delete', ['reason' => 'Mistake'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_only_who_may_delete_visits_can(): void
    {
        $patient = $this->patient();
        $visit = app(VisitService::class)->open(['patient_id' => $patient->id, 'reason' => 'Check'], $this->admin->id);

        $this->actingAsRole('receptionist', $this->hospital);
        Livewire::test(DeleteVisit::class)->call('open', $visit->id)->assertForbidden();
        Sanctum::actingAs(auth()->user());
        $this->postJson('/api/v1/visits/'.$visit->uuid.'/delete', ['reason' => 'Mistake'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/visits/'.$visit->uuid.'/delete', ['reason' => 'Duplicate'])
            ->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(Visit::query()->find($visit->id));

        // A retry after a lost reply gets the same answer, not a 404.
        $this->postJson('/api/v1/visits/'.$visit->uuid.'/delete', ['reason' => 'Duplicate'])
            ->assertOk()->assertJsonPath('data.deleted', true);
    }

    public function test_a_patient_sharing_a_prepaid_card_is_kept_by_remove_test_records(): void
    {
        $holder = $this->patient(['first_name' => 'Test']);
        $child = $this->patient(['first_name' => 'Child', 'phone_1' => '0700555444']);
        $card = \App\Models\PatientCard::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $holder->id]);
        \App\Models\CardHolder::query()->create(['patient_card_id' => $card->id, 'patient_id' => $child->id, 'relationship' => 'child', 'status' => 'active']);

        $result = app(TestRecordsService::class)->remove([$holder->id], 'Smile Dental', $this->admin);

        $this->assertSame(0, $result['removed']);
        $this->assertStringContainsString('prepaid card', (string) reset($result['refused']));
        $this->assertNotNull(Patient::query()->find($holder->id));
    }

    // ── 3. Importing past visits ─────────────────────────────────────────

    public function test_past_visits_import_closed_billed_as_opening_entries_and_undo(): void
    {
        $patient = $this->patient(['old_file_no' => 'PT 1043']);
        Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Filling', 'price' => '30000']);
        Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Scaling', 'price' => '20000']);

        $import = app(DataImportService::class);
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "Date,File no,Dentist,Complaint,Diagnosis,Treatment,Amount,Paid\n14/03/2025,PT 1043,,Toothache,Caries 36,\"Filling, Scaling\",50000,30000\n");
        $batch = $import->start(ImportKind::Visits, $path, 'visits.csv', $this->admin->id);
        $batch = $import->map($batch, $batch->column_map ?? []);
        for ($i = 0; $i < 50 && $batch->status->isWorking(); $i++) {
            $batch = $import->step($batch, $this->admin->id);
        }
        $this->assertSame(ImportRow::READY, $batch->rows()->sole()->status, json_encode($batch->rows()->sole()->problems));
        $batch = $import->import($batch);
        for ($i = 0; $i < 50 && $batch->status->isWorking(); $i++) {
            $batch = $import->step($batch, $this->admin->id);
        }

        $visit = Visit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame('2025-03-14', $visit->created_at->toDateString());
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertSame(VisitOutcome::Closed, $visit->outcome);
        $this->assertSame('Caries 36', $visit->diagnosis);
        $this->assertSame(2, $visit->orderItems()->count());

        $invoice = $visit->invoices()->sole();
        $this->assertTrue($invoice->opening_balance);
        $this->assertSame('50000.00', (string) $invoice->total);
        $this->assertSame('30000.00', (string) $invoice->paid_before);
        $this->assertSame('20000.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        // Paid in the old books: never a payment here, never today's takings.
        $this->assertSame(0, Payment::query()->count());
        $entry = JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->sole();
        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->with('account')->get();
        $this->assertSame('20000.00', (string) $lines->firstWhere('account.system_key', 'receivable')?->debit);
        $this->assertSame('20000.00', (string) $lines->firstWhere('account.system_key', 'opening_equity')?->credit);

        $batch = $import->undo($batch->refresh(), $this->admin->id);
        for ($i = 0; $i < 50 && $batch->status->isWorking(); $i++) {
            $batch = $import->step($batch, $this->admin->id);
        }
        $this->assertSame(ImportRow::UNDONE, $batch->rows()->sole()->status, (string) $batch->rows()->sole()->message);
        $this->assertNull(Visit::query()->find($visit->id));
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_the_visits_page_import_button_opens_import_data_with_visits_chosen(): void
    {
        Livewire::test(VisitsIndex::class)->assertSee(route('admin.imports.index', ['kind' => 'visits']), false);
        Livewire::withQueryParams(['kind' => 'visits'])->test(ImportsIndex::class)->assertSet('kind', 'visits');

        $this->actingAsRole('receptionist', $this->hospital);
        Livewire::test(VisitsIndex::class)->assertDontSee(route('admin.imports.index', ['kind' => 'visits']), false);
    }

    // ── 4. Stock prices are optional ─────────────────────────────────────

    public function test_a_stock_item_needs_neither_price(): void
    {
        Livewire::test(StockIndex::class)
            ->call('create')
            ->set('name', 'Cotton wool')->set('unit', 'roll')->set('reorder_level', '2')
            ->set('cost_price', '')->set('sale_price', '')
            ->call('save')
            ->assertHasNoErrors();
        $item = StockItem::query()->where('name', 'Cotton wool')->sole();
        $this->assertSame('0.00', (string) $item->cost_price);
        $this->assertSame('0.00', (string) $item->sale_price);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/stock-items', ['name' => 'Gloves', 'unit' => 'box', 'reorder_level' => 1, 'sale_price' => 500])
            ->assertCreated()
            ->assertJsonPath('data.cost_price', '0.00')
            ->assertJsonPath('data.sale_price', '500.00');
    }

    // ── 5. Removing test records ─────────────────────────────────────────

    public function test_test_records_are_removed_with_the_books_reversed_and_the_stock_back(): void
    {
        $item = $this->item();
        $test = $this->patient(['first_name' => 'Test']);
        $visit = $this->workedVisit($test, $item);
        $payment = app(BillingService::class)->recordPayment($visit->invoices()->sole(), PaymentMethod::Cash, '4000', [], $this->admin->id);
        $keep = $this->patient(['first_name' => 'Real', 'phone_1' => '0700999888']);
        $this->assertSame('18.00', (string) $item->fresh()->current_quantity);

        Livewire::test(TestRecords::class)
            ->set('search', 'Test')
            ->call('pickShown')
            ->assertSet('picked', [$test->id])
            ->call('confirm')
            ->set('typedName', 'Wrong name')
            ->call('remove')
            ->assertHasErrors('typedName')
            ->set('typedName', 'smile dental')
            ->call('remove')
            ->assertHasNoErrors();

        $this->assertNull(Patient::withTrashed()->find($test->id));
        $this->assertNull(Visit::withTrashed()->find($visit->id));
        $this->assertNull(Payment::query()->find($payment->id));
        $this->assertNotNull(Patient::query()->find($keep->id));
        $this->assertSame('20.00', (string) $item->fresh()->current_quantity);

        // Nothing deleted from the books: every entry stands, each reversed.
        $payEntry = JournalEntry::query()->where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->sole();
        $this->assertNotNull($payEntry->reversed_by_id);
        $receivable = JournalLine::query()->whereHas('account', fn ($q) => $q->where('system_key', 'receivable'))
            ->selectRaw('coalesce(sum(debit),0) - coalesce(sum(credit),0) as net')->value('net');
        $this->assertSame(0, bccomp((string) $receivable, '0', 2));
    }

    public function test_test_records_are_for_the_hospital_admin_of_a_real_hospital_only(): void
    {
        $this->actingAsRole('receptionist', $this->hospital);
        $this->get(route('admin.settings.test-records'))->assertForbidden();

        $this->actingAsSuperAdmin();
        $this->get(route('admin.settings.test-records'))->assertForbidden();

        config(['demo.enabled' => true, 'demo.hospital' => 'demo-clinic']);
        $demo = $this->hospital(['slug' => 'demo-clinic']);
        $admin = $this->actingAsRole('hospital_admin', $demo);
        $this->assertNotNull(app(TestRecordsService::class)->refusal($admin));
        $this->get(route('admin.settings.test-records'))->assertForbidden();
    }

    public function test_staff_accounts_are_deleted_on_user_accounts_but_never_the_last_admin(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $other = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin', 'is_active' => true]);
        $other->syncSpatieRole();

        Livewire::test(UsersIndex::class)->call('delete', $nurse->id);
        $this->assertNull(User::query()->find($nurse->id));

        // Signed in as the other admin, the first is deleted; then the
        // remaining one is the last admin and is kept.
        $this->actingAs($other);
        Livewire::test(UsersIndex::class)->call('delete', $this->admin->id);
        $this->assertNull(User::query()->find($this->admin->id));

        $third = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin', 'is_active' => false]);
        $third->syncSpatieRole();
        $this->actingAs($third);
        Livewire::test(UsersIndex::class)->call('delete', $other->id)->assertDispatched('toast');
        $this->assertNotNull(User::query()->find($other->id));
    }
}
