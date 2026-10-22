<?php

namespace App\Models;

use App\Enums\DentalFinding;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finding on one tooth (and surfaces) at one moment. Append-only: a
 * correction is a new entry, never an edit, so the chart of any earlier date
 * can be shown as it was.
 *
 * @property int $id
 * @property string $uuid
 * @property int $patient_id
 * @property int|null $visit_id
 * @property int|null $order_id the completed order it came from, if any
 * @property int $tooth
 * @property string|null $surfaces
 * @property DentalFinding $finding
 * @property int|null $mobility
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property int|null $sync_revision
 * @property int|null $origin_device_id
 * @property string|null $origin_operation_id
 * @property \Illuminate\Support\Carbon|null $client_created_at
 */
class DentalChartEntry extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'patient_id', 'visit_id', 'order_id', 'tooth', 'surfaces', 'finding', 'mobility', 'notes', 'recorded_by', 'recorded_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'finding' => DentalFinding::class,
            'tooth' => 'integer',
            'mobility' => 'integer',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
            'client_created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
