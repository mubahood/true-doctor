<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffProfile> */
class StaffProfileFactory extends Factory
{
    protected $model = StaffProfile::class;

    public function definition(): array
    {
        $user = User::factory()->create(['hospital_id' => Hospital::factory()]);

        return [
            'hospital_id' => $user->hospital_id,
            'user_id' => $user->id,
            'specialty' => $this->faker->randomElement(['Cardiology', 'Pediatrics', 'General', 'Surgery']),
            'is_active' => true,
        ];
    }
}
