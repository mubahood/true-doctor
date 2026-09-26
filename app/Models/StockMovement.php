<?php

namespace App\Models;

use App\Enums\StockMovementReason;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger row (no updated_at).
 *
 * @property StockMovementReason $reason
 * @property numeric-string $quantity
 * @property numeric-string $balance_after
 * @property numeric-string|null $unit_cost what one unit had cost, at the time
 * @property numeric-string|null $unit_price what one unit was sold for, at the time
 */
class StockMovement extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'stock_item_id', 'reason', 'quantity', 'balance_after', 'unit_cost', 'unit_price',
        'source_type', 'source_id', 'note', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => StockMovementReason::class,
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Who moved it.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** What this movement cost the store, or null where it was never recorded. */
    public function costValue(): ?string
    {
        return $this->unit_cost === null ? null : bcmul((string) $this->quantity, (string) $this->unit_cost, 2);
    }

    /** What it was worth at the selling price, or null where that is unknown. */
    public function saleValue(): ?string
    {
        return $this->unit_price === null ? null : bcmul((string) $this->quantity, (string) $this->unit_price, 2);
    }

    /**
     * What the store made on it — only meaningful for stock that was SOLD.
     *
     * A write-off has no margin, it has a loss, and calling the two the same
     * number with a minus sign in front is how a report comes to say a store
     * had a good month because it threw a lot away.
     */
    public function margin(): ?string
    {
        if ($this->reason !== StockMovementReason::Dispensed) {
            return null;
        }

        $cost = $this->costValue();
        $sale = $this->saleValue();

        return $cost === null || $sale === null ? null : bcsub($sale, $cost, 2);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
