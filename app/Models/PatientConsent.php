<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer a patient gave about being messaged: on a channel, for a
 * purpose, yes or no, when, how and recorded by whom. Append-only: the latest
 * answer per channel and purpose is the one in force, and the history is the
 * record the Data Protection and Privacy Act asks a clinic to keep.
 *
 * @property int $id
 * @property int $hospital_id
 * @property int $patient_id
 * @property MessageChannel $channel
 * @property MessagePurpose $purpose
 * @property bool $granted
 * @property string $method desk · form · reply · app
 * @property int|null $recorded_by
 * @property string|null $note
 * @property \Illuminate\Support\Carbon $recorded_at
 */
class PatientConsent extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['patient_id', 'channel', 'purpose', 'granted', 'method', 'recorded_by', 'note', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'granted' => 'boolean',
            'recorded_at' => 'datetime',
        ];
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
