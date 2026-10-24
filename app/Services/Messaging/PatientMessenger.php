<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use App\Jobs\SendPatientMessage;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientMessage;
use App\Services\ReminderService;
use App\Support\HospitalBrand;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Every text message to a patient goes through here (roadmap 05 §1, §2).
 *
 * The rules, in order: the hospital has messages on and this event switched
 * on; the patient said yes for this purpose on this channel; there is a
 * number to send to (a child's guardian's, when the child has none of their
 * own). Then the message is written down once per event (`dedupe_key`), held
 * until the hospital's quiet hours end, and handed to the queue, which takes
 * the credit, sends it and keeps what the gateway said.
 *
 * A message that cannot go is still written down, with the reason, so "why
 * did my patient not get a reminder?" has an answer. Only when messages are
 * off for the hospital is nothing written at all.
 */
final class PatientMessenger
{
    /** Under this age, messages go to the guardian's number when the child has none. */
    public const CHILD_UNDER = 16;

    public function __construct(
        private readonly ConsentService $consents,
        private readonly ReminderService $zones,
    ) {}

    /**
     * @param  array<string,scalar|null>  $vars  for the event's wording (lang/en/patient_messages.php)
     */
    public function send(Patient $patient, string $event, array $vars, string $dedupeKey, MessagePurpose $purpose = MessagePurpose::Care): ?PatientMessage
    {
        $hospital = Hospital::query()->find($patient->hospital_id);
        $settings = MessagingSettings::of($hospital);
        if ($hospital === null || ! $settings->smsEnabled() || ! isset(MessagingSettings::EVENTS[$event])) {
            return null;
        }

        $existing = $this->existing($hospital->id, $dedupeKey);
        if ($existing !== null) {
            return $existing;
        }

        $to = $this->recipient($patient, $hospital);
        $reason = match (true) {
            ! $settings->eventOn($event) => 'event_off',
            ! $this->consents->allows($patient, MessageChannel::Sms, $purpose) => 'no_consent',
            $to === null => 'no_phone',
            default => null,
        };

        $body = $this->body($event, $vars, $hospital);
        $message = new PatientMessage([
            'uuid' => (string) Str::uuid(),
            'patient_id' => $patient->id,
            'channel' => MessageChannel::Sms,
            'purpose' => $purpose,
            'event' => $event,
            'dedupe_key' => $dedupeKey,
            'to_phone' => $to,
            'body' => $body,
            'status' => $reason === null ? MessageStatus::Queued : MessageStatus::Skipped,
            'skip_reason' => $reason,
            'segments' => self::segments($body),
            'attempts' => 0,
            'send_after' => $reason === null ? $this->afterQuietHours($hospital, $settings) : null,
        ]);
        $message->forceFill(['hospital_id' => $hospital->id]);

        try {
            $message->save();
        } catch (QueryException $e) {
            // The same event, written a moment ago by another process.
            return $this->existing($hospital->id, $dedupeKey) ?? throw $e;
        }

        if ($message->status === MessageStatus::Queued) {
            SendPatientMessage::dispatch($message->id)->delay($message->send_after)->afterCommit();
        }

        return $message;
    }

    /**
     * send(), for the places a message hangs off other work (a booking, a
     * payment): whatever goes wrong with the message is reported, never
     * thrown, so a text can never fail the booking or the payment.
     *
     * @param  array<string,scalar|null>  $vars
     */
    public function trySend(Patient $patient, string $event, array $vars, string $dedupeKey, MessagePurpose $purpose = MessagePurpose::Care): ?PatientMessage
    {
        try {
            return $this->send($patient, $event, $vars, $dedupeKey, $purpose);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The number a message for this patient goes to, as the gateway wants it. */
    public function recipient(Patient $patient, ?Hospital $hospital): ?string
    {
        $code = PhoneNumber::callingCodeFor($hospital);
        $own = PhoneNumber::e164($patient->phone_1, $code) ?? PhoneNumber::e164($patient->phone_2, $code);
        if ($own !== null) {
            return $own;
        }

        $age = $patient->dob?->age;
        if ($age !== null && $age < self::CHILD_UNDER) {
            foreach ($patient->guardianLinks()->withoutGlobalScopes()->with(['guardian' => fn ($q) => $q->withoutGlobalScopes()])->get() as $link) {
                $phone = PhoneNumber::e164($link->guardian?->phone_1, $code);
                if ($phone !== null) {
                    return $phone;
                }
            }
        }

        return null;
    }

    /**
     * What a patient would read for each message, with this clinic's own name
     * and number and sample details (Settings, Messages, on the web and in the app).
     *
     * @return array<string,string> event => text
     */
    public function previews(Hospital $hospital): array
    {
        $samples = [
            'appointment_booked' => ['doctor' => 'Dr. Nakato', 'date' => now()->addDays(3)->format('D j M'), 'time' => '10:30'],
            'appointment_day_before' => ['time' => '10:30'],
            'receipt' => ['amount' => '20,000', 'invoice' => 'INV-2026-00123', 'link' => url('/s/r/…')],
            'dental_recall' => [],
            'follow_up_due' => [],
            'document_link' => ['document' => 'lab result', 'link' => url('/p/…'), 'code' => '482913'],
        ];

        $out = [];
        foreach ($samples as $event => $vars) {
            $out[$event] = $this->body($event, $vars, $hospital);
        }

        return $out;
    }

    /** @param  array<string,scalar|null>  $vars */
    public function body(string $event, array $vars, Hospital $hospital): string
    {
        $brand = new HospitalBrand($hospital);
        $text = (string) __('patient_messages.'.$event, array_map(fn ($v) => (string) ($v ?? ''), $vars + [
            'clinic' => $brand->name() ?? 'Your clinic',
            'clinic_phone' => $brand->contact()['phone'] ?? 'the clinic',
        ]));

        if (config('messaging.sms.reply_stop')) {
            $text .= ' '.__('patient_messages.stop');
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * How many SMS parts a text costs: 160 characters in one (153 a part when
     * longer) in the plain GSM alphabet, 70 (67) once any other character is in it.
     */
    public static function segments(string $body): int
    {
        $gsm = preg_match('/^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&\'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\[\]~|€\\\\]*$/u', $body) === 1;
        $length = mb_strlen($body);
        [$single, $part] = $gsm ? [160, 153] : [70, 67];

        return $length <= $single ? 1 : (int) ceil($length / $part);
    }

    /** Now, or the end of the hospital's quiet hours when now is inside them. */
    private function afterQuietHours(Hospital $hospital, MessagingSettings $settings): ?Carbon
    {
        [$start, $end] = $settings->quietHours();
        $tz = $this->zones->zone($hospital);
        $local = Carbon::now($tz);
        $time = $local->format('H:i');

        $quiet = $start <= $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
        if (! $quiet) {
            return null;
        }

        $at = $local->copy()->setTimeFromTimeString($end);
        if ($at->lte($local)) {
            $at->addDay();
        }

        return $at->setTimezone((string) config('app.timezone'));
    }

    private function existing(int $hospitalId, string $dedupeKey): ?PatientMessage
    {
        return PatientMessage::query()->withoutGlobalScopes()
            ->where('hospital_id', $hospitalId)->where('dedupe_key', $dedupeKey)->first();
    }
}
