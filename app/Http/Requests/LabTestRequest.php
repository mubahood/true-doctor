<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LabTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via LabTestPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('labTest')?->id);
    }

    /**
     * The single validation source for lab tests — used by this request and by
     * the Livewire modal (App\Livewire\LabTests\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120',
                Rule::unique('lab_tests', 'name')->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))->ignore($ignoreId),
            ],
            'code' => ['nullable', 'string', 'max:32'],
            'specimen' => ['nullable', 'string', 'max:64'],
            'unit' => ['nullable', 'string', 'max:32'],
            'reference_range' => ['nullable', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
