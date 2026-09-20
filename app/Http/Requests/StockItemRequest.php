<?php

namespace App\Http\Requests;

use App\Support\CurrentHospital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a stock item's static attributes. Quantities are NEVER set here —
 * stock only moves through StockService (receive/adjust); this form can't touch
 * current_quantity or current_stock_value.
 */
class StockItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via StockItemPolicy
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('stock')?->id);
    }

    /**
     * The single validation source for stock items — used by this request (API)
     * and by the Livewire modal (App\Livewire\Stock\Index), which is the only
     * write surface left for an item's catalogue metadata.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        $hospitalId = app(CurrentHospital::class)->id();

        return [
            'name' => ['required', 'string', 'max:120'],
            'stock_category_id' => ['nullable', 'integer', Rule::exists('stock_categories', 'id')->where('hospital_id', $hospitalId)],
            'sku' => ['nullable', 'string', 'max:48'],
            'unit' => ['required', 'string', 'max:32'],
            'batch_no' => ['nullable', 'string', 'max:64'],
            'expiry_date' => ['nullable', 'date'],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
