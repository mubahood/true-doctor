<?php

namespace Tests\Unit;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hospital_gets_a_uuid_and_slug_on_create(): void
    {
        $hospital = Hospital::factory()->create(['name' => 'Mulago Referral', 'slug' => null]);

        $this->assertNotEmpty($hospital->uuid);
        $this->assertSame('mulago-referral', $hospital->slug);
    }

    public function test_hospital_active_subscription_ignores_expired_and_cancelled(): void
    {
        $hospital = Hospital::factory()->create();
        $plan = Plan::factory()->create();

        Subscription::factory()->for($hospital)->for($plan)->expired()->create();
        $active = Subscription::factory()->for($hospital)->for($plan)->create(['status' => SubscriptionStatus::Active]);

        $this->assertTrue($hospital->activeSubscription()->is($active));
    }

    public function test_subscription_is_currently_active_respects_end_date(): void
    {
        $future = Subscription::factory()->create(['status' => SubscriptionStatus::Active, 'ends_at' => now()->addDay()]);
        $past = Subscription::factory()->create(['status' => SubscriptionStatus::Active, 'ends_at' => now()->subDay()]);
        $openEnded = Subscription::factory()->create(['status' => SubscriptionStatus::Trialing, 'ends_at' => null]);
        $cancelled = Subscription::factory()->create(['status' => SubscriptionStatus::Cancelled, 'ends_at' => now()->addDay()]);

        $this->assertTrue($future->isCurrentlyActive());
        $this->assertFalse($past->isCurrentlyActive());
        $this->assertTrue($openEnded->isCurrentlyActive());
        $this->assertFalse($cancelled->isCurrentlyActive());
    }
}
