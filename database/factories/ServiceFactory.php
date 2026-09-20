<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'price' => $this->faker->randomElement(['10.00', '25.50', '100.00']),
            'tax_exempt' => false,
            'is_active' => true,
        ];
    }
}
