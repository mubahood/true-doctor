<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiologyStudyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via RadiologyStudyPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('radiologyStudy')?->id);
    }

    /**
     * The single validation source for radiology studies — used by this request
     * and by the Livewire modal (App\Livewire\RadiologyStudies\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120',
                Rule::unique('radiology_studies', 'name')->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))->ignore($ignoreId),
            ],
            'modality' => ['nullable', 'string', 'max:32'],
            'body_part' => ['nullable', 'string', 'max:64'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
