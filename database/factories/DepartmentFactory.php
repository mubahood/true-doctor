<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Hospital;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'is_active' => true,
        ];
    }
}
