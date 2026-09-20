<?php

namespace App\Models;

use App\Enums\BedStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property BedStatus $status
 * @property numeric-string $daily_charge
 * @property bool $is_active
 */
class Bed extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = ['ward_id', 'name', 'daily_charge', 'status', 'is_active'];

    protected function casts(): array
    {
        return ['status' => BedStatus::class, 'daily_charge' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Ward, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    /** @return HasOne<Admission, $this> */
    public function currentAdmission(): HasOne
    {
        return $this->hasOne(Admission::class)->where('status', 'admitted');
    }
}
