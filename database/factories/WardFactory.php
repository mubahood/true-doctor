<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ward> */
class WardFactory extends Factory
{
    protected $model = Ward::class;

    public function definition(): array
    {
        return ['hospital_id' => Hospital::factory(), 'name' => ucfirst($this->faker->unique()->word()).' Ward', 'is_active' => true];
    }
}
