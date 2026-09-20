<?php

namespace App\Services\Gateway;

/** Result of initializing a hosted payment: where to send the payer + our reference. */
final class GatewayCharge
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reference,   // our tx_ref
        public readonly ?string $link,       // hosted payment URL
        public readonly ?string $error = null,
    ) {}
}
