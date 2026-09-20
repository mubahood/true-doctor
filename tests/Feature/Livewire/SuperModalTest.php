<?php

namespace Tests\Feature\Livewire;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\PlanLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SuperModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingSuper(): User
    {
        $u = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $u->syncSpatieRole();
        $this->actingAs($u);

        return $u;
    }

    public function test_hospital_modal_creates(): void
    {
        $this->actingSuper();

        Livewire::test(\App\Livewire\Super\Hospitals\Index::class)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('name', 'Riverside Medical')
            ->set('slug', 'riverside-medical')
            ->set('timezone', 'Africa/Kampala')
            ->set('currency', 'UGX')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('hospitals', ['slug' => 'riverside-medical']);
    }

    public function test_hospital_modal_edits(): void
    {
        $this->actingSuper();
        $h = Hospital::factory()->create(['name' => 'Old Name']);

        Livewire::test(\App\Livewire\Super\Hospitals\Index::class)
            ->call('edit', $h->id)
            ->assertSet('name', 'Old Name')
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $h->fresh()->name);
    }

    public function test_plan_modal_creates_with_limits(): void
    {
        $this->actingSuper();

        Livewire::test(\App\Livewire\Super\Plans\Index::class)
            ->call('create')
            ->set('name', 'Growth')
            ->set('price', '250000')
            ->set('billing_cycle', 'monthly')
            ->set('max_staff', 25)
            ->set('max_patients', 5000)
            ->set('max_beds', 60)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $plan = Plan::where('name', 'Growth')->first();
        $this->assertNotNull($plan);
        $this->assertSame(25, $plan->limit('max_staff'));
        $this->assertSame(5000, $plan->limit('max_patients'));
        $this->assertSame(60, $plan->limit('max_beds'));
    }

    public function test_plan_modal_round_trips_the_limits_it_wrote(): void
    {
        $this->actingSuper();
        $plan = Plan::factory()->create(['limits' => ['max_staff' => 3, 'max_patients' => 10, 'max_beds' => 5]]);

        Livewire::test(\App\Livewire\Super\Plans\Index::class)
            ->call('edit', $plan->id)
            ->assertSet('max_staff', 3)
            ->assertSet('max_patients', 10)
            ->assertSet('max_beds', 5)
            ->set('max_staff', 7)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(7, $plan->fresh()->limit('max_staff'));
        $this->assertSame(10, $plan->fresh()->limit('max_patients'));
    }

    /**
     * How a plan is *sold* is the super-admin's to edit too, not just what it
     * costs: a tagline, a dynamic list of feature bullets, and which one plan
     * carries the "Most popular" ribbon on the subscription page.
     */
    public function test_plan_modal_writes_the_marketing_fields(): void
    {
        $this->actingSuper();

        Livewire::test(\App\Livewire\Super\Plans\Index::class)
            ->call('create')
            ->set('name', 'Growth')
            ->set('description', 'For a growing clinic')
            ->set('price', '99')
            ->call('addFeature')->set('features.0', 'Unlimited patients')
            ->call('addFeature')->set('features.1', 'Priority support')
            ->set('is_featured', true)
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::where('name', 'Growth')->firstOrFail();
        $this->assertSame('For a growing clinic', $plan->description);
        $this->assertSame(['Unlimited patients', 'Priority support'], $plan->features);
        $this->assertTrue($plan->is_featured);
    }

    public function test_plan_modal_round_trips_and_can_drop_a_feature(): void
    {
        $this->actingSuper();
        $plan = Plan::factory()->create(['features' => ['Keep me', 'Drop me'], 'description' => 'Tagline']);

        Livewire::test(\App\Livewire\Super\Plans\Index::class)
            ->call('edit', $plan->id)
            ->assertSet('description', 'Tagline')
            ->assertSet('features', ['Keep me', 'Drop me'])
            ->call('removeFeature', 1)
            ->assertSet('features', ['Keep me'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Keep me'], $plan->fresh()->features);
    }

    public function test_a_blank_feature_row_is_never_saved(): void
    {
        $this->actingSuper();

        Livewire::test(\App\Livewire\Super\Plans\Index::class)
            ->call('create')
            ->set('name', 'Sparse')
            ->set('price', '10')
            ->call('addFeature')->set('features.0', 'Real one')
            ->call('addFeature')   // left empty
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Real one'], Plan::where('name', 'Sparse')->firstOrFail()->features);
    }

    /** K4: a staff cap set through the modal has to be the cap PlanLimit enforces. */
    public function test_a_plan_edited_through_the_modal_enforces_the_staff_limit(): void
    {
        $this->actingSuper();
        $plan = Plan::factory()->create(['limits' => []]);
        $hospital = Hospital::factory()->create();
        Subscription::factory()->create(['hospital_id' => $hospital->id, 'plan_id' => $plan->id]);

        Livewire::test(\App\Livewire\Super\Plans\Index::class)
            ->call('edit', $plan->id)
            ->set('max_staff', 1)
            ->call('save')
            ->assertHasNoErrors();

        // One seat used (the hospital's own admin) → the next staff member is refused.
        User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        app(CurrentHospital::class)->set($hospital->id);

        $this->expectException(PlanLimitExceededException::class);
        app(PlanLimit::class)->assertCanCreate('staff');
    }

    public function test_subscription_modal_creates(): void
    {
        $this->actingSuper();
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['is_active' => true]);

        Livewire::test(\App\Livewire\Super\Subscriptions\Index::class)
            ->call('create')
            ->set('hospital_id', $h->id)
            ->set('plan_id', $plan->id)
            ->set('status', 'active')
            ->set('starts_at', '2026-01-01')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('subscriptions', ['hospital_id' => $h->id, 'plan_id' => $plan->id]);
    }

    /** C18: a resolved tenant must not shrink the platform list or hide a row from edit(). */
    public function test_subscription_list_and_edit_ignore_the_tenant_scope(): void
    {
        $this->actingSuper();
        $a = Hospital::factory()->create(['name' => 'Alpha Hospital']);
        $b = Hospital::factory()->create(['name' => 'Beta Hospital']);
        $subA = Subscription::factory()->create(['hospital_id' => $a->id]);
        Subscription::factory()->create(['hospital_id' => $b->id]);

        // The super-admin has been looking at hospital B.
        app(CurrentHospital::class)->set($b->id);

        Livewire::test(\App\Livewire\Super\Subscriptions\Index::class)
            ->assertSee('Alpha Hospital')
            ->assertSee('Beta Hospital')
            ->call('edit', $subA->id)
            ->assertSet('hospital_id', $a->id)
            ->assertHasNoErrors();
    }

    public function test_super_modals_forbidden_for_non_super(): void
    {
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);

        Livewire::test(\App\Livewire\Super\Hospitals\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\Super\Plans\Index::class)->assertForbidden();
        Livewire::test(\App\Livewire\Super\Subscriptions\Index::class)->assertForbidden();
    }
}
