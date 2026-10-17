<?php

namespace App\Models;

use App\Enums\PregnancyStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One pregnancy of one woman (roadmap 04 §4). The rules (dating, the contact
 * schedule, one open at a time) live in PregnancyService; edits are logged.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int $patient_id
 * @property \Illuminate\Support\Carbon|null $lmp
 * @property \Illuminate\Support\Carbon $edd
 * @property bool $edd_from_lmp
 * @property int $gravida
 * @property int $para
 * @property list<string>|null $risk_factors
 * @property string|null $risk_notes
 * @property string|null $birth_place
 * @property string|null $birth_companion
 * @property string|null $transport
 * @property string|null $blood_donor
 * @property string|null $birth_plan_notes
 * @property PregnancyStatus $status
 * @property \Illuminate\Support\Carbon|null $ended_on
 * @property string|null $outcome_notes
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Patient $patient
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AncContact> $contacts
 */
class Pregnancy extends Model
{
    use BelongsToHospital, LogsActivity;

    protected $fillable = [
        'uuid', 'patient_id', 'lmp', 'edd', 'edd_from_lmp', 'gravida', 'para', 'risk_factors', 'risk_notes',
        'birth_place', 'birth_companion', 'transport', 'blood_donor', 'birth_plan_notes',
        'status', 'ended_on', 'outcome_notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'lmp' => 'date',
            'edd' => 'date',
            'edd_from_lmp' => 'boolean',
            'gravida' => 'integer',
            'para' => 'integer',
            'risk_factors' => 'array',
            'status' => PregnancyStatus::class,
            'ended_on' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['lmp', 'edd', 'gravida', 'para', 'risk_factors', 'birth_place', 'status', 'ended_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('pregnancy');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return HasMany<AncContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(AncContact::class)->orderBy('seen_on')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
