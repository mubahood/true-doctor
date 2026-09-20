<?php

namespace App\Http\Requests;

use App\Enums\MedicationAdminStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class MedicationAdministrationRequest extends FormRequest
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
     * One validation source for an MAR entry — used by this request and by the
     * lazy panel App\Livewire\Admissions\Panels\Medications (plan §4.5).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'drug_name' => ['required', 'string', 'max:120'],
            'dose' => ['nullable', 'string', 'max:60'],
            'route' => ['nullable', 'string', 'max:32'],
            'status' => ['required', new Enum(MedicationAdminStatus::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
