<?php

namespace Tests\Feature;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\CurrentHospital;
use App\Support\PlanLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    private function hospitalOnPlan(array $limits): Hospital
    {
        $hospital = Hospital::factory()->create();
        $plan = Plan::factory()->create(['limits' => $limits]);
        Subscription::factory()->create(['hospital_id' => $hospital->id, 'plan_id' => $plan->id]);
        app(CurrentHospital::class)->set($hospital->id);

        return $hospital;
    }

    public function test_allows_creation_below_the_limit(): void
    {
        $h = $this->hospitalOnPlan(['max_patients' => 3]);
        Patient::factory()->create(['hospital_id' => $h->id]);

        // 1 of 3 — fine.
        app(PlanLimit::class)->assertCanCreate('patients');
        $this->assertTrue(true);
    }

    public function test_blocks_creation_at_the_limit(): void
    {
        $h = $this->hospitalOnPlan(['max_patients' => 2]);
        Patient::factory()->count(2)->create(['hospital_id' => $h->id]);

        $this->expectException(PlanLimitExceededException::class);
        app(PlanLimit::class)->assertCanCreate('patients');
    }

    public function test_no_limit_key_means_unlimited(): void
    {
        $h = $this->hospitalOnPlan(['max_staff' => 5]); // no max_patients
        Patient::factory()->count(50)->create(['hospital_id' => $h->id]);

        app(PlanLimit::class)->assertCanCreate('patients'); // no cap → no throw
        $this->assertTrue(true);
    }

    public function test_no_subscription_means_no_enforcement(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        Patient::factory()->count(10)->create(['hospital_id' => $h->id]);

        app(PlanLimit::class)->assertCanCreate('patients');
        $this->assertTrue(true);
    }

    public function test_limit_is_per_hospital(): void
    {
        $a = $this->hospitalOnPlan(['max_patients' => 1]);
        Patient::factory()->create(['hospital_id' => $a->id]);
        // Hospital B has its own headroom — a different tenant's count doesn't matter.
        $b = $this->hospitalOnPlan(['max_patients' => 5]);
        Patient::factory()->create(['hospital_id' => $b->id]);

        app(CurrentHospital::class)->set($b->id);
        app(PlanLimit::class)->assertCanCreate('patients'); // B: 1 of 5, fine
        $this->assertTrue(true);

        app(CurrentHospital::class)->set($a->id);
        $this->expectException(PlanLimitExceededException::class);
        app(PlanLimit::class)->assertCanCreate('patients'); // A: 1 of 1, blocked
    }
}
