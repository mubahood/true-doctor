<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\StockCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockCategory> */
class StockCategoryFactory extends Factory
{
    protected $model = StockCategory::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => ucfirst($this->faker->unique()->word()),
            'unit' => 'tablets',
            'is_active' => true,
        ];
    }
}
