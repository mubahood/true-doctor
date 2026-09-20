<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientInsuranceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via InsuranceClaimPolicy@manage
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for patient coverage — used by this request
     * and by the Livewire panel (App\Livewire\Patients\Panels\Insurances).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'insurance_provider_id' => ['required', 'integer', Rule::exists('insurance_providers', 'id')->where('hospital_id', $hospitalId)],
            'member_no' => ['required', 'string', 'max:64'],
            'coverage_percent' => ['required', 'numeric', 'between:0,100'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
