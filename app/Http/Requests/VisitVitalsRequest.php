<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Triage vitals. BMI is not accepted from the client — the service computes it. */
class VisitVitalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via VisitPolicy@recordVitals
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for triage vitals — used by this request and
     * by the Livewire panel (App\Livewire\Visits\Panels\Vitals).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        return [
            'temperature' => ['nullable', 'numeric', 'between:25,45'],
            'weight' => ['nullable', 'numeric', 'between:0.5,500'],
            'height' => ['nullable', 'numeric', 'between:20,260'],
            'pulse' => ['nullable', 'integer', 'between:20,300'],
            'respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'spo2' => ['nullable', 'integer', 'between:50,100'],
            'blood_pressure' => ['nullable', 'string', 'max:12', 'regex:/^\d{2,3}\/\d{2,3}$/'],
        ];
    }
}
