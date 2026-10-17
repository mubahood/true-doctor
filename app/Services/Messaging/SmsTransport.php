<?php

namespace App\Services\Messaging;

/**
 * Where a text message actually goes: the log (local, testing, and any server
 * with no gateway) or Africa's Talking. Chosen by `messaging.sms.driver`, so
 * going live is configuration, not code (roadmap 05 §1).
 */
interface SmsTransport
{
    /** The provider's name, written on every message it carried. */
    public function name(): string;

    /** Whether a message sent through this transport really reaches a phone. */
    public function live(): bool;

    public function send(string $to, string $body): SmsResult;
}
