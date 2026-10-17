<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to a patient (App\Services\Messaging\PatientMessenger): what
 * event it was for, to which number, what it said, and what became of it.
 * `dedupe_key` is unique per hospital, so the same event never messages twice.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int|null $patient_id
 * @property MessageChannel $channel
 * @property MessagePurpose $purpose
 * @property string $event
 * @property string $dedupe_key
 * @property string|null $to_phone
 * @property string $body
 * @property MessageStatus $status
 * @property string|null $skip_reason
 * @property string|null $provider
 * @property string|null $provider_id
 * @property int $segments
 * @property string|null $cost
 * @property string|null $error
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $send_after
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PatientMessage extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'uuid', 'patient_id', 'channel', 'purpose', 'event', 'dedupe_key', 'to_phone', 'body', 'status',
        'skip_reason', 'provider', 'provider_id', 'segments', 'cost', 'error', 'attempts', 'send_after', 'sent_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'status' => MessageStatus::class,
            'segments' => 'integer',
            'attempts' => 'integer',
            'send_after' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
