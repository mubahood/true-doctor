<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a dental visit's simple notes: History and Oral examination
 * as plain text (docs/dental.md, "Simple notes"). Append-only: a change is a
 * new version; the notes in force are the highest version. Written only by
 * App\Services\DentalNotesService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int $visit_id
 * @property int $patient_id
 * @property int $version
 * @property array<string,string> $data
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class DentalNote extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'visit_id', 'patient_id', 'version', 'data', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'version' => 'integer', 'recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
