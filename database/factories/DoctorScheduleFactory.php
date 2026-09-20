<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorSchedule> */
class DoctorScheduleFactory extends Factory
{
    protected $model = DoctorSchedule::class;

    public function definition(): array
    {
        $doctor = User::factory()->create(['hospital_id' => Hospital::factory(), 'role' => 'doctor']);

        return [
            'hospital_id' => $doctor->hospital_id,
            'user_id' => $doctor->id,
            'weekday' => Weekday::Monday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ];
    }
}
