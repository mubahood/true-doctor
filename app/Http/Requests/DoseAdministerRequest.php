<?php

namespace App\Http\Requests;

use App\Enums\DoseRecordStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mark a scheduled dose administered or missed. */
class DoseAdministerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via PrescriptionPolicy@administer
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for marking a dose — used by this request and
     * by App\Livewire\Visits\Panels\Prescriptions.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'status' => ['required', Rule::in([DoseRecordStatus::Administered->value, DoseRecordStatus::Missed->value])],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function status(): DoseRecordStatus
    {
        return DoseRecordStatus::from($this->validated('status'));
    }
}
