<?php

namespace Tests\Feature;

use App\Models\GatewayLog;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionActivated;
use App\Notifications\TrialEnding;
use App\Notifications\WelcomeToTrial;
use App\Services\Gateway\GatewayPaymentService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_registration_sends_a_welcome_email(): void
    {
        Notification::fake();
        $plan = Plan::factory()->create(['is_active' => true]);

        $this->post('/register', [
            'hospital_name' => 'Welcome Clinic', 'name' => 'Owner', 'email' => 'owner@welcome.test',
            'password' => 'password1', 'password_confirmation' => 'password1', 'plan_id' => $plan->id,
        ]);

        $owner = User::where('email', 'owner@welcome.test')->firstOrFail();
        Notification::assertSentTo($owner, WelcomeToTrial::class);
    }

    public function test_activation_sends_a_receipt_email(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['price' => '149.00', 'billing_cycle' => 'monthly', 'is_active' => true]);
        $owner = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $owner->syncSpatieRole();
        app(CurrentHospital::class)->set($h->id);
        Subscription::factory()->create(['hospital_id' => $h->id, 'plan_id' => $plan->id, 'status' => 'trialing']);

        GatewayLog::create([
            'hospital_id' => $h->id, 'provider' => 'flutterwave', 'tx_ref' => 'SUB-mail',
            'status' => 'pending', 'amount' => '149.00', 'currency' => 'USD',
            'meta' => ['purpose' => 'subscription', 'plan_id' => $plan->id],
        ]);
        config()->set('services.flutterwave.secret_hash', 'h');
        config()->set('services.flutterwave.base_url', 'https://api.flutterwave.com');
        Http::fake(['*/verify' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'tx_ref' => 'SUB-mail', 'id' => 1, 'amount' => 149, 'currency' => 'USD']], 200)]);

        app(GatewayPaymentService::class)->settle('1');

        Notification::assertSentTo($owner, SubscriptionActivated::class);
    }

    public function test_trial_reminder_command_notifies_owners(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['is_active' => true]);
        $owner = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $owner->syncSpatieRole();
        Subscription::factory()->create([
            'hospital_id' => $h->id, 'plan_id' => $plan->id, 'status' => 'trialing',
            'trial_ends_at' => now()->addDays(2), 'ends_at' => now()->addDays(2),
        ]);

        $this->artisan('subscriptions:trial-reminders')->assertExitCode(0);

        Notification::assertSentTo($owner, TrialEnding::class);
    }

    public function test_trial_reminder_ignores_trials_ending_far_out(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['is_active' => true]);
        $owner = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $owner->syncSpatieRole();
        Subscription::factory()->create([
            'hospital_id' => $h->id, 'plan_id' => $plan->id, 'status' => 'trialing',
            'trial_ends_at' => now()->addDays(20), 'ends_at' => now()->addDays(20),
        ]);

        $this->artisan('subscriptions:trial-reminders')->assertExitCode(0);
        Notification::assertNothingSent();
    }
}
