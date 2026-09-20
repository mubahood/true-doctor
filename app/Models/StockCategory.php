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

    /** @return HasMany<StockItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }
}
