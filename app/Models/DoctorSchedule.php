<?php

namespace App\Models;

use App\Enums\Weekday;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A doctor's weekly availability window. Tenant-scoped. A day can hold several
 * windows; booking picks the one that fully contains the requested time.
 *
 * @property Weekday $weekday
 * @property string $start_time
 * @property string $end_time
 * @property int $slot_minutes
 * @property bool $is_active
 */
class DoctorSchedule extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'user_id', 'room_id', 'weekday', 'start_time', 'end_time', 'slot_minutes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
            'slot_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_user_id', 'user_id');
    }
}
