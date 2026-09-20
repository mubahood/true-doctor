<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'hospital_id' => Hospital::factory(),
            'patient_no' => 'PT-'.date('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0'),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'sex' => $this->faker->randomElement(['male', 'female']),
            'phone_1' => '+2567'.$this->faker->numberBetween(10000000, 99999999),
            'status' => 'active',
            'consent_given' => true,
        ];
    }
}
