<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update a price-list service. Name unique per hospital. */
class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via ServicePolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('service')?->id);
    }

    /**
     * The single validation source for services — shared by this request and
     * the Livewire modal (App\Livewire\Services\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120',
                Rule::unique('services', 'name')
                    ->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))
                    ->ignore($ignoreId),
            ],
            'code' => ['nullable', 'string', 'max:32'],
            // The specialty it is listed under, and the income it earns into
            // (docs/finance.md) — this hospital's own income accounts only.
            'category' => ['nullable', 'string', 'max:60'],
            'ledger_account_id' => ['nullable', 'integer',
                Rule::exists('ledger_accounts', 'id')->where(fn ($q) => $q->where('hospital_id', $hospitalId)->where('type', 'income')),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // The top of a price range ("50,000 – 80,000"); empty for a fixed price.
            'max_price' => ['nullable', 'numeric', 'gt:price', 'max:99999999.99'],
            'tax_exempt' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tax_exempt' => $this->boolean('tax_exempt'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
