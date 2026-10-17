<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to open a patient link, append-only: who (the browser), from
 * where (the address), and what happened.
 *
 * @property int $id
 * @property int $hospital_id
 * @property int $patient_link_id
 * @property string $event
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class PatientLinkOpen extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    /** The page was shown (asking for the code). */
    public const VIEWED = 'viewed';

    public const UNLOCKED = 'unlocked';

    public const WRONG_CODE = 'wrong_code';

    /** The wrong code that locked it. */
    public const LOCKED = 'locked';

    /** Opened after it stopped working (expired, withdrawn or locked). */
    public const REFUSED = 'refused';

    public const DOWNLOADED = 'downloaded';

    protected $fillable = ['patient_link_id', 'event', 'ip', 'user_agent'];

    public static function eventLabel(string $event): string
    {
        return match ($event) {
            self::VIEWED => 'Link opened',
            self::UNLOCKED => 'Unlocked',
            self::WRONG_CODE => 'Wrong code',
            self::LOCKED => 'Locked after wrong codes',
            self::REFUSED => 'Refused, no longer works',
            self::DOWNLOADED => 'Document viewed',
            default => $event,
        };
    }

    /** @return BelongsTo<PatientLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(PatientLink::class, 'patient_link_id');
    }
}
