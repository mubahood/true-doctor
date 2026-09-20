<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_register_page_lists_plans(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'is_active' => true]);

        $this->get('/register')->assertOk()->assertSee('Starter');
    }

    public function test_signup_creates_hospital_owner_and_trial_then_logs_in(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        $res = $this->post('/register', [
            'hospital_name' => 'Sunrise Medical', 'name' => 'Dr Owner',
            'email' => 'owner@sunrise.test', 'password' => 'password1', 'password_confirmation' => 'password1',
            'plan_id' => $plan->id,
        ]);

        $res->assertRedirect(route('admin.onboarding'));
        $this->assertAuthenticated();

        $hospital = Hospital::where('name', 'Sunrise Medical')->firstOrFail();
        $owner = User::where('email', 'owner@sunrise.test')->firstOrFail();
        $this->assertSame('hospital_admin', $owner->role);
        $this->assertSame($hospital->id, $owner->hospital_id);
        $this->assertTrue($owner->hasRole('hospital_admin'));

        $sub = Subscription::where('hospital_id', $hospital->id)->firstOrFail();
        $this->assertSame('trialing', $sub->status->value);
        $this->assertNotNull($sub->trial_ends_at);
        $this->assertTrue($sub->isCurrentlyActive());
        // The trial runs a full 14 days from sign-up.
        $this->assertSame(14, (int) round($sub->starts_at->diffInDays($sub->trial_ends_at)));
        $this->assertSame($sub->trial_ends_at->toDateTimeString(), $sub->ends_at->toDateTimeString());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);
        User::factory()->create(['email' => 'taken@test.com']);

        $this->post('/register', [
            'hospital_name' => 'X Clinic', 'name' => 'A', 'email' => 'taken@test.com',
            'password' => 'password1', 'password_confirmation' => 'password1', 'plan_id' => $plan->id,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_setup_wizard_shows_after_signup(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);
        $this->post('/register', [
            'hospital_name' => 'Y Clinic', 'name' => 'B', 'email' => 'b@y.test',
            'password' => 'password1', 'password_confirmation' => 'password1', 'plan_id' => $plan->id,
        ]);

        // The new owner lands in the setup wizard, is told what is still needed,
        // and sees that they are on a trial rather than being asked to pay.
        $this->get('/admin/onboarding')
            ->assertOk()
            ->assertSee('Finish setting up your hospital')
            ->assertSee('Still needed')
            ->assertSee('Free trial');
    }
}
