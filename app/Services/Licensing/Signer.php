<?php

namespace App\Services\Licensing;

use App\Support\Edition;
use App\Support\Licensing\Canonical;

final class Signer
{
    /** @param array<string,mixed> $payload
     * @return array{key_id:string,signature:string}
     */
    public function sign(array $payload): array
    {
        if (! Edition::isSaas()) {
            throw new \RuntimeException('Signing is only available on the SaaS installation.');
        }

        $keyId = config('edition.signing_key_id');
        $secret = base64_decode((string) config('edition.signing_secret'), true);
        if (! is_string($keyId) || $keyId === '' || $secret === false
            || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Release signing is not configured.');
        }

        return [
            'key_id' => $keyId,
            'signature' => base64_encode(sodium_crypto_sign_detached(Canonical::json($payload), $secret)),
        ];
    }
}
