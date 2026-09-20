<?php

namespace App\Http\Requests;

use App\Enums\AppointmentSource;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Book an appointment. Shape/scope validation only — the availability window,
 * slot alignment and overlap checks are enforced transactionally in
 * AppointmentService (they need row locks a FormRequest can't take).
 */
class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via AppointmentPolicy
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for bookings — used by this request and by
     * the Livewire booking modal (App\Livewire\Appointments\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('hospital_id', $hospitalId)],
            // The visit this was arranged during, where there was one. It is
            // what makes a follow-up a follow-up rather than a cold booking;
            // AppointmentService takes the patient FROM it.
            'origin_visit_id' => ['nullable', 'integer', Rule::exists('visits', 'id')->where('hospital_id', $hospitalId)],
            'doctor_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('hospital_id', $hospitalId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('hospital_id', $hospitalId)],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->where('hospital_id', $hospitalId)],
            'scheduled_at' => ['required', 'date', 'after_or_equal:today'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'source' => ['required', new Enum(AppointmentSource::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
