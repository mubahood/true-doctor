<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' plan',
            'price' => fake()->randomElement([50000, 150000, 400000]),
            'billing_cycle' => BillingCycle::Monthly,
            'limits' => ['max_users' => 10, 'max_patients' => 1000, 'modules' => ['patients', 'appointments']],
            'is_active' => true,
        ];
    }
}
