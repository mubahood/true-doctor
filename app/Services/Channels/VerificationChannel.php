<?php

namespace App\Services\Channels;

/**
 * Swappable outbound channel for SMS/USSD (Uganda-friendly gateways such as
 * Africa's Talking). Bind a concrete implementation in a service provider so
 * the provider is interchangeable without touching callers.
 */
interface VerificationChannel
{
    /** Send a short message to a +256 phone number. */
    public function send(string $phone, string $message): void;

    /** Normalise a Ugandan number to +256E.164. */
    public function normalize(string $phone): string;
}
