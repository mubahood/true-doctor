<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shelf in the store, and the unit everything on it is counted in.
 *
 * The `*_count` and sum properties below are the categories page's aggregates,
 * selected in the query rather than counted per row — what is on the shelf,
 * how much of it is running out, and what it is worth.
 *
 * @property bool $is_active
 * @property-read int|null $items_count
 * @property-read int|null $active_count
 * @property-read int|null $low_count
 * @property-read int|null $expiring_count
 * @property-read numeric-string|null $on_hand
 * @property-read numeric-string|null $stock_value
 */
class StockCategory extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'unit', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Why this category may not be deleted, or null. */
    public function deletionBlocker(): ?string
    {
        return $this->items()->exists() ? 'This category has stock items — reassign them first.' : null;
    }

    /**
     * The categories page's aggregates: what is on the shelf, how much is
     * running out or near expiry, and what it is worth.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<StockCategory>  $q
     */
    public function scopeWithShelfFigures($q, string $horizon)
    {
        return $q->withCount([
            'items',
            'items as active_count' => fn ($i) => $i->where('is_active', true),
            'items as low_count' => fn ($i) => StockItem::applyLowStock($i->where('is_active', true)),
            'items as expiring_count' => fn ($i) => StockItem::applyExpiringBefore($i->where('is_active', true), $horizon),
        ])
            ->withSum(['items as on_hand' => fn ($i) => $i->where('is_active', true)], 'current_quantity')
            ->withSum(['items as stock_value' => fn ($i) => $i->where('is_active', true)], 'current_stock_value');
    }

    /**
     * Only the shelves with something wrong on them: running out or near expiry.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<StockCategory>  $q
     */
    public function scopeNeedingAttention($q, string $horizon)
    {
        return $q->where(fn ($inner) => $inner
            ->whereHas('items', fn ($i) => StockItem::applyLowStock($i->where('is_active', true)))
            ->orWhereHas('items', fn ($i) => StockItem::applyExpiringBefore($i->where('is_active', true), $horizon)));
    }

    /** @return HasMany<StockItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }
}
