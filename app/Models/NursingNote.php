<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Offline sync adds a device-minted `uuid` and provenance columns (plan §21).
 *
 * @property string|null $uuid
 * @property int|null $sync_revision
 * @property int|null $origin_device_id
 * @property string|null $origin_operation_id
 * @property \Illuminate\Support\Carbon|null $client_created_at
 * @property-read Admission|null $admission
 */
class NursingNote extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        // Minted by the device that recorded it, kept for life (plan §7.2).
        'uuid', 'admission_id', 'note', 'recorded_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime',
            // Offline provenance. Cast because the docblock promises a Carbon,
            // and without it every ->format() on this is a fatal.
            'client_created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The stay this was recorded on.
     *
     * Added for offline sync, which reconciles by UUID rather than by id: a
     * record captured on a device references a stay that device also knows by
     * uuid, which is the only identifier both sides agree on.
     *
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }
}
