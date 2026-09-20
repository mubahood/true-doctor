<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update an insurance provider. Name unique per hospital. */
class InsuranceProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via InsuranceProviderPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('insuranceProvider')?->id);
    }

    /**
     * The single validation source for providers — shared by this request and
     * the Livewire modal (App\Livewire\InsuranceProviders\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120',
                Rule::unique('insurance_providers', 'name')
                    ->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))
                    ->ignore($ignoreId),
            ],
            'code' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
