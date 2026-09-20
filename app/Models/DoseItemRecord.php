<?php

namespace App\Models;

use App\Enums\DoseRecordStatus;
use App\Enums\DoseSlot;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DoseSlot $slot
 * @property DoseRecordStatus $status
 * @property \Illuminate\Support\Carbon $scheduled_date
 */
class DoseItemRecord extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'dose_item_id', 'scheduled_date', 'slot', 'status', 'administered_at', 'administered_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'slot' => DoseSlot::class,
            'status' => DoseRecordStatus::class,
            'administered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DoseItem, $this> */
    public function doseItem(): BelongsTo
    {
        return $this->belongsTo(DoseItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by');
    }
}
