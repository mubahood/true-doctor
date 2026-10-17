<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A coded diagnosis on a visit (roadmap 04 §1). Append-only: a change is a
 * new row that `replaces_id` the one it corrects, a removal is a new row with
 * `removed`; what is in force is every row nothing later replaces, not removed.
 * The free-text diagnosis on the visit stays as it was written.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int $visit_id
 * @property int $patient_id
 * @property string $system
 * @property string $code
 * @property string $title
 * @property bool $is_primary
 * @property string $certainty confirmed · suspected
 * @property string|null $note
 * @property int|null $replaces_id
 * @property bool $removed
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $recorded_at
 */
class VisitDiagnosis extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'visit_id', 'patient_id', 'system', 'code', 'title', 'is_primary', 'certainty', 'note', 'replaces_id', 'removed', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'removed' => 'boolean', 'recorded_at' => 'datetime'];
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
