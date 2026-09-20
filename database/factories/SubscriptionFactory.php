<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'plan_id' => Plan::factory(),
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'trial_ends_at' => null,
            'status' => SubscriptionStatus::Active,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
            'status' => SubscriptionStatus::Expired,
        ]);
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'starts_at' => now(),
            'ends_at' => null,
            'trial_ends_at' => now()->addDays(14),
            'status' => SubscriptionStatus::Trialing,
        ]);
    }
}
