<?php

namespace App\Services\Gateway;

/** Normalized result of a server-side transaction verification. */
final class GatewayVerification
{
    public function __construct(
        public readonly bool $successful,
        public readonly string $reference,     // our tx_ref echoed back
        public readonly ?string $providerRef,  // gateway's own transaction id
        public readonly string $amount,        // scale-2 string
        public readonly string $currency,
        public readonly ?string $paymentType = null,
        public readonly ?string $error = null,
    ) {}
}
