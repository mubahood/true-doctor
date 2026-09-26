<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Open a visit for an existing patient. */
class VisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via VisitPolicy
    }

    public function rules(): array
    {
        $rules = self::rulesFor();
        $rules['appointment_id'][] = self::appointmentIsTheirs($this->input('patient_id'));

        return $rules;
    }

    /**
     * The appointment a visit fulfils must be this patient's, and must not
     * have a visit already. A client only ever offers those, but no client is
     * trusted to; the unique index behind it (one_visit_per_appointment) is
     * the last word.
     */
    public static function appointmentIsTheirs(mixed $patientId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($patientId): void {
            if ($value === null || $value === '') {
                return;
            }

            $ok = \App\Models\Appointment::whereKey($value)
                ->where('patient_id', $patientId)
                ->whereDoesntHave('visit')
                ->exists();

            if (! $ok) {
                $fail('That appointment is not this patient\'s, or already has a visit.');
            }
        };
    }

    /**
     * The single validation source for opening a visit — used by this
     * request and by the Livewire modal (App\Livewire\Visits\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('hospital_id', $hospitalId)],
            'appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('hospital_id', $hospitalId)],
            'doctor_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('hospital_id', $hospitalId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('hospital_id', $hospitalId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'complaints' => ['nullable', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            // Is anybody seeing them yet? A visit opened at the desk is
            // Pending; one opened by the person about to see them is Ongoing,
            // and VisitService::start() writes that move into the trail.
            'start_now' => ['sometimes', 'boolean'],
            // Vitals come from the ONE place that validates vitals, so the
            // ranges a nurse is held to at the desk are the ranges they are
            // held to in the panel.
        ] + VisitVitalsRequest::rulesFor();
    }
}
