<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $unit_price
 * @property numeric-string $line_total
 */
class DispensationItem extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'dispensation_id', 'stock_item_id', 'name', 'quantity', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Dispensation, $this> */
    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class);
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
