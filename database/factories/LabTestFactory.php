<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\LabTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LabTest> */
class LabTestFactory extends Factory
{
    protected $model = LabTest::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'specimen' => 'blood',
            'unit' => 'mg/dL',
            'reference_range' => '4.0-11.0',
            'price' => '15.00',
            'is_active' => true,
        ];
    }
}
