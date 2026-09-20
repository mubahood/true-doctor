<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for an admission — used by this request and
     * by the Livewire admit modal (App\Livewire\Admissions\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            // A stay is an ORDER ON A VISIT, so the visit is what an admission
            // is raised against — the same way a lab order or an imaging
            // order is, and for the same reason. The patient is whoever that
            // visit belongs to; asking for them separately invited the two to
            // disagree, and `AdmissionService` had to refuse the combination.
            'visit_id' => ['required', 'integer', Rule::exists('visits', 'id')->where('hospital_id', $hospitalId)],
            'bed_id' => ['required', 'integer', Rule::exists('beds', 'id')->where('hospital_id', $hospitalId)],
            // Still validated, because the service still takes one, but the
            // form no longer asks: it is read off the chosen visit.
            'patient_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->where('hospital_id', $hospitalId)],
            'admitting_doctor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('hospital_id', $hospitalId)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
