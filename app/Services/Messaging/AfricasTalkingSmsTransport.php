<?php

namespace App\Services\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Africa's Talking bulk SMS (version1/messaging). One recipient per call, so
 * each message carries its own id, status and cost back to its own row.
 *
 * Recipient status codes: 100 processed, 101 sent, 102 queued are accepted;
 * 405 (our balance), 500, 501 (theirs) are worth retrying; the rest (an
 * invalid or blacklisted number, a refused sender id) are final.
 */
final class AfricasTalkingSmsTransport implements SmsTransport
{
    private const ACCEPTED = [100, 101, 102];

    private const RETRYABLE = [405, 500, 501, 502];

    public function __construct(
        private readonly string $username,
        private readonly string $apiKey,
        private readonly ?string $senderId = null,
        private readonly bool $sandbox = false,
    ) {}

    public function name(): string
    {
        return 'africastalking';
    }

    public function live(): bool
    {
        return ! $this->sandbox;
    }

    public function send(string $to, string $body): SmsResult
    {
        $url = $this->sandbox
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        try {
            $response = Http::asForm()
                ->withHeaders(['apiKey' => $this->apiKey, 'Accept' => 'application/json'])
                ->timeout(15)
                ->post($url, array_filter([
                    'username' => $this->username,
                    'to' => $to,
                    'message' => $body,
                    'from' => $this->senderId ?: null,
                ]));
        } catch (ConnectionException $e) {
            return SmsResult::failed('No answer from the SMS gateway: '.$e->getMessage(), true);
        }

        if (! $response->successful()) {
            return SmsResult::failed('The SMS gateway answered '.$response->status().'.', $response->serverError() || $response->status() === 429);
        }

        $recipient = $response->json('SMSMessageData.Recipients.0');
        if (! is_array($recipient)) {
            return SmsResult::failed((string) ($response->json('SMSMessageData.Message') ?: 'The gateway did not take the message.'));
        }

        $code = (int) ($recipient['statusCode'] ?? 0);
        if (in_array($code, self::ACCEPTED, true)) {
            return SmsResult::sent(
                isset($recipient['messageId']) ? (string) $recipient['messageId'] : null,
                isset($recipient['cost']) ? (string) $recipient['cost'] : null,
            );
        }

        return SmsResult::failed((string) ($recipient['status'] ?? 'Refused').' ('.$code.')', in_array($code, self::RETRYABLE, true));
    }
}
