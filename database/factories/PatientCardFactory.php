<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientCard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PatientCard>
 */
class PatientCardFactory extends Factory
{
    protected $model = PatientCard::class;

    public function definition(): array
    {
        $patient = Patient::factory()->create();
        $number = (string) $this->faker->unique()->numberBetween(1000000000000000, 9999999999999999);

        return [
            'uuid' => (string) Str::uuid(),
            'hospital_id' => $patient->hospital_id,
            'patient_id' => $patient->id,
            'card_number' => $number,
            'card_hash' => hash_hmac('sha256', $number, (string) config('app.key')),
            'status' => 'active',
            'accepts_credit' => false,
            'max_credit' => 0,
            'balance' => 0,
        ];
    }
}
