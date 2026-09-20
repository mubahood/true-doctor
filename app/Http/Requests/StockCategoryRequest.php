<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via StockCategoryPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('stockCategory')?->id);
    }

    /**
     * The single validation source for stock categories — used by this request
     * and by the Livewire modal (App\Livewire\StockCategories\Index).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120',
                Rule::unique('stock_categories', 'name')
                    ->where(fn ($q) => $q->where('hospital_id', $hospitalId)->whereNull('deleted_at'))
                    ->ignore($ignoreId),
            ],
            'unit' => ['required', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
