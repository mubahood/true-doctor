<?php

namespace Database\Factories;

use App\Enums\BedStatus;
use App\Models\Bed;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bed> */
class BedFactory extends Factory
{
    protected $model = Bed::class;

    public function definition(): array
    {
        $ward = Ward::factory()->create();

        return [
            'hospital_id' => $ward->hospital_id,
            'ward_id' => $ward->id,
            'name' => 'Bed '.$this->faker->unique()->numberBetween(1, 9999),
            'daily_charge' => '50.00',
            'status' => BedStatus::Available,
            'is_active' => true,
        ];
    }
}
