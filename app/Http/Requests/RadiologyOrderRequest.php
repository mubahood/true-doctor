<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiologyOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via radiology.order
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for raising a radiology order — used by this
     * request and by App\Livewire\Visits\Panels\RadiologyOrders.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'clinical_notes' => ['nullable', 'string', 'max:255'],
            'study_ids' => ['required', 'array', 'min:1'],
            'study_ids.*' => ['integer', Rule::exists('radiology_studies', 'id')->where('hospital_id', $hospitalId)],
        ];
    }
}
