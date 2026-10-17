<?php

namespace App\Models;

use App\Enums\DataRequestKind;
use App\Enums\DataRequestStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request from a patient about their personal data (docs/compliance/data-subject-rights.md).
 * Changed only by App\Services\Compliance\DataRequestService: logged once, closed once.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int|null $patient_id
 * @property string $requester_name
 * @property string|null $requester_contact
 * @property DataRequestKind $kind
 * @property DataRequestStatus $status
 * @property string|null $details
 * @property \Illuminate\Support\Carbon $received_on
 * @property \Illuminate\Support\Carbon $due_on
 * @property string|null $outcome
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property int|null $closed_by
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class DataRequest extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'uuid', 'patient_id', 'requester_name', 'requester_contact', 'kind', 'status',
        'details', 'received_on', 'due_on', 'outcome', 'closed_at', 'closed_by', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DataRequestKind::class,
            'status' => DataRequestStatus::class,
            'received_on' => 'date',
            'due_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === DataRequestStatus::Open && $this->due_on->lt(today());
    }

    /** Every activity row is stamped with the tenant (activity_log.hospital_id). */
    public function tapActivity(\Spatie\Activitylog\Models\Activity $activity, string $eventName): void
    {
        $activity->setAttribute('hospital_id', $this->hospital_id);
    }
}
