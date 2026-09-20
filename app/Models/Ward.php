<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property bool $is_active
 * @property numeric-string $default_daily_charge the ward's STANDARD nightly rate.
 *                                                What is billed comes from the bed (`beds.daily_charge`), which a discharge
 *                                                reads; this is the figure a new bed starts from and the one an
 *                                                administrator sets when repricing the whole ward.
 */
class Ward extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'description', 'default_daily_charge', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'default_daily_charge' => 'decimal:2'];
    }

    /** @return HasMany<Bed, $this> */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }
}
