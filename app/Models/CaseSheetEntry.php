<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of one section of a visit's case sheet (the presenting
 * complaint, the extra-oral examination...). Append-only: a change is a new
 * version; the section in force is the highest version. Written only by
 * App\Services\CaseSheetService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int $visit_id
 * @property int $patient_id
 * @property string $template
 * @property string $section
 * @property int $version
 * @property array<string,mixed> $data
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class CaseSheetEntry extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'visit_id', 'patient_id', 'template', 'section', 'version', 'data', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer', 'recorded_at' => 'datetime'];
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
