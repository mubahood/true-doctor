<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Livewire\Departments\Index as DepartmentsIndex;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use App\Support\Sequence;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Regression guards for docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md K1, K3, K5, K10, K16, I11, I12. */
class IntegrityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Hospital $h): User
    {
        $this->seed(RbacSeeder::class);
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_sequences_are_per_hospital_per_period_and_seeded_from_existing_rows(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();

        app(CurrentHospital::class)->set($a->id);
        $this->assertSame(43, Sequence::next('invoice', '2026', fn () => 42));
        $this->assertSame(44, Sequence::next('invoice', '2026', fn () => 999)); // seed only used once
        $this->assertSame(1, Sequence::next('invoice', '2027'));

        app(CurrentHospital::class)->set($b->id);
        $this->assertSame(1, Sequence::next('invoice', '2026'));

        $this->assertDatabaseCount('sequences', 3);
    }

    public function test_patient_numbers_continue_after_existing_records(): void
    {
        $h = Hospital::factory()->create();
        $this->admin($h);
        Patient::factory()->count(2)->create(['hospital_id' => $h->id]);

        $p = app(\App\Services\PatientService::class)->register(['first_name' => 'New', 'last_name' => 'One', 'sex' => 'male', 'status' => 'active'], null);

        $this->assertSame($h->id, $p->hospital_id);
        $this->assertSame(3, (int) \Illuminate\Support\Facades\DB::table('sequences')->where('key', 'patient')->where('hospital_id', $h->id)->value('next_value'));
    }

    public function test_a_visit_cannot_receive_two_invoices(): void
    {
        $h = Hospital::factory()->create();
        $this->admin($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '10.00']);
        $billing = app(BillingService::class);
        $billing->orderService($c, $svc->id, 1);

        $billing->generateInvoice($c);

        $this->expectException(\RuntimeException::class);
        $billing->generateInvoice($c);
    }

    public function test_recreating_an_archived_department_restores_it_instead_of_failing(): void
    {
        $h = Hospital::factory()->create();
        $this->admin($h);
        $d = Department::factory()->create(['hospital_id' => $h->id, 'name' => 'Radiology', 'code' => 'RAD']);
        $d->delete();

        Livewire::test(DepartmentsIndex::class)->call('create')->set('name', 'Radiology')->set('code', 'rd')
            ->call('save')->assertHasNoErrors()->assertDispatched('toast');

        $this->assertSame(1, Department::withTrashed()->where('name', 'Radiology')->count());
        $this->assertNull($d->fresh()->deleted_at);
        $this->assertSame('RD', $d->fresh()->code);
    }

    public function test_decimal_helper_never_goes_through_float(): void
    {
        $this->assertSame('18.0000', HospitalSettings::decimal('18', 4));
        $this->assertSame('0.1450', HospitalSettings::decimal(0.145, 4));
        $this->assertSame('1234567.89', HospitalSettings::decimal('1234567.89', 2));
        $this->assertSame('0.00', HospitalSettings::decimal('abc', 2));
    }

    public function test_slugs_get_a_suffix_on_collision(): void
    {
        $a = Hospital::factory()->create(['name' => "St. Mary's Clinic", 'slug' => null]);
        $b = Hospital::factory()->create(['name' => "St. Mary's Clinic", 'slug' => null]);
        $this->assertSame('st-marys-clinic', $a->slug);
        $this->assertSame('st-marys-clinic-2', $b->slug);

        Plan::factory()->create(['name' => 'Gold', 'slug' => null]);
        $this->assertSame('gold-2', Plan::factory()->create(['name' => 'Gold', 'slug' => null])->slug);
    }

    public function test_web_responses_carry_security_headers(): void
    {
        $h = Hospital::factory()->create();
        $this->admin($h);

        $this->get(route('admin.departments.index'))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    public function test_api_login_is_throttled_per_email(): void
    {
        $h = Hospital::factory()->create();
        User::factory()->create(['hospital_id' => $h->id, 'email' => 'doc@example.test', 'role' => 'doctor']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'doc@example.test', 'password' => 'wrong', 'device_name' => 't'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'doc@example.test', 'password' => 'wrong', 'device_name' => 't'])->assertStatus(429);
        // Unknown accounts are throttled the same way (no enumeration by status).
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong', 'device_name' => 't'])->assertStatus(401);
    }

    public function test_payments_cannot_be_posted_into_a_closed_period(): void
    {
        $h = Hospital::factory()->create();
        $u = $this->admin($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '10.00']);
        $billing = app(BillingService::class);
        $billing->orderService($c, $svc->id, 1);
        $invoice = $billing->generateInvoice($c);

        $fy = app(\App\Services\FinancialYearService::class)->create(['name' => 'FY', 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString()]);
        app(\App\Services\FinancialYearService::class)->close($fy, $u->id);

        $this->expectException(\App\Exceptions\ClosedPeriodException::class);
        $billing->recordPayment($invoice, PaymentMethod::Cash, '10.00', [], $u->id);
    }
}
