<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LabOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via lab.order
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for raising a lab order — used by this
     * request and by App\Livewire\Visits\Panels\LabOrders.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'clinical_notes' => ['nullable', 'string', 'max:255'],
            'test_ids' => ['required', 'array', 'min:1'],
            'test_ids.*' => ['integer', Rule::exists('lab_tests', 'id')->where('hospital_id', $hospitalId)],
        ];
    }
}
