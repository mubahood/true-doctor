<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\RadiologyStudy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RadiologyStudy> */
class RadiologyStudyFactory extends Factory
{
    protected $model = RadiologyStudy::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'modality' => 'X-ray',
            'body_part' => 'Chest',
            'price' => '40.00',
            'is_active' => true,
        ];
    }
}
