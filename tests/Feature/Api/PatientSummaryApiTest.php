<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPatientRecords;
use Tests\TestCase;

/** GET /api/v1/patients/{uuid}/summary (docs/api/patients.md). */
class PatientSummaryApiTest extends TestCase
{
    use BuildsPatientRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        app(\App\Support\CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_it_returns_figures_alerts_and_recent_lists_for_a_full_role(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->staff($h, 'hospital_admin');
        $p = $this->patientOf($h, ['allergies' => ['Penicillin']]);
        $this->invoiceFor($p, '25000.00', 10, $admin->id);
        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/v1/patients/{$p->uuid}/summary")->assertOk();

        $res->assertJsonPath('success', true)
            ->assertJsonPath('data.patient.uuid', $p->uuid)
            ->assertJsonPath('data.figures.outstanding', '25000.00')
            ->assertJsonPath('data.figures.billed_total', '25000.00')
            ->assertJsonPath('data.figures.visits_count', 1)
            ->assertJsonPath('data.figures.oldest_unpaid_days', 10)
            ->assertJsonPath('data.aging.0-30', '25000.00')
            ->assertJsonStructure(['data' => ['sections', 'figures' => ['outstanding_label'], 'alerts', 'visits', 'invoices', 'payments', 'diagnoses', 'medication', 'vitals', 'lab_orders', 'admissions', 'appointments']]);
        $this->assertContains('owes', array_column($res->json('data.alerts'), 'key'));
        $this->assertContains('allergies', array_column($res->json('data.alerts'), 'key'));
    }

    public function test_sections_the_role_may_not_see_are_omitted_not_empty(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->staff($h, 'hospital_admin');
        $p = $this->patientOf($h);
        $this->invoiceFor($p, '25000.00', null, $admin->id);

        // A pharmacist: visits.view but no billing.view and no appointments.view.
        Sanctum::actingAs($this->staff($h, 'pharmacist'));
        $data = $this->getJson("/api/v1/patients/{$p->uuid}/summary")->assertOk()->json('data');

        foreach (['invoices', 'payments', 'aging', 'appointments'] as $omitted) {
            $this->assertArrayNotHasKey($omitted, $data, "{$omitted} must be omitted");
        }
        foreach (['outstanding', 'billed_total', 'paid_total', 'next_appointment'] as $omitted) {
            $this->assertArrayNotHasKey($omitted, $data['figures'], "figure {$omitted} must be omitted");
        }
        $this->assertNotContains('owes', array_column($data['alerts'], 'key'));
        $this->assertArrayHasKey('visits', $data);
        $this->assertArrayHasKey('medication', $data);

        // An accountant: money, but nothing clinical.
        Sanctum::actingAs($this->staff($h, 'accountant'));
        $data = $this->getJson("/api/v1/patients/{$p->uuid}/summary")->assertOk()->json('data');

        $this->assertArrayHasKey('invoices', $data);
        $this->assertArrayHasKey('outstanding', $data['figures']);
        foreach (['visits', 'diagnoses', 'medication', 'vitals', 'lab_orders', 'imaging_orders', 'admissions'] as $omitted) {
            $this->assertArrayNotHasKey($omitted, $data, "{$omitted} must be omitted");
        }
        $this->assertArrayNotHasKey('visits_count', $data['figures']);
    }

    public function test_a_dental_practice_gets_no_admissions(): void
    {
        $h = Hospital::factory()->create();
        $h->forceFill(['settings' => ['practice' => ['sector' => 'dental_clinic']]])->save();
        $doctor = $this->staff($h, 'doctor');
        $p = $this->patientOf($h);
        Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);
        Sanctum::actingAs($doctor);

        $data = $this->getJson("/api/v1/patients/{$p->uuid}/summary")->assertOk()->json('data');

        $this->assertArrayNotHasKey('admissions', $data);
        $this->assertArrayNotHasKey('admitted_now', $data['figures']);
        $this->assertArrayHasKey('visits', $data);
    }

    public function test_a_role_without_patient_view_is_refused(): void
    {
        $h = Hospital::factory()->create();
        $p = $this->patientOf($h);
        $u = $this->staff($h, 'receptionist');
        $u->syncRoles([]);
        Sanctum::actingAs($u);

        $this->getJson("/api/v1/patients/{$p->uuid}/summary")->assertForbidden();
    }

    public function test_another_hospitals_patient_is_not_found(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = $this->patientOf($b);
        Sanctum::actingAs($this->staff($a, 'hospital_admin'));

        $this->getJson("/api/v1/patients/{$patientB->uuid}/summary")->assertNotFound();
    }

    public function test_it_needs_a_token_and_the_brief_is_untouched(): void
    {
        $h = Hospital::factory()->create();
        $p = $this->patientOf($h);

        $this->getJson("/api/v1/patients/{$p->uuid}/summary")->assertUnauthorized();

        Sanctum::actingAs($this->staff($h, 'receptionist'));
        $this->getJson("/api/v1/patients/{$p->uuid}/brief")->assertOk()->assertJsonStructure(['data' => ['owed', 'owed_label', 'open_visit']]);
        $this->assertSame('api.patients.summary', app('router')->getRoutes()->getByName('api.patients.summary')->getName());
    }

    public function test_the_statement_pdf_is_served_to_the_app_under_the_web_rules(): void
    {
        $h = Hospital::factory()->create();
        $p = $this->patientOf($h);

        Sanctum::actingAs($this->staff($h, 'accountant'));
        $response = $this->get("/api/v1/patients/{$p->uuid}/statement");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        Sanctum::actingAs($this->staff($h, 'nurse'));
        $this->get("/api/v1/patients/{$p->uuid}/statement")->assertForbidden();
    }
}
