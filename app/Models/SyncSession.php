<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One push or pull round, for the diagnostics screen and for answering
 *  "what did that device do last Tuesday". */
class SyncSession extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'session_uuid', 'hospital_id', 'user_id', 'device_id', 'kind',
        'operation_count', 'accepted_count', 'rejected_count',
        'conflict_count', 'duplicate_count', 'duration_ms', 'client_protocol',
    ];

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
