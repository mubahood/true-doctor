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

    /** Why a message was not sent, in the words the message log shows (web and app). */
    public const SKIP_REASONS = [
        'no_consent' => 'The patient has not agreed to texts',
        'no_phone' => 'No mobile number on the record',
        'no_credit' => 'No messages left',
        'event_off' => 'This message is switched off',
    ];

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

    /**
     * This month's messages by status, for the figures at the top of Settings,
     * Messages. Nothing without a hospital: with no tenant resolved the scope
     * filters nothing, and a platform account would count every clinic's.
     *
     * @return array<string,int> status => count
     */
    public static function monthByStatus(?int $hospitalId): array
    {
        if ($hospitalId === null) {
            return [];
        }

        return self::query()->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)->all();
    }

    public function skipReasonLabel(): ?string
    {
        return $this->skip_reason === null ? null : (self::SKIP_REASONS[$this->skip_reason] ?? $this->skip_reason);
    }

    /**
     * The text as the message log shows it. A document link's code and the
     * link's own token are left out: a patient link keeps only their hashes
     * so nobody can read them back (PatientLinkService), and the log must not
     * become the place where they can be, on the web or in the app's copy.
     */
    public function logBody(): string
    {
        if ($this->event !== 'document_link') {
            return $this->body;
        }

        $body = (string) preg_replace('#/p/[A-Za-z0-9]{8,}#', '/p/…', $this->body);

        return (string) preg_replace('/\(code:\s*\d+\)/i', '(code: ••••••)', $body);
    }

    /** What became of it, in one line under the status: why it was not sent, the gateway's error, or when it will go. */
    public function statusNote(): ?string
    {
        return match (true) {
            $this->skip_reason !== null => $this->skipReasonLabel(),
            filled($this->error) => $this->error,
            $this->send_after !== null && $this->status === MessageStatus::Queued => 'After the quiet hours, '.$this->send_after->format('H:i'),
            default => null,
        };
    }
}
