<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stocked item. current_quantity / current_stock_value are maintained only by
 * StockService (never a boot hook). Alerts derive from reorder_level & expiry.
 *
 * @property numeric-string $current_quantity
 * @property numeric-string $cost_price
 * @property numeric-string $sale_price
 * @property numeric-string $current_stock_value
 * @property numeric-string $reorder_level
 * @property \Illuminate\Support\Carbon|null $expiry_date
 */
class StockItem extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'stock_category_id', 'name', 'sku', 'unit', 'batch_no', 'expiry_date',
        'original_quantity', 'current_quantity', 'cost_price', 'sale_price',
        'current_stock_value', 'reorder_level', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'original_quantity' => 'decimal:2', 'current_quantity' => 'decimal:2',
            'cost_price' => 'decimal:2', 'sale_price' => 'decimal:2',
            'current_stock_value' => 'decimal:2', 'reorder_level' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<StockCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StockCategory::class, 'stock_category_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('created_at')->latest('id');
    }

    public function isLowStock(): bool
    {
        return bccomp((string) $this->current_quantity, (string) $this->reorder_level, 2) <= 0;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /**
     * What "running out" and "expiring soon" MEAN, as plain conditions.
     *
     * The scopes below are these, and so is every `withCount` closure that
     * counts them — a count that used its own threshold would let the alerts
     * page and the categories page disagree about which items are in trouble,
     * which is worse than either being wrong on its own.
     *
     * Deliberately un-generic: Eloquent hands a relation aggregate's closure a
     * builder it types only as `Builder<Model>`, and a rule about the columns
     * of one table does not need to know more than the table it is aimed at.
     *
     * @template TBuilder of Builder<covariant \Illuminate\Database\Eloquent\Model>
     *
     * @param  TBuilder  $q
     * @return TBuilder
     */
    public static function applyLowStock(Builder $q): Builder
    {
        return $q->whereColumn('current_quantity', '<=', 'reorder_level');
    }

    /**
     * @template TBuilder of Builder<covariant \Illuminate\Database\Eloquent\Model>
     *
     * @param  TBuilder  $q
     * @return TBuilder
     */
    public static function applyExpiringBefore(Builder $q, string $date): Builder
    {
        return $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $date);
    }

    /** @param Builder<StockItem> $q */
    public function scopeLowStock(Builder $q): Builder
    {
        return self::applyLowStock($q);
    }

    /** @param Builder<StockItem> $q */
    public function scopeExpiringBefore(Builder $q, string $date): Builder
    {
        return self::applyExpiringBefore($q, $date);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
