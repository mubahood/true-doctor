<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Livewire\Settings\VisitFormSettings;
use App\Livewire\Ui\SelectSearch;
use App\Livewire\Visits\Index as VisitsIndex;
use App\Livewire\Visits\Panels\Dosage;
use App\Models\Appointment;
use App\Models\AuditEntry;
use App\Models\DoseItem;
use App\Models\DoseItemRecord;
use App\Models\Hospital;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\DentalNotesService;
use App\Services\DispensationService;
use App\Services\PrescriptionService;
use App\Services\VisitReportService;
use App\Support\CurrentHospital;
use App\Support\Practice\VisitForm;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A dental clinic's request (docs/pharmacy.md, "Dosage"; docs/documents.md,
 * "The patient summary"): a dosage section on the visit built on the
 * prescription, and a printed visit report that gives the patient the
 * treatment, dosage, bill and appointments and none of the examinations.
 * A general hospital keeps its prescriptions panel and its full report.
 */
class VisitDosageTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $dentist;

    private Patient $patient;

    private StockItem $amoxicillin;

    private StockItem $paracetamol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        $this->hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->dentist = $this->staff('hospital_admin');
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Amina', 'last_name' => 'Nakato']);
        $this->amoxicillin = StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Amoxicillin 500mg', 'current_quantity' => '100', 'sale_price' => '500.00', 'is_active' => true]);
        $this->paracetamol = StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Paracetamol 500mg', 'current_quantity' => '0', 'sale_price' => '100.00', 'is_active' => true]);
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $user = User::factory()->create(['hospital_id' => ($hospital ?? $this->hospital)->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function visit(?Hospital $hospital = null, ?Patient $patient = null): Visit
    {
        return Visit::factory()->create([
            'hospital_id' => ($hospital ?? $this->hospital)->id,
            'patient_id' => ($patient ?? $this->patient)->id,
            'doctor_user_id' => $this->dentist->id,
            'reason' => null, 'complaints' => null, 'diagnosis' => null,
        ]);
    }

    /** @return list<array{stock_item_id:int,dose:string,frequency:string,days:int,note:string|null}> */
    private function rows(): array
    {
        return [
            ['stock_item_id' => $this->amoxicillin->id, 'dose' => '1 capsule', 'frequency' => 'Three times a day', 'days' => 5, 'note' => 'After meals'],
            ['stock_item_id' => $this->paracetamol->id, 'dose' => '2 tablets', 'frequency' => 'When needed', 'days' => 3, 'note' => null],
        ];
    }

    private function general(): Hospital
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        app(CurrentHospital::class)->set($general->id);
        app()->forgetInstance(\App\Support\HospitalSettings::class);

        return $general;
    }

    // ── The setting ────────────────────────────────────────────────────

    public function test_a_dental_clinic_writes_a_dosage_and_prints_a_summary_and_a_general_hospital_is_as_it_was(): void
    {
        $form = VisitForm::of($this->hospital);
        $this->assertTrue($form->writesDosage());
        $this->assertSame(VisitForm::SUMMARY, $form->visitReport);
        $this->assertTrue($form->printsSummary());

        $general = VisitForm::of(Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]));
        $this->assertFalse($general->writesDosage());
        $this->assertSame(VisitForm::FULL, $general->visitReport);
    }

    public function test_settings_choose_what_the_visit_report_shows(): void
    {
        $this->actingAs($this->dentist);
        Livewire::test(VisitFormSettings::class)
            ->assertSee('Visit report shows')->assertSee('Patient summary')->assertSee('Full clinical record')
            ->set('visitReport', VisitForm::FULL)->call('save')->assertHasNoErrors();
        $this->assertSame(VisitForm::FULL, $this->hospital->fresh()->settings['clinical']['visit_report']);
        $this->assertFalse(VisitForm::of($this->hospital->fresh())->printsSummary());

        // The practice's own default is stored as "follow the practice".
        Livewire::test(VisitFormSettings::class)->set('visitReport', VisitForm::SUMMARY)->call('save');
        $this->assertNull($this->hospital->fresh()->settings['clinical']['visit_report']);

        // The app reads and changes it too.
        Sanctum::actingAs($this->dentist);
        $this->getJson('/api/v1/admin/settings/practice')->assertOk()->assertJsonPath('data.visit_form.visit_report', 'summary')
            ->assertJsonPath('data.visit_form.dosage', true)
            ->assertJsonPath('data.visit_form.catalogue.visit_report.full', 'Full clinical record');
        $this->putJson('/api/v1/admin/settings/practice/visit-form', ['visit_report' => 'full'])->assertOk()
            ->assertJsonPath('data.visit_form.visit_report', 'full');
    }

    // ── The visit page ─────────────────────────────────────────────────

    public function test_the_visit_page_shows_dosage_in_a_dental_clinic_and_prescriptions_elsewhere(): void
    {
        $this->actingAs($this->dentist)->get(route('admin.visits.show', $this->visit()))->assertOk()
            ->assertSeeLivewire(Dosage::class)->assertDontSeeLivewire(\App\Livewire\Visits\Panels\Prescriptions::class);

        $general = $this->general();
        $admin = $this->staff('hospital_admin', $general);
        $visit = Visit::factory()->create(['hospital_id' => $general->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $general->id])->id]);
        $this->actingAs($admin)->get(route('admin.visits.show', $visit))->assertOk()
            ->assertSeeLivewire(\App\Livewire\Visits\Panels\Prescriptions::class)->assertDontSeeLivewire(Dosage::class);
    }

    public function test_the_dentist_adds_rows_of_medicine_dose_how_often_and_days_and_nothing_is_billed(): void
    {
        $visit = $this->visit();
        $this->actingAs($this->dentist);

        $panel = Livewire::test(Dosage::class, ['visitId' => $visit->id])
            ->assertSee('No medicine written on this visit')
            ->call('openForm')->call('addDoseRow')->call('addDoseRow');
        $uids = array_column($panel->get('dosage'), 'uid');
        $this->assertCount(3, $uids);

        $panel->call('picked', 'dose-'.$uids[0], $this->amoxicillin->id)
            ->set('dosage.0.dose', '1 capsule')->set('dosage.0.frequency', 'Three times a day')->set('dosage.0.days', 5)->set('dosage.0.note', 'After meals')
            ->call('picked', 'dose-'.$uids[1], $this->paracetamol->id)
            ->set('dosage.1.dose', '2 tablets')->set('dosage.1.frequency', 'other')->set('dosage.1.frequency_other', 'Every 6 hours')->set('dosage.1.days', 3)
            ->call('removeDoseRow', 2)
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false)
            ->assertSee('Amoxicillin 500mg')->assertSee('Three times a day')->assertSee('Every 6 hours')->assertSee('After meals');

        $doses = DoseItem::query()->orderBy('id')->get();
        $this->assertCount(2, $doses);
        $this->assertSame($this->amoxicillin->id, (int) $doses[0]->stock_item_id);
        $this->assertSame('Amoxicillin 500mg', $doses[0]->drug_name);
        $this->assertSame('1 capsule', $doses[0]->dosage);
        $this->assertSame('Three times a day', $doses[0]->frequency);
        $this->assertSame(['morning', 'afternoon', 'night'], $doses[0]->slots);
        $this->assertSame(5, $doses[0]->days);
        $this->assertSame('After meals', $doses[0]->instructions);
        $this->assertSame('Every 6 hours', $doses[1]->frequency);
        $this->assertSame([], $doses[1]->slots);
        $this->assertNotNull($doses[0]->uuid);
        $this->assertSame(1, Prescription::query()->where('visit_id', $visit->id)->count());

        // Taken at home: no ward round, and never billed here.
        $this->assertSame(0, DoseItemRecord::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        // Audited.
        $this->assertTrue(AuditEntry::query()->where('subject_type', DoseItem::class)->exists());
    }

    public function test_a_row_needs_a_medicine_from_stock_a_dose_how_often_and_days(): void
    {
        $visit = $this->visit();
        $this->actingAs($this->dentist);

        Livewire::test(Dosage::class, ['visitId' => $visit->id])->call('openForm')
            ->set('dosage.0.days', '')->call('save')
            ->assertHasErrors(['dosage.0.stock_item_id', 'dosage.0.dose', 'dosage.0.frequency', 'dosage.0.days']);

        // "Other" needs its words.
        $panel = Livewire::test(Dosage::class, ['visitId' => $visit->id])->call('openForm');
        $uid = $panel->get('dosage')[0]['uid'];
        $panel->call('picked', 'dose-'.$uid, $this->amoxicillin->id)->set('dosage.0.dose', '1')->set('dosage.0.frequency', 'other')
            ->call('save')->assertHasErrors(['dosage.0.frequency']);

        // A medicine of another hospital is not in this clinic's stock.
        $other = Hospital::factory()->create();
        $foreign = StockItem::factory()->create(['hospital_id' => $other->id, 'name' => 'Foreign drug']);
        $panel->call('picked', 'dose-'.$uid, $foreign->id)->set('dosage.0.frequency', 'Once a day')
            ->call('save')->assertHasErrors(['dosage.0.stock_item_id']);
        $this->assertSame(0, DoseItem::query()->count());
    }

    public function test_a_nurse_reads_the_dosage_but_cannot_write_it(): void
    {
        $visit = $this->visit();
        app(PrescriptionService::class)->writeDosage($visit, $this->rows(), $this->dentist->id);

        $this->actingAs($this->staff('nurse'));
        Livewire::test(Dosage::class, ['visitId' => $visit->id])->assertSee('Amoxicillin 500mg')
            ->assertDontSeeHtml('wire:click="openForm"')->call('openForm')->assertForbidden();
    }

    public function test_a_row_is_removed_until_the_pharmacy_dispenses_it(): void
    {
        $visit = $this->visit();
        app(PrescriptionService::class)->writeDosage($visit, $this->rows(), $this->dentist->id);
        $this->actingAs($this->dentist);
        $first = DoseItem::query()->orderBy('id')->firstOrFail();

        Livewire::test(Dosage::class, ['visitId' => $visit->id])->call('remove', $first->id)
            ->assertDontSee('Amoxicillin 500mg')->assertSee('Paracetamol 500mg');
        $this->assertSame(1, DoseItem::query()->count());

        // Dispensing follows the prescription flow: it bills, and the line then stays.
        $prescription = Prescription::query()->firstOrFail();
        app(DispensationService::class)->dispense($visit, [['stock_item_id' => $this->amoxicillin->id, 'quantity' => '15']], null, $this->dentist->id, $prescription->id);
        $this->assertSame(1, OrderItem::query()->count());

        $last = DoseItem::query()->firstOrFail();
        Livewire::test(Dosage::class, ['visitId' => $visit->id])->call('remove', $last->id)->assertDispatched('toast');
        $this->assertSame(1, DoseItem::query()->count());

        // The last row removed takes its empty prescription with it.
        $fresh = $this->visit();
        app(PrescriptionService::class)->writeDosage($fresh, [$this->rows()[0]], $this->dentist->id);
        Livewire::test(Dosage::class, ['visitId' => $fresh->id])->call('remove', DoseItem::query()->latest('id')->firstOrFail()->id);
        $this->assertSame(0, Prescription::query()->where('visit_id', $fresh->id)->count());
    }

    public function test_the_medicine_picker_offers_every_active_item_even_out_of_stock(): void
    {
        $this->actingAs($this->dentist);
        Livewire::test(SelectSearch::class, ['resource' => 'medicines', 'name' => 'dose-x'])
            ->set('query', 'Para')->assertSee('Paracetamol 500mg')->assertSee('Out of stock');
        Livewire::test(SelectSearch::class, ['resource' => 'stock-items', 'name' => 'x'])
            ->set('query', 'Para')->assertDontSee('Paracetamol 500mg');
    }

    // ── The new visit form ─────────────────────────────────────────────

    public function test_the_new_visit_form_takes_an_optional_dosage_after_the_oral_examination(): void
    {
        $this->actingAs($this->dentist);

        $form = Livewire::test(VisitsIndex::class)->call('create')
            ->assertSeeInOrder(['Oral examination', 'Dosage', 'Visit status'])
            ->call('picked', 'patient_id', $this->patient->id)
            ->set('complaints', 'Toothache')->call('addDoseRow');
        $uid = $form->get('dosage')[0]['uid'];
        $form->call('picked', 'dose-'.$uid, $this->amoxicillin->id)
            ->assertSet('patient_id', $this->patient->id)
            ->set('dosage.0.dose', '1 capsule')->set('dosage.0.frequency', 'Twice a day')->set('dosage.0.days', 5)
            ->call('save')->assertHasNoErrors();

        $visit = Visit::query()->latest('id')->firstOrFail();
        $dose = DoseItem::query()->firstOrFail();
        $this->assertSame($visit->id, $dose->prescription->visit_id);
        $this->assertSame('Twice a day', $dose->frequency);

        // Skipped, or a row left empty: no prescription.
        $other = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        Livewire::test(VisitsIndex::class)->call('create')->call('picked', 'patient_id', $other->id)
            ->set('complaints', 'Check-up')->call('addDoseRow')->call('save')->assertHasNoErrors();
        $this->assertSame(1, Prescription::query()->count());

        // Half a row is checked before the visit opens.
        $third = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        Livewire::test(VisitsIndex::class)->call('create')->call('picked', 'patient_id', $third->id)
            ->set('complaints', 'Pain')->call('addDoseRow')->set('dosage.0.dose', '1 tablet')
            ->call('save')->assertHasErrors(['dosage.0.stock_item_id', 'dosage.0.frequency']);
        $this->assertSame(0, Visit::query()->where('patient_id', $third->id)->count());
    }

    public function test_the_desk_and_a_general_hospital_see_no_dosage_on_the_new_visit_form(): void
    {
        $this->actingAs($this->staff('receptionist'));
        Livewire::test(VisitsIndex::class)->call('create')->assertDontSee('Add a medicine');

        $general = $this->general();
        $this->actingAs($this->staff('hospital_admin', $general));
        Livewire::test(VisitsIndex::class)->call('create')->assertDontSee('Add a medicine');
    }

    // ── The printed report ─────────────────────────────────────────────

    public function test_the_dental_report_is_a_patient_summary_without_examinations(): void
    {
        $visit = $this->visit();
        app(DentalNotesService::class)->save($visit, [
            'medical_history' => 'Hypertension, on amlodipine', 'hard_tissues' => 'Deep caries 36 DO', 'diagnosis' => 'Irreversible pulpitis 36',
        ], $this->dentist);
        $visit->forceFill(['doctor_remarks' => 'Internal remark for the team', 'blood_pressure' => '130/85'])->save();
        app(PrescriptionService::class)->writeDosage($visit, $this->rows(), $this->dentist->id);

        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Extraction', 'price' => '50000.00']);
        app(BillingService::class)->orderService($visit, $service->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->dentist->id);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '20000.00', [], $this->dentist->id);

        Appointment::factory()->create([
            'hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id, 'doctor_user_id' => $this->dentist->id,
            'scheduled_at' => now()->addDays(7)->setTime(10, 30), 'ends_at' => now()->addDays(7)->setTime(11, 0), 'reason' => 'Review the socket',
        ]);
        Appointment::factory()->create([
            'hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id, 'doctor_user_id' => $this->dentist->id,
            'scheduled_at' => now()->subDays(7), 'ends_at' => now()->subDays(7)->addMinutes(30), 'reason' => 'Old booking',
        ]);

        $summary = app(VisitReportService::class)->summary($visit->fresh());
        $html = view('pdf.visit-summary', ['summary' => $summary, 'hospital' => $this->hospital])->render();

        foreach (['Visit summary', 'Amina Nakato', $this->patient->patient_no, $this->dentist->name, 'Treatment done', 'Extraction',
            'Dosage', 'Amoxicillin 500mg', '1 capsule', 'Three times a day', 'After meals', 'When needed',
            'Bill', 'Payments', 'Cash', 'Balance', 'Next appointments', 'Review the socket', '10:30'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['amlodipine', 'Hypertension', 'Deep caries', 'pulpitis', 'Internal remark', 'Oral examination', 'History', 'Diagnosis', '130/85', 'Old booking', 'Dental chart'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
        $this->assertSame('30000.00', $summary['totals']['balance']);

        $this->actingAs($this->dentist)->get(route('admin.visits.report', $visit))->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_general_hospital_keeps_the_full_report(): void
    {
        $general = $this->general();
        $admin = $this->staff('hospital_admin', $general);
        $visit = Visit::factory()->create([
            'hospital_id' => $general->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $general->id])->id,
            'diagnosis' => 'Malaria', 'reason' => 'Fever',
        ]);

        $this->assertFalse(VisitForm::of($general)->printsSummary());
        $this->actingAs($admin)->get(route('admin.visits.report', $visit))->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=visit-report-'.$visit->visit_no.'.pdf');
    }

    // ── The app ────────────────────────────────────────────────────────

    public function test_the_app_reads_writes_and_removes_the_dosage(): void
    {
        $visit = $this->visit();
        Sanctum::actingAs($this->dentist);

        $this->getJson("/api/v1/visits/{$visit->uuid}/dosage")->assertOk()
            ->assertJsonPath('data.items', [])->assertJsonPath('data.frequencies.1', 'Twice a day');

        $body = ['items' => $this->rows()];
        $first = $this->postJson("/api/v1/visits/{$visit->uuid}/dosage", $body, ['Idempotency-Key' => 'dose-1'])->assertStatus(201)
            ->assertJsonPath('data.items.0.medicine', 'Amoxicillin 500mg')
            ->assertJsonPath('data.items.0.frequency', 'Three times a day')
            ->assertJsonPath('data.items.1.note', null);
        // A resend after a lost reply writes nothing twice.
        $this->postJson("/api/v1/visits/{$visit->uuid}/dosage", $body, ['Idempotency-Key' => 'dose-1'])->assertStatus(201);
        $this->assertSame(2, DoseItem::query()->count());

        $this->postJson("/api/v1/visits/{$visit->uuid}/dosage", ['items' => [['stock_item_id' => $this->amoxicillin->id]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.dose', 'items.0.frequency', 'items.0.days'], 'errors');

        $uuid = $first->json('data.items.0.uuid');
        $this->deleteJson("/api/v1/visits/{$visit->uuid}/dosage/{$uuid}")->assertOk()->assertJsonCount(1, 'data.items');

        // Another hospital's visit is not found.
        $other = Hospital::factory()->create();
        $foreign = Visit::factory()->create(['hospital_id' => $other->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $other->id])->id]);
        $this->getJson("/api/v1/visits/{$foreign->uuid}/dosage")->assertNotFound();

        // A nurse reads, never writes.
        Sanctum::actingAs($this->staff('nurse'));
        $this->getJson("/api/v1/visits/{$visit->uuid}/dosage")->assertOk();
        $this->postJson("/api/v1/visits/{$visit->uuid}/dosage", $body)->assertForbidden();
    }
}
