<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * The default driver: no credentials needed, nothing leaves the server. It
 * records that a push would have gone (never its text) and drops it, so every
 * edition behaves the same and the phone still has polling plus its own
 * local reminders.
 */
final class NullPushTransport implements PushTransport
{
    public function send(string $token, string $platform, array $payload): bool
    {
        Log::debug('push.null: dropped', ['platform' => $platform, 'category' => $payload['category'] ?? null]);

        return false;
    }

    public function delivers(): bool
    {
        return false;
    }
}
