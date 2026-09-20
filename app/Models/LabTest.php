<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $price
 * @property bool $is_active
 */
class LabTest extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'specimen', 'unit', 'reference_range', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
