<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a part of the patient's own history (medical, surgical,
 * financial): it belongs to the patient, not to a visit, and is confirmed at
 * each visit. Append-only: an edit is a new version; a "Still correct" is a
 * new version with the same data and `confirmed`. Written only by
 * App\Services\CaseSheetService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int $patient_id
 * @property int|null $visit_id
 * @property string $section
 * @property int $version
 * @property array<string,mixed> $data
 * @property bool $confirmed
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class PatientHistoryEntry extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'patient_id', 'visit_id', 'section', 'version', 'data', 'confirmed', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'version' => 'integer', 'confirmed' => 'boolean', 'recorded_at' => 'datetime'];
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
