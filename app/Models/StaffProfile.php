<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Clinical/HR extension of a staff user (one per user). Tenant-scoped; login
 * credentials stay on the User — this holds specialty, licence, qualifications,
 * signature and the weekly availability template used later by scheduling.
 *
 * @property array<string,mixed>|null $schedule
 * @property bool $is_active
 */
class StaffProfile extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'department_id', 'job_title', 'specialty',
        'license_no', 'qualifications', 'signature', 'schedule', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
