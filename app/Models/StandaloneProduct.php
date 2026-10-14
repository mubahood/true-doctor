<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property numeric-string $price_ugx
 * @property array<string,int>|null $limits
 */
/**
 * What the self-hosted edition sells for (Super → Standalone products).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property numeric-string $price_ugx
 * @property numeric-string|null $renewal_price_ugx
 * @property int $updates_months
 * @property array<string,int>|null $limits
 * @property bool $is_active
 */
class StandaloneProduct extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'price_ugx', 'renewal_price_ugx', 'updates_months', 'limits', 'is_active'];

    protected function casts(): array
    {
        return ['price_ugx' => 'decimal:2', 'renewal_price_ugx' => 'decimal:2', 'limits' => 'array', 'is_active' => 'boolean'];
    }
}
