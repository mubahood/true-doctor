<?php

namespace Tests\Feature;

use App\Enums\BedStatus;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Invoice;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\PatientSummaryService;
use App\Services\ReportService;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPatientRecords;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The one service behind the patient page and GET /patients/{uuid}/summary.
 */
class PatientSummaryServiceTest extends TestCase
{
    use BuildsPatientRecords, InteractsWithTenant, RefreshDatabase;

    private PatientSummaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PatientSummaryService::class);
    }

    public function test_figures_add_up_billed_paid_and_outstanding(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);

        $a = $this->invoiceFor($p, '40000.00', null, $admin->id);
        $this->invoiceFor($p, '10000.00', null, $admin->id);
        $this->pay($a, '15000.00', $admin->id);

        $f = $this->service->figures($p, ['money']);

        $this->assertSame('50000.00', $f['billed_total']);
        $this->assertSame('15000.00', $f['paid_total']);
        $this->assertSame('35000.00', $f['outstanding']);
        $this->assertSame(2, $f['outstanding_count']);
        $this->assertSame(0, $f['oldest_unpaid_days']);
    }

    public function test_a_void_invoice_is_neither_billed_nor_owed(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);

        $this->invoiceFor($p, '20000.00', null, $admin->id);
        $void = $this->invoiceFor($p, '99000.00', null, $admin->id);
        Invoice::whereKey($void->id)->update(['status' => 'void']);

        $f = $this->service->figures($p, ['money']);

        $this->assertSame('20000.00', $f['billed_total']);
        $this->assertSame('20000.00', $f['outstanding']);
        $this->assertSame(1, $f['outstanding_count']);
        $this->assertSame('20000.00', $this->service->aging($p)['total']);
        $this->assertSame('20000.00', $this->service->statement($p)['balance_due']);
        $this->assertCount(1, $this->service->statement($p)['invoices']);
    }

    public function test_debt_is_aged_into_bands_by_when_the_invoice_was_raised(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);

        $this->invoiceFor($p, '1000.00', 5, $admin->id);
        $this->invoiceFor($p, '2000.00', 45, $admin->id);
        $this->invoiceFor($p, '4000.00', 75, $admin->id);
        $this->invoiceFor($p, '8000.00', 200, $admin->id);

        $aging = $this->service->aging($p);

        $this->assertSame(['0-30' => '1000.00', '31-60' => '2000.00', '61-90' => '4000.00', '90+' => '8000.00'], $aging['buckets']);
        $this->assertSame('15000.00', $aging['total']);
        $this->assertSame(4, $aging['count']);
        $this->assertSame(200, $this->service->figures($p, ['money'])['oldest_unpaid_days']);
        // The shared definition: what the reports call outstanding is the same total.
        $this->assertSame($aging['total'], app(ReportService::class)->outstanding()['total']);
    }

    public function test_a_fully_paid_invoice_owes_nothing(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $this->pay($this->invoiceFor($p, '5000.00', null, $admin->id), '5000.00', $admin->id);

        $f = $this->service->figures($p, ['money']);

        $this->assertSame('0.00', $f['outstanding']);
        $this->assertNull($f['oldest_unpaid_days']);
        $this->assertSame([], array_filter($this->service->alerts($p, ['money'], $f), fn ($a) => $a['key'] === 'owes'));
    }

    public function test_another_hospitals_money_is_invisible(): void
    {
        $a = $this->hospital();
        $b = $this->hospital();
        $adminB = $this->actingAsRole('hospital_admin', $b);
        $patientB = $this->patientOf($b);
        $this->invoiceFor($patientB, '70000.00', null, $adminB->id);
        Visit::factory()->create(['hospital_id' => $b->id, 'patient_id' => $patientB->id]);

        // Signed in at hospital A, asking about hospital B's patient.
        app(CurrentHospital::class)->set($a->id);
        $f = $this->service->figures($patientB, ['money', 'visits']);

        $this->assertSame('0.00', $f['outstanding']);
        $this->assertSame('0.00', $f['billed_total']);
        $this->assertSame(0, $f['visits_count']);
        $this->assertSame([], $this->service->recent($patientB, ['money', 'visits'], 5)['invoices']);
    }

    public function test_sections_follow_permissions_and_the_practice(): void
    {
        $h = $this->hospital();
        $roles = [];
        foreach (['receptionist', 'doctor', 'nurse', 'pharmacist', 'accountant'] as $role) {
            $roles[$role] = PatientSummaryService::sectionsFor($this->actingAsRole($role, $h));
        }

        $this->assertContains('money', $roles['receptionist']);
        $this->assertContains('cards', $roles['receptionist']);
        $this->assertNotContains('admissions', $roles['receptionist']);

        $this->assertContains('clinical', $roles['doctor']);
        $this->assertContains('admissions', $roles['doctor']);
        $this->assertContains('money', $roles['doctor']);
        $this->assertNotContains('cards', $roles['doctor']);

        $this->assertNotContains('money', $roles['nurse']);
        $this->assertContains('admissions', $roles['nurse']);

        $this->assertNotContains('money', $roles['pharmacist']);
        $this->assertContains('clinical', $roles['pharmacist']);
        $this->assertNotContains('appointments', $roles['pharmacist']);

        $this->assertContains('money', $roles['accountant']);
        $this->assertContains('cards', $roles['accountant']);
        $this->assertNotContains('visits', $roles['accountant']);
        $this->assertNotContains('clinical', $roles['accountant']);
    }

    public function test_a_dental_practice_has_no_admissions(): void
    {
        $h = $this->hospital();
        $doctor = $this->actingAsRole('doctor', $h);
        $this->assertContains('admissions', PatientSummaryService::sectionsFor($doctor));

        $h->forceFill(['settings' => array_merge($h->settings ?? [], ['practice' => ['sector' => 'dental_clinic']])])->save();
        app()->forgetInstance(HospitalSettings::class);
        app(CurrentHospital::class)->set($h->id);

        $sections = PatientSummaryService::sectionsFor($doctor);
        $this->assertNotContains('admissions', $sections);
        $this->assertContains('clinical', $sections);
    }

    public function test_sections_not_given_are_omitted_not_emptied(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $this->invoiceFor($p, '1000.00', null, $admin->id);

        $none = $this->service->summary($p, []);
        $this->assertSame([], $none['figures']);
        $this->assertSame([], $none['recent']);

        $visitsOnly = $this->service->summary($p, ['visits']);
        $this->assertArrayHasKey('visits_count', $visitsOnly['figures']);
        $this->assertArrayNotHasKey('outstanding', $visitsOnly['figures']);
        $this->assertArrayNotHasKey('invoices', $visitsOnly['recent']);
        $this->assertArrayNotHasKey('payments', $visitsOnly['recent']);
        $this->assertSame([], array_filter($visitsOnly['alerts'], fn ($a) => $a['key'] === 'owes'));
        $this->assertArrayNotHasKey('billed', $visitsOnly['recent']['visits'][0]);
    }

    public function test_alerts_say_only_what_applies(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $clean = $this->patientOf($h, ['allergies' => [], 'chronic_conditions' => [], 'consent_given' => true]);
        $this->assertSame([], $this->service->alerts($clean, [], []));

        $p = $this->patientOf($h, [
            'allergies' => ['Penicillin'], 'chronic_conditions' => ['Asthma'], 'consent_given' => false, 'status' => 'inactive',
        ]);
        $this->invoiceFor($p, '30000.00', 40, $admin->id);

        $sections = ['money'];
        $keys = array_column($this->service->alerts($p, $sections, $this->service->figures($p, $sections)), 'key');

        $this->assertEqualsCanonicalizing(['status', 'allergies', 'chronic', 'owes', 'no_consent'], $keys);
    }

    public function test_an_admitted_patient_is_reported_with_ward_and_bed(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $ward = Ward::factory()->create(['hospital_id' => $h->id, 'name' => 'Female Ward']);
        $bed = Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'B4', 'status' => BedStatus::Available]);
        app(AdmissionService::class)->admit($p, $bed, [
            'admitted_at' => now()->subDays(2)->toDateTimeString(), 'admitting_doctor_id' => $admin->id, 'reason' => 'Fluids', 'title' => 'Admit',
        ], $admin->id);

        $f = $this->service->figures($p, ['admissions']);

        $this->assertSame('Female Ward', $f['admitted_now']['ward']);
        $this->assertSame('B4', $f['admitted_now']['bed']);
        $alert = collect($this->service->alerts($p, ['admissions'], $f))->firstWhere('key', 'admitted');
        $this->assertSame('Female Ward', $alert['ward']);
        $this->assertSame(1, Admission::count());
    }

    public function test_visit_and_appointment_figures_and_the_timeline_run_newest_first(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);

        Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'created_at' => now()->subDays(9), 'diagnosis' => 'Malaria']);
        $recentVisit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'created_at' => now()->subDay()]);
        Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $p->id, 'doctor_user_id' => $admin->id,
            'scheduled_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(30),
        ]);

        $sections = ['visits', 'appointments', 'clinical'];
        $f = $this->service->figures($p, $sections);

        $this->assertSame(2, $f['visits_count']);
        $this->assertNotNull($f['open_visit']);
        $this->assertNotNull($f['next_appointment']);

        $recent = $this->service->recent($p, $sections, 5);
        $this->assertSame('Malaria', $recent['diagnoses'][0]['diagnosis']);
        $this->assertSame($recentVisit->visit_no, $recent['visits'][0]['visit_no']);

        $timeline = $this->service->activity($recent);
        $times = array_column($timeline, 'at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertSame('appointment', $timeline[0]['kind']);
    }

    public function test_recent_lists_are_bounded(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        Visit::factory()->count(8)->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);

        $this->assertCount(3, $this->service->recent($p, ['visits'], 3)['visits']);
        $this->assertCount(8, $this->service->recent($p, ['visits'], 100)['visits']);
        $this->assertSame(15, $this->service->visitsPage($p, false, 15)->perPage());
    }
}
