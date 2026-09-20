<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Log;

/**
 * Default channel: normalises the number and logs the message. Swap for a live
 * Africa's Talking driver in production without changing any caller.
 */
class LogChannel implements VerificationChannel
{
    public function send(string $phone, string $message): void
    {
        Log::channel('stack')->info('[SMS→'.$this->normalize($phone).'] '.$message);
    }

    public function normalize(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        // 0772… → 256772… ; 772… → 256772…
        if (str_starts_with($digits, '0')) {
            $digits = '256'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '256')) {
            $digits = '256'.$digits;
        }

        return '+'.$digits;
    }
}
