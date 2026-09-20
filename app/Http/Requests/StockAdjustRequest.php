<?php

namespace App\Http\Requests;

use App\Enums\StockMovementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An adjustment / wastage / return movement (not a purchase receipt). The rule
 * set is exposed as a static so App\Livewire\Stock\Show validates against
 * exactly the same source (plan §4.5).
 */
class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller authorizes via StockItemPolicy@update
    }

    /**
     * The four reasons a human may post by hand — dispensing and opening stock
     * are written by their own services, never from this form.
     *
     * @return list<StockMovementReason>
     */
    public static function reasons(): array
    {
        return [
            // A correction either way…
            StockMovementReason::AdjustmentIn,
            StockMovementReason::AdjustmentOut,
            StockMovementReason::ReturnedToStock,
            // …and the reasons stock actually goes missing. "Adjustment (out),
            // 400 tablets" is a number nobody can act on; expired, damaged and
            // stolen are three problems with three different fixes.
            StockMovementReason::Expired,
            StockMovementReason::Damaged,
            StockMovementReason::Lost,
            StockMovementReason::ReturnedToSupplier,
            StockMovementReason::Wastage,
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function rulesFor(): array
    {
        return [
            'reason' => ['required', Rule::in(array_map(fn (StockMovementReason $r) => $r->value, self::reasons()))],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return self::rulesFor();
    }
}
