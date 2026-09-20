<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Record a procedure with optional photos on the private disk. */
class TreatmentRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // caller authorizes via TreatmentRecordPolicy
    }

    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The single validation source for treatment records — used by this request
     * and by the Livewire panel (App\Livewire\Patients\Panels\Treatments).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'procedure' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visit_id' => ['nullable', 'integer', Rule::exists('visits', 'id')->where('hospital_id', $hospitalId)],
            'performed_at' => ['nullable', 'date'],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['file', 'image', 'max:8192'],
        ];
    }
}
