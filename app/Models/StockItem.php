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
    /** Days ahead an expiry date counts as "expiring" — the alerts page's horizon. */
    public const EXPIRY_HORIZON_DAYS = 90;

    /**
     * The stock register's filters — low, expiring, expired; a category; a
     * name. Shared by the web register and the API.
     *
     * @param  Builder<StockItem>  $q
     * @return Builder<StockItem>
     */
    public function scopeListed(Builder $q, ?string $filter, int|string|null $category, ?string $search): Builder
    {
        $search = trim((string) $search);

        return $q
            ->when($filter === 'low', fn (Builder $x) => $x->lowStock())
            ->when($filter === 'expiring', fn (Builder $x) => $x->expiringBefore(now()->addDays(self::EXPIRY_HORIZON_DAYS)->toDateString()))
            ->when($filter === 'expired', fn (Builder $x) => $x->expiringBefore(now()->toDateString()))
            ->when(($category ?? '') !== '' && (int) $category > 0, fn (Builder $x) => $x->where('stock_category_id', (int) $category))
            ->when($search !== '', fn (Builder $x) => $x->where('name', 'like', "%{$search}%"));
    }

    /**
     * What the store holds, by the same rules the alerts page uses.
     *
     * @return array{items:int,quantity:string,value:string,low:int,expiring:int,expired:int}
     */
    public static function shelf(): array
    {
        $horizon = now()->addDays(self::EXPIRY_HORIZON_DAYS)->toDateString();
        $active = fn () => self::where('is_active', true);

        return [
            'items' => $active()->count(),
            'quantity' => (string) ($active()->sum('current_quantity') ?: '0'),
            'value' => (string) ($active()->sum('current_stock_value') ?: '0'),
            'low' => self::applyLowStock($active())->count(),
            'expiring' => self::applyExpiringBefore($active(), $horizon)->count(),
            'expired' => self::applyExpiringBefore($active(), now()->toDateString())->count(),
        ];
    }

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
