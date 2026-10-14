<?php

namespace App\Services\Install;

/** Encrypts the setup answers between short browser migration requests. */
final class InstallDraft
{
    /** @param array<string,string> $details */
    public function save(string $path, string $appKey, array $details): string
    {
        $token = bin2hex(random_bytes(32));
        $payload = json_encode(['token_hash' => hash('sha256', $token), 'details' => $details], JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($payload, $nonce, $this->key($appKey));
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, base64_encode($nonce.$encrypted), LOCK_EX) === false) {
            throw new \RuntimeException('The installation draft could not be saved.');
        }
        chmod($temporary, 0600);
        rename($temporary, $path);

        return $token;
    }

    /** @return array<string,string> */
    public function load(string $path, string $appKey, string $token): array
    {
        $encoded = is_file($path) ? file_get_contents($path) : false;
        $binary = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($binary === false || strlen($binary) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \DomainException('The installation draft is missing or damaged.');
        }
        $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key($appKey));
        $document = is_string($plaintext) ? json_decode($plaintext, true) : null;
        if (! is_array($document) || ! hash_equals((string) ($document['token_hash'] ?? ''), hash('sha256', $token))
            || ! is_array($document['details'] ?? null)) {
            throw new \DomainException('This browser cannot continue the installation.');
        }

        return $document['details'];
    }

    private function key(string $appKey): string
    {
        $decoded = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \DomainException('The installation encryption key is invalid.');
        }

        return $decoded;
    }
}
