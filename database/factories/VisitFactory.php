<?php

namespace Database\Factories;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Visit> */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        $hospital = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $hospital->id]);

        return [
            'uuid' => (string) Str::uuid(),
            'hospital_id' => $hospital->id,
            'visit_no' => 'V-'.now()->format('Ymd').'-'.$this->faker->unique()->numberBetween(1, 999),
            'patient_id' => $patient->id,
            // Opened, not started — the same state VisitService::open leaves
            // a real one in, so a factory visit behaves like a real one.
            'status' => VisitStatus::Pending,
            'stage' => VisitStage::Ongoing,
            'outcome' => null,
        ];
    }
}
