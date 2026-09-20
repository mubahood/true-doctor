<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BedTransfer extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'admission_id', 'from_bed_id', 'to_bed_id', 'reason', 'transferred_by', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<Bed, $this> */
    public function fromBed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'from_bed_id');
    }

    /** @return BelongsTo<Bed, $this> */
    public function toBed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'to_bed_id');
    }
}
