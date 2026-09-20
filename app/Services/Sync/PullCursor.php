<?php

namespace App\Services\Sync;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Where a device has got to in the server's change stream.
 *
 * Two decisions worth spelling out.
 *
 * **It is a revision, not a timestamp.** `updated_at` collides at second
 * resolution, and rows written inside one transaction can carry timestamps
 * that straddle another reader's cursor — so a change is skipped and, because
 * the cursor has moved past it, never sent again. Silent, permanent, and
 * invisible until somebody notices a record that never reached a device. A
 * monotonic counter handed out under a row lock cannot do that (plan §9.4).
 *
 * **It is signed.** A cursor is a client-supplied value that decides which
 * rows come back. Left as a plain integer, a device could send a cursor of 0
 * and pull the hospital's entire history, or — worse, once more entities are
 * pullable — walk another tenant's stream by guessing. Encrypting it with the
 * app key means a cursor can only be one this server issued, to this device,
 * for this hospital.
 */
final class PullCursor
{
    public function __construct(
        public readonly int $hospitalId,
        public readonly int $revision,
        public readonly string $deviceUuid,
    ) {}

    /** The opaque string a device holds. */
    public function encode(): string
    {
        return Crypt::encryptString(json_encode([
            'h' => $this->hospitalId,
            'r' => $this->revision,
            'd' => $this->deviceUuid,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Read a cursor back, refusing anything this server did not issue.
     *
     * A cursor for another hospital or another device is not an error to
     * explain — it is a claim to data the caller has no business asking for —
     * so it reads as "start from the beginning" rather than telling the caller
     * anything about what it got wrong.
     */
    public static function decode(?string $encoded, int $hospitalId, string $deviceUuid): self
    {
        if ($encoded === null || $encoded === '') {
            return new self($hospitalId, 0, $deviceUuid);
        }

        try {
            $data = json_decode(Crypt::decryptString($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return new self($hospitalId, 0, $deviceUuid);
        }

        if (! is_array($data)
            || (int) ($data['h'] ?? 0) !== $hospitalId
            || (string) ($data['d'] ?? '') !== $deviceUuid) {
            return new self($hospitalId, 0, $deviceUuid);
        }

        return new self($hospitalId, max(0, (int) ($data['r'] ?? 0)), $deviceUuid);
    }

    public function advancedTo(int $revision): self
    {
        // Never backwards: a page whose highest revision is lower than where we
        // already were would rewind the device and re-send what it has.
        return new self($this->hospitalId, max($this->revision, $revision), $this->deviceUuid);
    }
}
