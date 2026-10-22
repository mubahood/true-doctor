<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Livewire\Visits\Actions;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\RoleAbility;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\AccessService;
use App\Services\BillingService;
use App\Services\DentalNotesService;
use App\Services\DentalService;
use App\Services\OrderService;
use App\Services\VisitReportService;
use App\Support\Access\PermissionResolver;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A dental clinic's three asks (docs/rbac.md, "What a practice starts with";
 * docs/documents.md, "The patient summary"; docs/dental.md): the desk raises
 * the bill, prints the visit report, enters stock and keeps the books; the
 * report carries the oral examination, the plan, the receipt and who prepared
 * it; the chart, the plan and the prescription come before the orders. A
 * general hospital is as it was.
 */
class DentalClinicDeskTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $clinic;

    private User $dentist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        $this->clinic = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        $this->use($this->clinic);
        $this->dentist = $this->staff('doctor');
    }

    private function use(Hospital $hospital): void
    {
        app(CurrentHospital::class)->set($hospital->id);
        app()->forgetInstance(HospitalSettings::class);
        app(PermissionResolver::class)->flush();
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $user = User::factory()->create(['hospital_id' => ($hospital ?? $this->clinic)->id, 'role' => $role, 'name' => ucfirst($role).' Person']);
        $user->syncSpatieRole();

        return $user;
    }

    private function visit(?Hospital $hospital = null): Visit
    {
        $hospital ??= $this->clinic;

        return Visit::factory()->create([
            'hospital_id' => $hospital->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $hospital->id, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0772123456'])->id,
            'doctor_user_id' => $hospital->is($this->clinic) ? $this->dentist->id : null,
            'reason' => 'Toothache',
        ]);
    }

    // ── 1. Access ──────────────────────────────────────────────────────

    public function test_a_dental_clinic_starts_its_desk_its_dentist_and_its_assistant_with_what_they_asked_for(): void
    {
        $desk = $this->staff('receptionist');
        foreach (['patients.create', 'visits.create', 'billing.manage', 'billing.view', 'visits.report', 'pharmacy.manage', 'pharmacy.view',
            'reports.view', 'finance.view', 'finance.record'] as $p) {
            $this->assertTrue($desk->can($p), "receptionist: {$p}");
        }
        $this->assertFalse($desk->can('visits.clinical'), 'printing the report is not reading the notes');
        $this->assertFalse($desk->can('finance.manage'));
        $this->assertContains('finance.record', $desk->getAllPermissions()->pluck('name')->all());

        foreach (['visits.diagnose', 'visits.clinical', 'prescriptions.prescribe', 'treatments.manage', 'visits.manage'] as $p) {
            $this->assertTrue($this->dentist->can($p), "doctor: {$p}");
        }

        $assistant = $this->staff('nurse');
        $this->assertTrue($assistant->can('pharmacy.manage'));
        $this->assertTrue($assistant->can('pharmacy.view'));

        // Nothing is written: the practice decides.
        $this->assertSame(0, RoleAbility::query()->count());
    }

    public function test_a_general_hospital_keeps_the_matrix(): void
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        $this->use($general);
        $desk = $this->staff('receptionist', $general);
        foreach (['visits.report', 'pharmacy.manage', 'reports.view', 'finance.view', 'finance.record'] as $p) {
            $this->assertFalse($desk->can($p), "general receptionist: {$p}");
        }
        $this->assertFalse($this->staff('nurse', $general)->can('pharmacy.manage'));
    }

    public function test_the_clinic_still_changes_them_in_staff_and_access(): void
    {
        $admin = $this->staff('hospital_admin');
        $access = app(AccessService::class);
        $desk = $this->staff('receptionist');

        $cell = $access->matrix()['receptionist']['manage_stock'];
        $this->assertTrue($cell['on']);
        $this->assertTrue($cell['default'], 'the practice default reads as the standard');
        $this->assertFalse($cell['changed']);

        $access->setForRole($admin, 'receptionist', 'manage_stock', false);
        $this->assertFalse(User::query()->find($desk->id)->can('pharmacy.manage'));
        $this->assertTrue($access->matrix()['receptionist']['manage_stock']['changed']);

        // Back on: the row goes, the practice default holds again.
        $access->setForRole($admin, 'receptionist', 'manage_stock', true);
        $this->assertSame(0, RoleAbility::query()->count());
        $this->assertTrue(User::query()->find($desk->id)->can('pharmacy.manage'));
    }

    // ── 2. The visit report ────────────────────────────────────────────

    public function test_the_desk_prints_the_patient_summary_and_a_general_desk_cannot(): void
    {
        $visit = $this->visit();
        $this->actingAs($this->staff('receptionist'))->get(route('admin.visits.report', $visit))->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=visit-summary-'.$visit->visit_no.'.pdf');

        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        $this->use($general);
        $other = $this->visit($general);
        $this->actingAs($this->staff('receptionist', $general))->get(route('admin.visits.report', $other))->assertForbidden();
    }

    public function test_the_summary_carries_what_the_clinic_listed_and_nothing_else(): void
    {
        $visit = $this->visit();
        $visit->forceFill(['blood_pressure' => '130/85', 'doctor_remarks' => 'Internal remark for the team'])->save();
        app(DentalNotesService::class)->save($visit, [
            'medical_history' => 'Hypertension', 'hard_tissues' => 'Deep caries 36 DO', 'soft_tissues' => 'Gingivitis lower anterior',
            'xray' => 'Periapical lesion 36', 'diagnosis' => 'Irreversible pulpitis 36',
        ], $this->dentist);

        $rootCanal = Service::factory()->create(['hospital_id' => $this->clinic->id, 'name' => 'Root canal', 'price' => '150000.00', 'is_active' => true]);
        app(DentalService::class)->planItem($visit->patient, $rootCanal, 36, null, null, null, $visit, $this->dentist);

        // A product given without billing stays off the receipt.
        $order = app(OrderService::class)->place($visit, OrderType::Procedure, 'Filling', [], $this->dentist->id);
        $resin = StockItem::factory()->create(['hospital_id' => $this->clinic->id, 'name' => 'Composite resin', 'current_quantity' => '20', 'sale_price' => '500', 'cost_price' => '200', 'is_active' => true]);
        app(BillingService::class)->addItem($order, ['stock_item_id' => $resin->id, 'quantity' => '1', 'billed' => false], $this->dentist->id);
        $extraction = Service::factory()->create(['hospital_id' => $this->clinic->id, 'name' => 'Extraction', 'price' => '50000.00']);
        app(BillingService::class)->orderService($visit, $extraction->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->dentist->id);
        $payment = app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '20000.00', [], $this->dentist->id);

        $desk = $this->staff('receptionist');
        $summary = app(VisitReportService::class)->summary($visit->fresh(), $desk);
        $html = view('pdf.visit-summary', ['summary' => $summary, 'hospital' => $this->clinic])->render();

        foreach (['Amina Nakato', '0772123456', 'Dentist', $this->dentist->name, 'Oral examination', 'Deep caries 36 DO', 'Gingivitis lower anterior',
            'Periapical lesion 36', 'Irreversible pulpitis 36', 'Treatment plan', 'Root canal', 'Planned', 'Receipt', 'Receipt no',
            $payment->receiptNo(), 'Cash', 'Total', 'Paid', 'Balance', 'Prepared by', 'Receptionist Person', now()->format('d M Y')] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['Hypertension', 'Medical history', 'Internal remark', '130/85', 'Composite resin'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
    }

    // ── 3. The layout ──────────────────────────────────────────────────

    public function test_the_quick_view_puts_the_chart_the_plan_and_the_prescription_before_the_orders(): void
    {
        $visit = $this->visit();
        $this->actingAs($this->dentist);
        $component = Livewire::test(Actions::class)->call('openAction', $visit->id, 'summary');

        $this->assertSame(['summary', 'chart', 'plan', 'prescription', 'orders', 'bill', 'history'], array_keys($component->instance()->sections));
        $html = $component->html();
        foreach (['vs-chart', 'vs-plan', 'vs-prescription'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, $id);
        }

        // The desk does not read the clinical record, so it gets none of them.
        $this->actingAs($this->staff('receptionist'));
        $desk = Livewire::test(Actions::class)->call('openAction', $visit->id, 'summary');
        $this->assertSame(['summary', 'orders', 'bill', 'payments', 'history'], array_keys($desk->instance()->sections));
    }

    public function test_a_general_hospital_quick_view_is_as_it_was(): void
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        $this->use($general);
        $visit = $this->visit($general);
        $this->actingAs($this->staff('hospital_admin', $general));

        $sections = Livewire::test(Actions::class)->call('openAction', $visit->id, 'summary')->instance()->sections;
        $this->assertSame(['summary', 'orders', 'bill', 'payments', 'history'], array_keys($sections));
    }

    public function test_the_visit_page_puts_the_notes_the_chart_the_plan_and_the_prescription_before_the_bill(): void
    {
        $visit = $this->visit();
        $this->actingAs($this->dentist)->get(route('admin.visits.show', $visit))->assertOk()
            ->assertSeeInOrder(['visits.panels.dental-notes', 'patients.tabs.dental', 'patients.tabs.dental', 'visits.panels.dosage', 'visits.panels.charges'], false);

        $this->actingAs($this->staff('receptionist'))->get(route('admin.visits.show', $visit))->assertOk()
            ->assertSee(route('admin.visits.report', $visit), false);
    }
}
