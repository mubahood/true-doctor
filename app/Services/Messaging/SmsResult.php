<?php

namespace App\Services\Messaging;

/** What the gateway said about one message. */
final readonly class SmsResult
{
    public function __construct(
        public bool $ok,
        public ?string $providerId = null,
        public ?string $cost = null,
        public ?string $error = null,
        /** Worth trying again later (the gateway or the network failed, not the number). */
        public bool $retryable = false,
    ) {}

    public static function sent(?string $providerId = null, ?string $cost = null): self
    {
        return new self(true, $providerId, $cost);
    }

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(false, null, null, $error, $retryable);
    }
}
