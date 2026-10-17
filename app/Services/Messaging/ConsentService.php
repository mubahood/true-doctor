<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Patient;
use App\Models\PatientConsent;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Whether a patient may be messaged, per channel and purpose (roadmap 05 §2).
 *
 * The record is append-only (`patient_consents`): the latest answer for a
 * channel and purpose is the one in force, every earlier one stays as the
 * history. Nothing is assumed: a patient with no answer is not messaged.
 */
final class ConsentService
{
    /** Methods an answer can arrive by. */
    public const METHODS = ['desk', 'form', 'reply', 'app'];

    /**
     * The answers in force, keyed "channel.purpose" (e.g. "sms.care") => true/false.
     * A key that is missing has never been asked.
     *
     * @return array<string,bool>
     */
    public function current(Patient $patient): array
    {
        $out = [];
        foreach ($this->latest($patient) as $row) {
            $out[$row->channel->value.'.'.$row->purpose->value] = $row->granted;
        }

        return $out;
    }

    public function allows(Patient $patient, MessageChannel $channel, MessagePurpose $purpose): bool
    {
        return ($this->current($patient)[$channel->value.'.'.$purpose->value] ?? false) === true;
    }

    /**
     * Record answers, writing only the ones that change what is in force, so
     * re-saving a form does not pad the history.
     *
     * @param  array<string,bool>  $answers  "channel.purpose" => yes/no
     * @return int how many answers were recorded
     */
    public function record(Patient $patient, array $answers, string $method = 'desk', ?int $by = null, ?string $note = null): int
    {
        $now = $this->current($patient);
        $method = in_array($method, self::METHODS, true) ? $method : 'desk';
        $written = 0;

        foreach ($answers as $key => $granted) {
            [$channel, $purpose] = array_pad(explode('.', (string) $key, 2), 2, null);
            $channel = MessageChannel::tryFrom((string) $channel);
            $purpose = MessagePurpose::tryFrom((string) $purpose);
            if ($channel === null || $purpose === null || ($now[$key] ?? null) === (bool) $granted) {
                continue;
            }

            // The patient's hospital, set before saving: a STOP reply arrives
            // with no hospital in context.
            (new PatientConsent([
                'patient_id' => $patient->id,
                'channel' => $channel,
                'purpose' => $purpose,
                'granted' => (bool) $granted,
                'method' => $method,
                'recorded_by' => $by,
                'note' => $note,
                'recorded_at' => Carbon::now(),
            ]))->forceFill(['hospital_id' => $patient->hospital_id])->save();
            $written++;
        }

        return $written;
    }

    /**
     * "STOP" from a phone: no more text messages to any patient on that number,
     * in any hospital, for any purpose. The person asked the number to stop,
     * whichever clinic's message they were answering.
     *
     * @return int how many patients were opted out
     */
    public function optOutPhone(string $phone, string $method = 'reply'): int
    {
        $tail = PhoneNumber::tail($phone);
        if ($tail === null) {
            return 0;
        }

        // On the digits alone: the desk types "0772 123 456" as often as "+256772123456".
        $digits = fn (string $column) => "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?";
        $patients = Patient::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->whereRaw($digits('phone_1'), ['%'.$tail])->orWhereRaw($digits('phone_2'), ['%'.$tail]))
            ->get()
            ->filter(fn (Patient $p) => PhoneNumber::tail($p->phone_1) === $tail || PhoneNumber::tail($p->phone_2) === $tail);

        foreach ($patients as $patient) {
            $this->record($patient, array_fill_keys(array_map(fn (MessagePurpose $p) => 'sms.'.$p->value, MessagePurpose::cases()), false), $method, null, 'Replied STOP');
        }

        return $patients->count();
    }

    /** @return Collection<int, PatientConsent> the newest answer per channel and purpose */
    public function latest(Patient $patient): Collection
    {
        return PatientConsent::query()->withoutGlobalScopes()
            ->where('patient_id', $patient->id)
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->get()
            ->unique(fn (PatientConsent $c) => $c->channel->value.'.'.$c->purpose->value)
            ->values();
    }

    /** @return Collection<int, PatientConsent> every answer, newest first, for the record's history */
    public function history(Patient $patient): Collection
    {
        return PatientConsent::query()->withoutGlobalScopes()->with('recordedBy:id,name')
            ->where('patient_id', $patient->id)
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->get();
    }
}
