<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vaccine dose given. Append-only: a wrong entry is voided with a reason
 * (ImmunisationService::void), never edited or deleted.
 *
 * @property int $id
 * @property string $uuid
 * @property int $patient_id
 * @property int|null $visit_id
 * @property string $dose_key
 * @property \Illuminate\Support\Carbon $given_on
 * @property bool $given_elsewhere
 * @property string|null $batch_no
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
class Immunisation extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'patient_id', 'visit_id', 'dose_key', 'given_on', 'given_elsewhere', 'batch_no', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'given_on' => 'date',
            'given_elsewhere' => 'boolean',
            'created_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @param Builder<Immunisation> $query */
    public function scopeStanding(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
