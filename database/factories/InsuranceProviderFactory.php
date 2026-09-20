<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\InsuranceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InsuranceProvider> */
class InsuranceProviderFactory extends Factory
{
    protected $model = InsuranceProvider::class;

    public function definition(): array
    {
        return ['hospital_id' => Hospital::factory(), 'name' => $this->faker->unique()->company().' Insurance', 'is_active' => true];
    }
}
