<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\WelcomeToTrial;
use App\Support\Edition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Public self-registration for the app: the web's rules and service, answered like auth/login. */
class PublicRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $marker = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->marker !== null && is_file($this->marker)) {
            unlink($this->marker);
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function payload(Plan $plan, array $over = []): array
    {
        return array_replace([...$this->humanCheck('register'),
            'hospital_name' => 'Sunrise Medical', 'name' => 'Dr Owner', 'email' => 'owner@sunrise.test',
            'password' => 'password1', 'password_confirmation' => 'password1', 'plan_id' => $plan->id,
        ], $over);
    }

    public function test_plans_are_public_and_list_only_active_ones_with_the_trial(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'is_active' => true, 'limits' => ['max_staff' => 5]]);
        Plan::factory()->create(['name' => 'Retired', 'is_active' => false]);

        $res = $this->getJson('/api/v1/public/plans')->assertOk()->assertJsonPath('data.trial_days', 14);

        $this->assertSame(['Starter'], collect($res->json('data.plans'))->pluck('name')->all());
        $plan = $res->json('data.plans.0');
        foreach (['id', 'slug', 'price', 'price_label', 'billing_cycle', 'limits', 'features', 'trial_days'] as $k) {
            $this->assertArrayHasKey($k, $plan);
        }
        $this->assertSame(5, $plan['limits']['max_staff']);
    }

    public function test_the_picture_check_is_served_as_json(): void
    {
        $res = $this->getJson('/api/v1/public/human-check')->assertOk();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $res->json('data.human_id'));
        $this->assertStringStartsWith('data:image/png;base64,', $res->json('data.image'));
        $this->assertNotNull(\App\Support\HumanCheck::code($res->json('data.human_id')));
        $this->assertSame(3, $res->json('data.min_seconds'));
        // The stamp is the web's: it validates for the 'register' form.
        $this->assertNotNull(\App\Support\HumanCheck::age('register', $res->json('data.human_started')));
    }

    public function test_registration_creates_hospital_owner_and_trial_and_signs_in(): void
    {
        Notification::fake();
        $plan = Plan::factory()->create(['is_active' => true]);

        $res = $this->postJson('/api/v1/public/register', $this->payload($plan) + ['device_name' => 'pixel'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.password_change_required', false)
            ->assertJsonPath('data.user.email', 'owner@sunrise.test')
            ->assertJsonPath('data.user.role', 'hospital_admin');

        $owner = User::where('email', 'owner@sunrise.test')->firstOrFail();
        $hospital = Hospital::where('name', 'Sunrise Medical')->firstOrFail();
        $this->assertSame($hospital->id, $owner->hospital_id);
        $sub = Subscription::where('hospital_id', $hospital->id)->firstOrFail();
        $this->assertSame('trialing', $sub->status->value);
        $this->assertSame(14, (int) round($sub->starts_at->diffInDays($sub->trial_ends_at)));
        Notification::assertSentTo($owner, WelcomeToTrial::class);
        $this->assertSame('pixel', $owner->tokens()->first()->name);

        // The token works: the app is signed straight in, and setup is what is outstanding.
        $this->withHeader('Authorization', 'Bearer '.$res->json('data.token'))
            ->getJson('/api/v1/admin/onboarding')->assertOk()->assertJsonPath('data.complete', false);
    }

    public function test_a_duplicate_email_or_hospital_is_a_validation_error(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);
        User::factory()->create(['email' => 'owner@sunrise.test']);

        $this->postJson('/api/v1/public/register', $this->payload($plan))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('email', 'errors');

        Hospital::factory()->create(['name' => 'Sunrise Medical']);
        $this->postJson('/api/v1/public/register', $this->payload($plan, ['email' => 'new@sunrise.test']))
            ->assertStatus(422)->assertJsonValidationErrors('hospital_name', 'errors');

        $this->assertSame(0, Subscription::count());
    }

    public function test_the_envelope_names_every_missing_field(): void
    {
        $this->postJson('/api/v1/public/register', [])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['hospital_name', 'name', 'email', 'password', 'plan_id', 'human_id', 'human_answer', 'human_started'], 'errors');
    }

    public function test_an_inactive_or_unknown_plan_is_refused(): void
    {
        $inactive = Plan::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/public/register', $this->payload($inactive))->assertStatus(422)->assertJsonValidationErrors('plan_id', 'errors');
        $this->assertSame(0, Hospital::where('name', 'Sunrise Medical')->count());
    }

    public function test_the_bot_checks_are_the_webs(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        // Wrong characters.
        $this->postJson('/api/v1/public/register', $this->payload($plan, ['human_answer' => 'ZZZZZ']))
            ->assertStatus(422)->assertJsonValidationErrors('human_answer', 'errors');

        // The hidden field filled in.
        $this->postJson('/api/v1/public/register', $this->payload($plan, ['website' => 'http://spam.test']))
            ->assertStatus(422)->assertJsonValidationErrors('website', 'errors');

        // A form submitted in under three seconds.
        $fast = $this->payload($plan, ['human_started' => Crypt::encryptString('register|'.time())]);
        $this->postJson('/api/v1/public/register', $fast)->assertStatus(422)->assertJsonValidationErrors('human_started', 'errors');

        // A solved picture is single use.
        $good = $this->payload($plan);
        $this->postJson('/api/v1/public/register', $good)->assertCreated();
        $this->postJson('/api/v1/public/register', $good + ['email' => 'again@sunrise.test', 'hospital_name' => 'Other'])
            ->assertStatus(422)->assertJsonValidationErrors('human_answer', 'errors');

        $this->assertSame(1, Hospital::where('name', 'Sunrise Medical')->count());
    }

    public function test_registration_is_throttled_to_six_an_hour_per_address(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/public/register', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/public/register', $this->payload($plan))
            ->assertStatus(429)->assertJsonPath('success', false)->assertJsonPath('code', 'too_many_requests');
        $this->assertSame(0, Hospital::where('name', 'Sunrise Medical')->count());
    }

    public function test_it_does_not_exist_on_a_standalone_installation(): void
    {
        config(['edition.name' => Edition::STANDALONE]);
        $this->marker = tempnam(sys_get_temp_dir(), 'td-installed-');
        file_put_contents($this->marker, '{"edition":"standalone"}');
        config(['edition.installed_path' => $this->marker]);

        $this->getJson('/api/v1/public/plans')->assertNotFound();
        $this->getJson('/api/v1/public/human-check')->assertNotFound();
        $this->postJson('/api/v1/public/register', [])->assertNotFound();
    }
}
