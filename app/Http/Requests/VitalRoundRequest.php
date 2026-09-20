<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VitalRoundRequest extends FormRequest
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
     * One validation source for a vitals round — used by this request and by the
     * lazy panel App\Livewire\Admissions\Panels\VitalRounds (plan §4.5).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'temperature' => ['nullable', 'numeric', 'between:25,45'],
            'pulse' => ['nullable', 'integer', 'between:20,300'],
            'blood_pressure' => ['nullable', 'string', 'max:12', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            'respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'spo2' => ['nullable', 'integer', 'between:50,100'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
