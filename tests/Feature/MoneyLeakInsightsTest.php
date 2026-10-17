<?php

namespace Tests\Feature;

use App\Enums\VisitStatus;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\PracticeService;
use App\Support\CurrentHospital;
use App\Support\Insights\InsightEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Phase 2 insight rules (roadmap 09 §1): money leaks (visits closed
 * without a bill, large discounts) and follow-up (abnormal results after the
 * patient left, dental check-ups overdue). Each: fires, stays quiet, is
 * role-aware, and never counts another hospital's rows.
 */
class MoneyLeakInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
    }

    private function user(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    /** @return array<string,array<string,mixed>> key => insight, as $user sees it */
    private function insights(User $user): array
    {
        Cache::flush();
        app(CurrentHospital::class)->set($user->hospital_id);

        return collect(app(InsightEngine::class)->for($user, InsightEngine::MAX_LIMIT))->keyBy('key')->all();
    }

    private function visit(Hospital $h, array $extra = []): Visit
    {
        app(CurrentHospital::class)->set($h->id);

        return Visit::factory()->create($extra + ['hospital_id' => $h->id, 'patient_id' => $extra['patient_id'] ?? Patient::factory()->create(['hospital_id' => $h->id])->id]);
    }

    /** A visit with a charge on it, then closed with no invoice. */
    private function closedUnbilled(Hospital $h, string $price = '40000.00', int $daysAgo = 2): Visit
    {
        $visit = $this->visit($h);
        $service = Service::factory()->create(['hospital_id' => $h->id, 'price' => $price]);
        app(BillingService::class)->orderService($visit, $service->id);
        $visit->forceFill(['status' => VisitStatus::Completed, 'completed_at' => now()->subDays($daysAgo)])->save();

        return $visit;
    }

    // ── Visits closed without a bill ─────────────────────────────────────

    public function test_a_visit_closed_with_work_and_no_invoice_is_a_leak(): void
    {
        $this->closedUnbilled($this->h);
        $this->closedUnbilled($this->h, '10000.00', 45);  // too long ago
        $this->closedUnbilled($this->other, '99000.00');  // another hospital

        $item = $this->insights($this->user('receptionist'))['visits-closed-unbilled'] ?? null;
        $this->assertNotNull($item);
        $this->assertSame('danger', $item['tone']);
        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString('40,000', $item['detail']);
        $this->assertStringNotContainsString('99,000', $item['detail']);
    }

    public function test_an_invoiced_visit_or_one_with_only_cancelled_lines_is_not(): void
    {
        $billed = $this->closedUnbilled($this->h);
        app(CurrentHospital::class)->set($this->h->id);
        $billed->forceFill(['status' => VisitStatus::Ongoing, 'completed_at' => null])->save();
        app(BillingService::class)->generateInvoice($billed->fresh());
        $billed->forceFill(['status' => VisitStatus::Completed, 'completed_at' => now()->subDay()])->save();

        $cancelled = $this->closedUnbilled($this->h);
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->where('visit_id', $cancelled->id)->pluck('id'))->update(['status' => 'cancelled']);

        $this->assertArrayNotHasKey('visits-closed-unbilled', $this->insights($this->user('receptionist')));
    }

    public function test_only_people_who_see_billing_are_told(): void
    {
        $this->closedUnbilled($this->h);

        $this->assertArrayHasKey('visits-closed-unbilled', $this->insights($this->user('hospital_admin')));
        $this->assertArrayNotHasKey('visits-closed-unbilled', $this->insights($this->user('nurse')));
    }

    // ── Large discounts ─────────────────────────────────────────────────

    private function invoice(Hospital $h, string $subtotal, string $discount, int $daysAgo = 1): void
    {
        $visit = $this->visit($h);
        DB::table('invoices')->insert([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $h->id, 'invoice_no' => 'INV-'.Str::random(8),
            'visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'currency' => 'UGX',
            'subtotal' => $subtotal, 'tax_total' => '0', 'discount' => $discount, 'total' => bcsub($subtotal, $discount, 2),
            'amount_paid' => '0', 'balance' => bcsub($subtotal, $discount, 2), 'status' => 'issued',
            'issued_at' => now()->subDays($daysAgo), 'created_at' => now()->subDays($daysAgo), 'updated_at' => now(),
        ]);
    }

    public function test_discounts_over_a_fifth_of_the_bill_are_shown_to_the_owner(): void
    {
        $this->invoice($this->h, '100000.00', '30000.00');      // 30%: large
        $this->invoice($this->h, '100000.00', '20000.00');      // exactly 20%: not over
        $this->invoice($this->h, '100000.00', '50000.00', 10);  // too long ago
        $this->invoice($this->other, '100000.00', '90000.00');  // another hospital

        $item = $this->insights($this->user('hospital_admin'))['discounts-large'] ?? null;
        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString('30,000', $item['detail']);

        // The desk that gives discounts is not the audience.
        $this->assertArrayNotHasKey('discounts-large', $this->insights($this->user('receptionist')));
    }

    public function test_no_large_discount_no_item(): void
    {
        $this->invoice($this->h, '100000.00', '5000.00');
        $this->assertArrayNotHasKey('discounts-large', $this->insights($this->user('hospital_admin')));
    }

    // ── Abnormal results after the patient left ─────────────────────────

    private function labResult(Visit $visit, string $flag, \Illuminate\Support\Carbon $resultedAt): void
    {
        $orderId = DB::table('lab_orders')->insertGetId([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $visit->hospital_id, 'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id, 'status' => 'completed', 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
        DB::table('lab_order_items')->insert([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $visit->hospital_id, 'lab_order_id' => $orderId,
            'name' => 'Haemoglobin', 'price' => '5000.00', 'result_value' => '6.1', 'result_flag' => $flag,
            'resulted_at' => $resultedAt, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
    }

    public function test_an_abnormal_result_after_the_visit_closed_needs_a_call(): void
    {
        $doctor = $this->user('doctor');
        $mine = $this->visit($this->h, ['doctor_user_id' => $doctor->id, 'status' => VisitStatus::Completed, 'completed_at' => now()->subDays(2)]);
        $this->labResult($mine, 'low', now()->subDay());

        $theirs = $this->visit($this->h, ['status' => VisitStatus::Completed, 'completed_at' => now()->subDays(2)]);
        $this->labResult($theirs, 'high', now()->subDay());

        // Not counted: normal, read while the patient was there, and another hospital's.
        $this->labResult($this->visit($this->h, ['status' => VisitStatus::Completed, 'completed_at' => now()->subDays(2)]), 'normal', now()->subDay());
        $this->labResult($this->visit($this->h, ['status' => VisitStatus::Completed, 'completed_at' => now()->subHour()]), 'high', now()->subDay());
        $this->labResult($this->visit($this->other, ['status' => VisitStatus::Completed, 'completed_at' => now()->subDays(2)]), 'high', now()->subDay());

        $item = $this->insights($doctor)['results-after-patient-left'] ?? null;
        $this->assertNotNull($item);
        $this->assertSame(1, $item['count'], 'a doctor sees their own patients');

        $this->assertSame(2, $this->insights($this->user('hospital_admin'))['results-after-patient-left']['count']);
        $this->assertArrayNotHasKey('results-after-patient-left', $this->insights($this->user('receptionist')));
    }

    // ── Dental check-ups overdue ────────────────────────────────────────

    public function test_dental_recalls_due_and_not_booked(): void
    {
        app(PracticeService::class)->choose($this->h, 'dental_clinic', []);
        $this->h->refresh();
        app(CurrentHospital::class)->set($this->h->id);

        $due = fn (int $daysAgo) => Patient::factory()->create(['hospital_id' => $this->h->id, 'next_recall_on' => now()->subDays($daysAgo)->toDateString()]);
        $due(10);                               // overdue
        $back = $due(20);                       // came back since
        $this->visit($this->h, ['patient_id' => $back->id, 'created_at' => now()->subDays(5)]);
        $booked = $due(15);                     // already booked
        \App\Models\Appointment::factory()->create([
            'hospital_id' => $this->h->id, 'patient_id' => $booked->id,
            'scheduled_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(30),
        ]);
        $due(90);                               // too long ago
        Patient::factory()->create(['hospital_id' => $this->h->id, 'next_recall_on' => now()->addDays(5)->toDateString()]); // not yet
        Patient::factory()->create(['hospital_id' => $this->other->id, 'next_recall_on' => now()->subDays(3)->toDateString()]);

        $item = $this->insights($this->user('receptionist'))['dental-recalls-due'] ?? null;
        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
    }

    public function test_a_clinic_without_dentistry_is_not_told_about_recalls(): void
    {
        app(PracticeService::class)->choose($this->h, 'medical_clinic', []);
        $this->h->refresh();
        Patient::factory()->create(['hospital_id' => $this->h->id, 'next_recall_on' => now()->subDays(3)->toDateString()]);

        $this->assertArrayNotHasKey('dental-recalls-due', $this->insights($this->user('receptionist')));
    }

    public function test_the_new_rules_are_registered(): void
    {
        foreach ([
            \App\Support\Insights\Rules\VisitsClosedUnbilled::class,
            \App\Support\Insights\Rules\LargeDiscounts::class,
            \App\Support\Insights\Rules\ResultsAfterPatientLeft::class,
            \App\Support\Insights\Rules\DentalRecallsDue::class,
        ] as $rule) {
            $this->assertContains($rule, InsightEngine::RULES);
        }
    }
}
