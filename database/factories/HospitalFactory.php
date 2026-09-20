<?php

namespace Database\Factories;

use App\Enums\HospitalStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Hospital>
 */
class HospitalFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Hospital';

        return [
            'name' => $name,
            'timezone' => 'Africa/Kampala',
            'currency' => 'UGX',
            'status' => HospitalStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => HospitalStatus::Suspended]);
    }
}
