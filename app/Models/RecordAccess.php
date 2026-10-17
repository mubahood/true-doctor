<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The read audit: somebody opened or printed a patient's record (roadmap 07
 * §3, docs/audit.md). Written by RecordAccessLog only; append-only.
 *
 * @property int $id
 * @property int|null $hospital_id
 * @property int|null $user_id
 * @property int $patient_id
 * @property string $action
 * @property string $channel
 * @property string|null $context
 * @property string|null $ip
 * @property string|null $device
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class RecordAccess extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'opened' => 'Opened',
        'printed' => 'Printed',
    ];

    protected $fillable = ['hospital_id', 'user_id', 'patient_id', 'action', 'channel', 'context', 'ip', 'device', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('The record-access log is append-only.');
        });
        static::deleting(function (): void {
            throw new LogicException('The record-access log is append-only.');
        });
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst($this->action);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }
}
