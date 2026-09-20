<?php

namespace App\Http\Requests;

use App\Enums\AdmissionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DischargeRequest extends FormRequest
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
     * One validation source for closing an admission — used by this request and
     * by the discharge slide-over (App\Livewire\Admissions\Show).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $outcomes = array_map(fn (AdmissionStatus $s) => $s->value, AdmissionStatus::outcomes());

        return [
            'outcome' => ['required', Rule::in($outcomes)],
            'discharge_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function outcome(): AdmissionStatus
    {
        return AdmissionStatus::from($this->validated('outcome'));
    }
}
