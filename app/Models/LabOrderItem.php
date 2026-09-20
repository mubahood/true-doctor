<?php

namespace App\Models;

use App\Enums\ResultFlag;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $price
 * @property ResultFlag|null $result_flag
 *
 * Offline sync adds a device-minted `uuid`, a `version` for optimistic
 * concurrency and provenance columns (plan §21). A result is the strictest
 * entity in the system: a stale device overwriting one is a patient-safety
 * event, so the version is never merged, only compared.
 * @property string|null $uuid
 * @property int $version
 * @property int|null $sync_revision
 * @property int|null $origin_device_id
 * @property string|null $origin_operation_id
 * @property \Illuminate\Support\Carbon|null $client_created_at
 * @property \Illuminate\Support\Carbon|null $resulted_at
 * @property string|null $result_value
 * @property-read LabOrder|null $order
 */
class LabOrderItem extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        // The id both sides agree on when a result is reported from a
        // device (plan §7.2).
        'uuid',
        'lab_order_id', 'lab_test_id', 'name', 'unit', 'reference_range', 'price',
        'result_value', 'result_flag', 'result_notes', 'resulted_at', 'resulted_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'result_flag' => ResultFlag::class,
            'resulted_at' => 'datetime',
            'client_created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LabOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function hasResult(): bool
    {
        return $this->result_value !== null && $this->result_value !== '';
    }
}
