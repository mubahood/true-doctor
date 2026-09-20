<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per operation a device has ever submitted — the idempotency ledger.
 *
 * The whole safety property lives in the unique index on `operation_id`, not
 * here: this model is how the result is read back when a replay arrives. See
 * `App\Services\Sync\OperationLedger` for the gate itself.
 *
 * `payload_hash` is a hash, never the payload. A clinical record sitting in a
 * log table would be a second copy under nobody's governance; the hash is
 * enough to tell whether two submissions carried the same content.
 *
 * @property array<string,mixed>|null $result
 */
class SyncOperation extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'operation_id', 'hospital_id', 'user_id', 'device_id', 'sync_session_id',
        'entity', 'entity_uuid', 'operation', 'status', 'reason_code', 'message',
        'server_id', 'version', 'base_version', 'result', 'payload_hash',
        'client_created_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'client_created_at' => 'datetime',
            'processed_at' => 'datetime',
            'server_id' => 'integer',
            'version' => 'integer',
            'base_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSettled(): bool
    {
        return $this->status !== 'processing';
    }
}
