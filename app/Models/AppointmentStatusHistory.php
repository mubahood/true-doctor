<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for one appointment state change. Never updated.
 *
 * @property AppointmentStatus|null $from_status
 * @property AppointmentStatus $to_status
 */
class AppointmentStatusHistory extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'appointment_id', 'from_status', 'to_status', 'note', 'changed_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => AppointmentStatus::class,
            'to_status' => AppointmentStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
