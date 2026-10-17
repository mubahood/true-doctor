<?php

namespace Tests\Feature;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\PlanLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Plans cap staff accounts and beds; never patients (docs/decisions.md 2026-10-17). */
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
        $h = $this->hospitalOnPlan(['max_staff' => 3]);
        User::factory()->create(['hospital_id' => $h->id]);

        // 1 of 3: fine.
        app(PlanLimit::class)->assertCanCreate('staff');
        $this->assertTrue(true);
    }

    public function test_blocks_creation_at_the_limit(): void
    {
        $h = $this->hospitalOnPlan(['max_staff' => 2]);
        User::factory()->count(2)->create(['hospital_id' => $h->id]);

        $this->expectException(PlanLimitExceededException::class);
        app(PlanLimit::class)->assertCanCreate('staff');
    }

    public function test_patients_are_never_limited_even_by_an_old_plan_that_says_so(): void
    {
        $h = $this->hospitalOnPlan(['max_patients' => 2, 'max_staff' => 5]);
        Patient::factory()->count(5)->create(['hospital_id' => $h->id]);

        $this->assertNull(app(PlanLimit::class)->limitFor('patients'));
        app(PlanLimit::class)->assertCanCreate('patients');
        $this->assertTrue(true);
    }

    public function test_no_limit_key_means_unlimited(): void
    {
        $h = $this->hospitalOnPlan(['max_beds' => 5]); // no max_staff
        User::factory()->count(20)->create(['hospital_id' => $h->id]);

        app(PlanLimit::class)->assertCanCreate('staff');
        $this->assertTrue(true);
    }

    public function test_no_subscription_means_no_enforcement(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        User::factory()->count(10)->create(['hospital_id' => $h->id]);

        app(PlanLimit::class)->assertCanCreate('staff');
        $this->assertTrue(true);
    }

    public function test_limit_is_per_hospital(): void
    {
        $a = $this->hospitalOnPlan(['max_staff' => 1]);
        User::factory()->create(['hospital_id' => $a->id]);
        // Hospital B has its own headroom: a different tenant's count does not matter.
        $b = $this->hospitalOnPlan(['max_staff' => 5]);
        User::factory()->create(['hospital_id' => $b->id]);

        app(CurrentHospital::class)->set($b->id);
        app(PlanLimit::class)->assertCanCreate('staff'); // B: 1 of 5, fine
        $this->assertTrue(true);

        app(CurrentHospital::class)->set($a->id);
        $this->expectException(PlanLimitExceededException::class);
        app(PlanLimit::class)->assertCanCreate('staff'); // A: 1 of 1, blocked
    }
}
