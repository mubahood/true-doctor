<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Doctor's clinical narrative: complaints, diagnosis, remarks, assigned doctor. */
class VisitClinicalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via VisitPolicy@diagnose
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for the clinical narrative — used by this
     * request and by App\Livewire\Visits\Panels\Clinical (which picks the
     * subset of keys its form actually exposes).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'doctor_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('hospital_id', $hospitalId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('hospital_id', $hospitalId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'complaints' => ['nullable', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'doctor_remarks' => ['nullable', 'string', 'max:2000'],
            'receptionist_remarks' => ['nullable', 'string', 'max:2000'],
            'patient_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
