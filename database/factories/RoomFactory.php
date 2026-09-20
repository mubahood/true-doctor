<?php

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Hospital;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Room> */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => 'Room '.$this->faker->unique()->numberBetween(1, 9999),
            'type' => RoomType::Consultation,
            'status' => RoomStatus::Available,
            'capacity' => 1,
        ];
    }
}
