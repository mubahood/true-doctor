<?php

namespace App\Services\Messaging\Owner;

use App\Models\Hospital;
use App\Models\User;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\PatientMessenger;
use App\Services\Messaging\SmsTransport;
use App\Support\PhoneNumber;
use RuntimeException;

/**
 * The summary as one text message to the owner's phone, through the same
 * transport patients' messages use (the log driver until a gateway is set).
 * On the online service it costs messages from the hospital's balance like
 * any other text; a send that fails gives them back.
 */
final class SmsOwnerChannel implements OwnerChannel
{
    public function __construct(
        private readonly SmsTransport $transport,
        private readonly MessageCredits $credits,
    ) {}

    public function name(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return 'Text message';
    }

    public function address(User $owner, Hospital $hospital): ?string
    {
        return PhoneNumber::e164($owner->phone, PhoneNumber::callingCodeFor($hospital));
    }

    /** @param  list<string>  $lines */
    public function format(string $subject, array $lines, string $link): string
    {
        return trim((string) preg_replace('/\s+/', ' ', implode(' ', $lines).' '.$link));
    }

    public function send(Hospital $hospital, string $to, string $subject, string $body): ?string
    {
        $parts = PatientMessenger::segments($body);
        $metered = MessagingSettings::metered();

        if ($metered) {
            try {
                $this->credits->post($hospital->id, 'send', -$parts, 'owner-summary', null, null, 'Daily summary to the owner');
            } catch (RuntimeException) {
                return 'No messages left. Top up, or switch the summary to email.';
            }
        }

        try {
            $result = $this->transport->send($to, $body);
        } catch (\Throwable $e) {
            report($e);
            $result = \App\Services\Messaging\SmsResult::failed('The SMS gateway could not be reached.', true);
        }

        if (! $result->ok) {
            if ($metered) {
                $this->credits->post($hospital->id, 'refund', $parts, 'owner-summary', null, null, 'Daily summary not sent');
            }

            return $result->error ?? 'The text message was not sent.';
        }

        return null;
    }
}
