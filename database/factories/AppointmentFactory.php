<?php

namespace Database\Factories;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $hospital = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $hospital->id]);
        $doctor = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'doctor']);

        return [
            'uuid' => (string) Str::uuid(),
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => '2026-08-03 09:00:00',
            'ends_at' => '2026-08-03 09:30:00',
            'duration_minutes' => 30,
            'source' => AppointmentSource::WalkIn,
            'status' => AppointmentStatus::Scheduled,
        ];
    }
}
