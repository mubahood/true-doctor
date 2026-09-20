<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via WardPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('ward')?->id);
    }

    /**
     * The single validation source for wards — used by this request and by the
     * Livewire modal (App\Livewire\Wards\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120',
                Rule::unique('wards', 'name')->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            // The same bounds as `BedRequest::daily_charge`: a ward rate that
            // a bed could not legally hold would be a rate nobody can apply.
            'default_daily_charge' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
