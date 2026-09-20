<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A write the server refused because somebody else had already changed the
 * record.
 *
 * Only the field NAMES in dispute are stored, not their values — enough for a
 * person to see what needs a decision, without filing the clinical content in
 * a second place (plan §20).
 *
 * @property array<int,string>|null $contested_fields
 * @property array<int,string>|null $merged_fields
 */
class SyncConflict extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'hospital_id', 'device_id', 'user_id', 'operation_id', 'entity', 'entity_uuid',
        'strategy', 'base_version', 'server_version', 'contested_fields', 'merged_fields',
        'resolution', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'contested_fields' => 'array',
            'merged_fields' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
