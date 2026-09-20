<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A purchase / goods-in receipt. The rule set is exposed as a static so
 * App\Livewire\Stock\Show validates against exactly the same source
 * (plan §4.5 — "Validation rules live in the FormRequest only").
 */
class StockReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via StockItemPolicy@update
    }

    /** @return array<string, list<string>> */
    public static function rulesFor(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return self::rulesFor();
    }
}
