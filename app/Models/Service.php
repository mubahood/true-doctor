<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A price-list entry. Prices are hospital-owned (admin-set, in the hospital's
 * configured currency); a snapshot is copied onto each ordered line.
 *
 * @property numeric-string $price
 * @property bool $tax_exempt
 * @property bool $is_active
 */
class Service extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'price', 'tax_exempt', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'tax_exempt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
