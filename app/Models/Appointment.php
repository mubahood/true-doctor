<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A booked appointment. State only ever changes through AppointmentService
 * (state machine + history). scheduled_at/ends_at bound the occupied slot.
 *
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property AppointmentStatus $status
 * @property AppointmentSource $source
 * @property int $duration_minutes
 * @property \Illuminate\Support\Carbon|null $checked_in_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $reason
 */
class Appointment extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_id', 'origin_visit_id', 'doctor_user_id', 'department_id', 'room_id',
        'scheduled_at', 'ends_at', 'duration_minutes', 'source', 'status', 'reason',
        'checked_in_at', 'started_at', 'completed_at', 'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_minutes' => 'integer',
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'checked_in_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasMany<AppointmentStatusHistory, $this> */
    /**
     * The visit this booking became, if it has been attended.
     *
     * One apiece — `visits.appointment_id` carries a unique index, so a
     * double check-in cannot produce two visits and two bills for one
     * attendance (see the one_visit_per_appointment migration).
     *
     * @return HasOne<Visit, $this>
     */
    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    /**
     * The visit this was arranged DURING — a follow-up said at the end of a
     * consultation. The opposite direction from `visit()` above, which is the
     * visit this booking later BECAME, and the two are easy to confuse: one
     * points backwards in time and the other forwards.
     *
     * @return BelongsTo<Visit, $this>
     */
    public function originVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'origin_visit_id');
    }

    /** Arranged in the consulting room rather than over the telephone. */
    public function isFollowUp(): bool
    {
        return $this->origin_visit_id !== null;
    }

    public function history(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->latest('created_at')->latest('id');
    }

    public function scopeForDate(Builder $q, string $date): Builder
    {
        return $q->whereDate('scheduled_at', $date);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
