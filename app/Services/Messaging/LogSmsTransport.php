<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Writes the message to the log instead of sending it: local, testing, and a server with no gateway. */
final class LogSmsTransport implements SmsTransport
{
    public function name(): string
    {
        return 'log';
    }

    public function live(): bool
    {
        return false;
    }

    public function send(string $to, string $body): SmsResult
    {
        Log::info('SMS (not sent: log driver)', ['to' => $to, 'body' => $body]);

        return SmsResult::sent('log-'.Str::lower(Str::random(12)));
    }
}
