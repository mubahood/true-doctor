<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One antenatal contact. Append-only: a correction is a new contact note,
 * never an edit.
 *
 * @property int $id
 * @property string $uuid
 * @property int $pregnancy_id
 * @property int $patient_id
 * @property int|null $visit_id
 * @property \Illuminate\Support\Carbon $seen_on
 * @property int $gestation_days
 * @property int $contact_no
 * @property string|null $blood_pressure
 * @property string|null $weight
 * @property int|null $fundal_height
 * @property int|null $fetal_heart_rate
 * @property list<string>|null $checks
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class AncContact extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'pregnancy_id', 'patient_id', 'visit_id', 'seen_on', 'gestation_days', 'contact_no',
        'blood_pressure', 'weight', 'fundal_height', 'fetal_heart_rate', 'checks', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'seen_on' => 'date',
            'weight' => 'decimal:2',
            'gestation_days' => 'integer',
            'contact_no' => 'integer',
            'fundal_height' => 'integer',
            'fetal_heart_rate' => 'integer',
            'checks' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Pregnancy, $this> */
    public function pregnancy(): BelongsTo
    {
        return $this->belongsTo(Pregnancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
