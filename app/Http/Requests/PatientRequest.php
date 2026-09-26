<?php

namespace App\Http\Requests;

use App\Enums\PatientSex;
use App\Enums\PatientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Typed boundary for patient writes (B9/B10) — nothing throwaway reaches the
 * model, booleans/enums normalized here, never patched in a model hook.
 * Authorization is handled by PatientPolicy on the route/controller.
 */
class PatientRequest extends FormRequest
{
    /**
     * The blood groups a patient record accepts — one list for the rule, the
     * web forms, Field Mode and the app (GET /api/v1/meta options).
     */
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public function authorize(): bool
    {
        return true; // controller authorizes via the Patient policy
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'dob' => ['nullable', 'date', 'before_or_equal:today'],
            'sex' => ['nullable', new Enum(PatientSex::class)],

            'phone_1' => ['nullable', 'string', 'max:32'],
            'phone_2' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:191'],
            'home_address' => ['nullable', 'string', 'max:191'],
            'district_id' => ['nullable', Rule::exists('districts', 'id')],

            'blood_type' => ['nullable', 'string', Rule::in(self::BLOOD_TYPES)],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:100'],
            'chronic_conditions' => ['nullable', 'array'],
            'chronic_conditions.*' => ['string', 'max:100'],

            'spouse_name' => ['nullable', 'string', 'max:150'],
            'father_name' => ['nullable', 'string', 'max:150'],
            'mother_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],

            'insurance_provider' => ['nullable', 'string', 'max:150'],
            'insurance_member_no' => ['nullable', 'string', 'max:100'],
            'bank_details' => ['nullable', 'string', 'max:500'],

            'consent_given' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', new Enum(PatientStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalize the checkbox to a real boolean at the boundary (B10).
        $this->merge([
            'consent_given' => $this->boolean('consent_given'),
        ]);

        foreach (['allergies', 'chronic_conditions'] as $key) {
            $val = $this->input($key);
            if (is_string($val)) {
                $this->merge([$key => array_values(array_filter(array_map('trim', explode(',', $val))))]);
            }
        }
    }
}
