<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weight, length/height or MUAC taken at a child clinic. Append-only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $patient_id
 * @property int|null $visit_id
 * @property \Illuminate\Support\Carbon $measured_on
 * @property string|null $weight
 * @property string|null $height
 * @property string|null $muac
 * @property bool $oedema
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class GrowthMeasurement extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'patient_id', 'visit_id', 'measured_on', 'weight', 'height', 'muac', 'oedema', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'weight' => 'decimal:2',
            'height' => 'decimal:1',
            'muac' => 'decimal:1',
            'oedema' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
