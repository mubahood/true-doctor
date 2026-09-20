<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The hospital's own identity: what appears on invoices, receipts, lab reports
 * and patient ID cards. Set during onboarding and editable afterwards.
 */
class HospitalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via manage-settings
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * One source of truth for the hospital-profile rules — shared by this
     * FormRequest and the onboarding step (house rule 13).
     *
     * @return array<string,list<string>>
     */
    public static function rulesFor(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'timezone' => ['required', 'string', 'max:64'],
        ];
    }
}
