<?php

namespace App\Models;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room / visit space / ward of one hospital, optionally attached to a
 * department. Tenant-scoped; name is unique per hospital.
 *
 * @property RoomType $type
 * @property RoomStatus $status
 */
class Room extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'department_id', 'name', 'type', 'status', 'capacity', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'status' => RoomStatus::class,
            'capacity' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
