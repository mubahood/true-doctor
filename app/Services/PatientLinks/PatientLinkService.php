<?php

namespace App\Services\PatientLinks;

use App\Enums\SharedDocumentType;
use App\Models\Patient;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Models\PatientMessage;
use App\Models\Scopes\HospitalScope;
use App\Models\User;
use App\Services\Messaging\PatientMessenger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Patient links: a portal without passwords (roadmap 05 §4).
 *
 * A link is `/p/{token}`: 24 random characters, short enough for a text
 * message. The token is the proof we issued it, the way a signature is for
 * the invoice links, but only its SHA-256 is stored, so a copy of the
 * database opens nothing. Opening it asks for a six-digit code (sent with it)
 * or the patient's date of birth; five wrong answers lock it for good.
 *
 * It expires (7 days unless staff choose otherwise) and the clinic can
 * withdraw it at any time. Every attempt is written to `patient_link_opens`
 * with the address and browser it came from.
 */
final class PatientLinkService
{
    public const DAYS = 7;

    /** @var list<int> */
    public const DAY_CHOICES = [1, 3, 7, 14, 30];

    /** How long an unlocked link stays open in that browser. */
    public const UNLOCK_MINUTES = 30;

    public function __construct(
        private readonly SharedDocuments $documents,
        private readonly PatientMessenger $messenger,
    ) {}

    /**
     * Make a link to one document, and text it to the patient when asked
     * (their consent and the clinic's message settings still decide).
     *
     * The token and the code exist in clear only in what this returns.
     *
     * @return array{link:PatientLink, url:string, code:string, message:?PatientMessage}
     *
     * @throws ValidationException when the document is not ready to send
     */
    public function issue(SharedDocumentType $type, Model $record, ?User $by, int $days = self::DAYS, bool $send = false): array
    {
        $patient = $this->documents->patientOf($type, $record);
        $reason = $this->documents->notReady($type, $record) ?? ($patient === null ? 'This document has no patient.' : null);
        if ($reason !== null || $patient === null) {
            throw ValidationException::withMessages(['document' => $reason ?? 'This document has no patient.']);
        }

        $days = in_array($days, self::DAY_CHOICES, true) ? $days : self::DAYS;
        $token = Str::random(24);
        $code = (string) random_int(100000, 999999);

        return DB::transaction(function () use ($type, $record, $by, $days, $send, $patient, $token, $code) {
            $link = new PatientLink([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'document_type' => $type,
                'document_id' => $record->getKey(),
                'title' => $type->label(),
                'reference' => $this->documents->reference($type, $record),
                'token_hash' => self::hashToken($token),
                'code_hash' => Hash::make($code),
                'expires_at' => Carbon::now()->addDays($days),
                'created_by' => $by?->id,
            ]);
            $link->forceFill(['hospital_id' => $patient->hospital_id])->save();

            $url = self::url($token);
            $message = null;
            if ($send) {
                $message = $this->messenger->send($patient, 'document_link', [
                    'document' => Str::lower($type->label()),
                    'link' => $url,
                    'code' => $code,
                ], 'patient_link:'.$link->uuid);

                if ($message !== null) {
                    $link->forceFill(['patient_message_id' => $message->id])->save();
                }
            }

            return ['link' => $link, 'url' => $url, 'code' => $code, 'message' => $message];
        });
    }

    /** Withdraw a link: it stops working at once, whatever its expiry. */
    public function revoke(PatientLink $link, ?User $by): PatientLink
    {
        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => Carbon::now(), 'revoked_by' => $by?->id])->save();
        }

        return $link;
    }

    /** The link a token opens, in any hospital (the public page has no tenant yet). */
    public function resolve(string $token): ?PatientLink
    {
        if (! preg_match('/^[A-Za-z0-9]{24}$/', $token)) {
            return null;
        }

        return PatientLink::withoutGlobalScope(HospitalScope::class)->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Try an answer: the code or the date of birth.
     *
     * Under a row lock, so five wrong answers sent at once still count five.
     *
     * @return string one of the PatientLinkOpen events: unlocked, wrong_code, locked, refused
     */
    public function unlock(PatientLink $link, string $answer, ?string $ip, ?string $agent): string
    {
        return DB::transaction(function () use ($link, $answer, $ip, $agent) {
            /** @var PatientLink $fresh */
            $fresh = PatientLink::withoutGlobalScope(HospitalScope::class)->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isActive()) {
                $this->record($fresh, PatientLinkOpen::REFUSED, $ip, $agent);

                return PatientLinkOpen::REFUSED;
            }

            if ($this->answers($fresh, $answer)) {
                $fresh->forceFill([
                    'failed_attempts' => 0,
                    'opens_count' => $fresh->opens_count + 1,
                    'last_opened_at' => Carbon::now(),
                ])->save();
                $this->record($fresh, PatientLinkOpen::UNLOCKED, $ip, $agent);

                return PatientLinkOpen::UNLOCKED;
            }

            $tries = $fresh->failed_attempts + 1;
            $locks = $tries >= PatientLink::MAX_ATTEMPTS;
            $fresh->forceFill(['failed_attempts' => $tries, 'locked_at' => $locks ? Carbon::now() : null])->save();
            $event = $locks ? PatientLinkOpen::LOCKED : PatientLinkOpen::WRONG_CODE;
            $this->record($fresh, $event, $ip, $agent);

            return $event;
        });
    }

    /** Write one line of the open log. Append-only. */
    public function record(PatientLink $link, string $event, ?string $ip, ?string $agent): void
    {
        (new PatientLinkOpen([
            'patient_link_id' => $link->id,
            'event' => $event,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $agent !== null ? mb_substr($agent, 0, 255) : null,
        ]))->forceFill(['hospital_id' => $link->hospital_id])->save();
    }

    /** Wrong answers left before the link locks. */
    public function triesLeft(PatientLink $link): int
    {
        return max(0, PatientLink::MAX_ATTEMPTS - $link->failed_attempts);
    }

    public static function url(string $token): string
    {
        return route('patient-link.show', ['token' => $token]);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function answers(PatientLink $link, string $answer): bool
    {
        $answer = trim($answer);
        $digits = preg_replace('/\s+/', '', $answer) ?? '';

        if (preg_match('/^\d{6}$/', $digits)) {
            return Hash::check($digits, $link->code_hash);
        }

        $dob = Patient::withoutGlobalScope(HospitalScope::class)->whereKey($link->patient_id)->value('dob');
        $given = self::parseDate($answer);

        return $dob !== null && $given !== null && Carbon::parse((string) $dob)->toDateString() === $given;
    }

    /** A date of birth as people type it: 14/03/1990, 14-03-1990, 14.03.1990 or 1990-03-14. */
    private static function parseDate(string $value): ?string
    {
        $value = str_replace(['.', '-', ' '], '/', trim($value));
        foreach (['d/m/Y', 'Y/m/d', 'j/n/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
