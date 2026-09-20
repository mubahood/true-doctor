<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that has been trusted to hold patient data offline.
 *
 * Registration is explicit and revocable (docs/OFFLINE_FIRST_IMPLEMENTATION_PLAN.md §12):
 * a hospital has to be able to see the list of machines carrying a copy of its
 * records and take one off it — a lost laptop, a leaver, a shared desk that
 * should never have been enabled.
 *
 * A revoked device is never deleted. The sync operations it submitted still
 * point at it, and an audit trail that loses the answer to "which machine was
 * that?" is not one.
 *
 * @property \Illuminate\Support\Carbon|null $registered_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string $device_uuid
 * @property string $label
 * @property string|null $pull_cursor
 * @property string|null $revoked_reason
 * @property-read User|null $user
 */
class Device extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'hospital_id', 'user_id', 'device_uuid', 'label', 'platform',
        'registered_at', 'last_seen_at', 'last_sync_at',
        'revoked_at', 'revoked_by', 'revoked_reason',
        'pull_cursor', 'protocol_version',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
            'protocol_version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function getRouteKeyName(): string
    {
        return 'device_uuid';
    }
}
