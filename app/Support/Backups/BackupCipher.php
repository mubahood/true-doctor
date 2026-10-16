<?php

namespace App\Support\Backups;

/**
 * Encrypts a backup file before it leaves the server, and decrypts it for a
 * restore. libsodium's secretstream (XChaCha20-Poly1305), a chunk at a time,
 * so a large backup never has to fit in memory and a truncated or altered
 * file is refused rather than half-restored.
 *
 * The key is BACKUP_ENCRYPTION_KEY (32 bytes, base64), kept apart from the
 * server: whoever holds a copy of the backups without it holds nothing.
 */
final class BackupCipher
{
    private const MAGIC = "TDBAK1\n";

    private const CHUNK = 1 << 20;

    public static function newKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public static function key(): ?string
    {
        $raw = base64_decode((string) config('backup.encryption_key'), true);

        return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES ? $raw : null;
    }

    public static function encrypt(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new \RuntimeException('The backup could not be opened for encryption.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($dst, self::MAGIC.$header);
            do {
                $chunk = (string) fread($src, self::CHUNK);
                $last = feof($src);
                $tag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                fwrite($dst, pack('N', strlen($sealed)).$sealed);
            } while (! $last);
        } finally {
            fclose($src);
            fclose($dst);
        }
        chmod($out, 0600);
    }

    public static function decrypt(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new \RuntimeException('The backup could not be opened for decryption.');
        }

        try {
            if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new \RuntimeException('This is not a True-Doctor encrypted backup.');
            }
            $header = (string) fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finished = false;
            while (! $finished) {
                $length = fread($src, 4);
                if ($length === false || strlen($length) !== 4) {
                    throw new \RuntimeException('The backup ends early: it is incomplete.');
                }
                $expected = (int) unpack('N', $length)[1];
                $sealed = (string) fread($src, $expected);
                if (strlen($sealed) !== $expected) {
                    throw new \RuntimeException('The backup ends early: it is incomplete.');
                }
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $sealed);
                if ($opened === false) {
                    throw new \RuntimeException('The backup does not open with this key, or it was altered.');
                }
                [$plain, $tag] = $opened;
                fwrite($dst, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
        chmod($out, 0600);
    }
}
