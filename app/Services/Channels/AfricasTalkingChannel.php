<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase-2 live SMS via Africa's Talking. Wire it up by binding this in place of
 * LogChannel and setting AT_USERNAME / AT_API_KEY. Kept behind the interface so
 * the provider is swappable. Not active by default.
 */
class AfricasTalkingChannel implements VerificationChannel
{
    public function __construct(
        private readonly string $username,
        private readonly string $apiKey,
        private readonly ?string $shortcode = null,
    ) {}

    public function send(string $phone, string $message): void
    {
        try {
            Http::asForm()
                ->withHeaders(['apiKey' => $this->apiKey, 'Accept' => 'application/json'])
                ->post('https://api.africastalking.com/version1/messaging', array_filter([
                    'username' => $this->username,
                    'to' => $this->normalize($phone),
                    'message' => $message,
                    'from' => $this->shortcode,
                ]));
        } catch (\Throwable $e) {
            Log::warning('AfricasTalking send failed: '.$e->getMessage());
        }
    }

    public function normalize(string $phone): string
    {
        return (new LogChannel)->normalize($phone);
    }
}
