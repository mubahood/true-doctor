<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for one visit state change. Never updated.
 *
 * The status columns hold PLAIN STRINGS, not a cast enum, on purpose. Rows
 * written before status and stage were separated say things like
 * `triage → consultation`, and those words are a true record of what happened;
 * rewriting them to fit today's vocabulary would be falsifying an audit trail,
 * and casting them would simply crash on reading one. `labelFor()` knows both
 * vocabularies, so an old trail keeps reading correctly.
 *
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $from_stage
 * @property string|null $to_stage
 * @property bool $is_override
 */
class VisitStatusHistory extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'visit_id', 'from_status', 'to_status', 'from_stage', 'to_stage',
        'note', 'is_override', 'changed_by', 'created_at',
    ];

    /**
     * Every word this table has ever held, old vocabulary and new.
     *
     * The four names on the left of the old pipeline all meant "the patient is
     * here and we are dealing with them"; they keep their own labels here
     * because that is what the row says happened on the day.
     */
    private const LABELS = [
        // Before status and stage were separated.
        'registration' => 'Registration',
        'triage' => 'Vitals',
        'consultation' => 'With doctor',
        'orders' => 'Orders',
        // Both vocabularies share these three words, with the same meaning.
        'billing' => 'Billing',
        'payment' => 'Payment',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        // Since the split.
        'pending' => 'Pending',
        'ongoing' => 'Ongoing',
        'closed' => 'Completed',
    ];

    protected function casts(): array
    {
        return [
            'is_override' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** Whichever of the two dimensions this row is about, said in words. */
    public function fromLabel(): string
    {
        return self::labelFor($this->from_stage ?? $this->from_status) ?? 'Opened';
    }

    public function toLabel(): string
    {
        return self::labelFor($this->to_stage ?? $this->to_status) ?? '—';
    }

    public static function labelFor(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::LABELS[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
