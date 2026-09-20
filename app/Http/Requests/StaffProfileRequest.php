<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update the clinical/HR profile for a staff user. The linked user must
 * belong to the current hospital and not already have a profile (one per user).
 */
class StaffProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via StaffProfilePolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('staffProfile')?->id);
    }

    /**
     * The single validation source for staff profiles — shared by this request
     * and the Livewire modal (App\Livewire\StaffProfiles\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('hospital_id', $hospitalId),
                Rule::unique('staff_profiles', 'user_id')->whereNull('deleted_at')->ignore($ignoreId),
            ],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('hospital_id', $hospitalId)],
            'job_title' => ['nullable', 'string', 'max:120'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'license_no' => ['nullable', 'string', 'max:80'],
            'qualifications' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
