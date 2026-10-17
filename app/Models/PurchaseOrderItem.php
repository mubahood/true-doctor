<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a purchase order: what was ordered, at what cost, and how much
 * of it has come in so far.
 *
 * @property numeric-string $quantity
 * @property numeric-string $unit_cost
 * @property numeric-string $line_total
 * @property numeric-string $received_quantity
 */
class PurchaseOrderItem extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'purchase_order_id', 'stock_item_id', 'quantity', 'unit_cost', 'line_total', 'received_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'received_quantity' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class)->withTrashed();
    }

    /** What is still to come: ordered less received, never below zero. */
    public function outstanding(): string
    {
        $left = bcsub((string) $this->quantity, (string) $this->received_quantity, 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }
}
