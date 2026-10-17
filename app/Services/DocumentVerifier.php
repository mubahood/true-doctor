<?php

namespace App\Services;

use App\Models\DocumentVerification;
use App\Models\Patient;
use App\Models\Scopes\HospitalScope;
use App\Support\CurrentHospital;
use App\Support\VerificationCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * The verification QR on every printed document (roadmap 05 §4).
 *
 * Each document gets a short random code the first time it is printed on a
 * day; the QR opens `/v/{code}`, which says only that it is a genuine
 * document of this hospital, its number, the day it was printed and the
 * patient's initials. Nothing about what it says, so a code found on a
 * dropped receipt tells a stranger nothing.
 *
 * The same document printed again on the same day carries the same code
 * (the fingerprint is hospital, title, number, patient and day), so a
 * reprinted invoice does not grow the table.
 *
 * Codes are 8 characters of an alphabet with no look-alikes (32^8 ≈ 10^12),
 * random rather than sequential so they cannot be walked; the page is rate
 * limited as well.
 */
final class DocumentVerifier
{
    public const LENGTH = 8;

    public function __construct(private readonly QrService $qr) {}

    /**
     * The code, the address and the QR image for a document being printed,
     * or null when there is no hospital to vouch for it. Never throws: a
     * missing QR must not stop a receipt printing.
     *
     * @return array{code:string, display:string, url:string, qr:string}|null
     */
    public function stamp(string $title, ?string $reference, ?Patient $patient = null): ?array
    {
        $hospitalId = app(CurrentHospital::class)->id();
        if ($hospitalId === null) {
            return null;
        }

        try {
            $row = $this->rowFor($hospitalId, $title, $reference, $patient);
            $url = route('verify.show', ['code' => $row->code]);

            return [
                'code' => $row->code,
                'display' => DocumentVerification::display($row->code),
                'url' => $url,
                'qr' => $this->qr->pngDataUri($url, 140),
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The record behind a code, in any hospital, counting the check. */
    public function check(string $code): ?DocumentVerification
    {
        $code = self::normalise($code);
        if ($code === null) {
            return null;
        }

        $row = DocumentVerification::withoutGlobalScope(HospitalScope::class)->where('code', $code)->first();
        if ($row !== null) {
            DocumentVerification::withoutGlobalScope(HospitalScope::class)->whereKey($row->id)->toBase()
                ->update(['checks_count' => $row->checks_count + 1, 'last_checked_at' => Carbon::now()]);
        }

        return $row;
    }

    /** "k7qm-4xpa" or "K7QM 4XPA" → "K7QM4XPA"; null when it cannot be a code. */
    public static function normalise(string $code): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return strlen($code) === self::LENGTH && strspn($code, VerificationCode::ALPHABET) === self::LENGTH ? $code : null;
    }

    /** "John Okello" → "J. O." */
    public static function initials(?Patient $patient): ?string
    {
        if ($patient === null) {
            return null;
        }

        $parts = array_filter([mb_substr(trim((string) $patient->first_name), 0, 1), mb_substr(trim((string) $patient->last_name), 0, 1)]);

        return $parts === [] ? null : implode(' ', array_map(fn ($c) => mb_strtoupper($c).'.', $parts));
    }

    private function rowFor(int $hospitalId, string $title, ?string $reference, ?Patient $patient): DocumentVerification
    {
        $today = Carbon::now();
        $fingerprint = hash('sha256', implode('|', [$hospitalId, $title, (string) $reference, (string) $patient?->id, $today->toDateString()]));

        $existing = $this->byFingerprint($fingerprint);
        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = new DocumentVerification([
                'code' => self::randomCode(),
                'fingerprint' => $fingerprint,
                'title' => mb_substr($title, 0, 120),
                'reference' => $reference !== null ? mb_substr($reference, 0, 120) : null,
                'patient_initials' => self::initials($patient),
                'issued_at' => $today,
            ]);
            $row->forceFill(['hospital_id' => $hospitalId]);

            try {
                $row->save();

                return $row;
            } catch (QueryException) {
                // Printed a moment ago by another request, or (very rarely) a code that is taken.
                $existing = $this->byFingerprint($fingerprint);
                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        throw new \RuntimeException('Could not issue a verification code.');
    }

    /** Under the tenant scope: the hospital printing is the resolved one. */
    private function byFingerprint(string $fingerprint): ?DocumentVerification
    {
        return DocumentVerification::query()->where('fingerprint', $fingerprint)->first();
    }

    private static function randomCode(): string
    {
        $alphabet = VerificationCode::ALPHABET;
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
