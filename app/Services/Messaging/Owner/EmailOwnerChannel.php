<?php

namespace App\Services\Messaging\Owner;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/** The summary as a short plain-text email to the owner's sign-in address. */
final class EmailOwnerChannel implements OwnerChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function address(User $owner, Hospital $hospital): ?string
    {
        $email = trim((string) $owner->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** @param  list<string>  $lines */
    public function format(string $subject, array $lines, string $link): string
    {
        return implode("\n", [...$lines, '', 'Open the dashboard: '.$link]);
    }

    public function send(Hospital $hospital, string $to, string $subject, string $body): ?string
    {
        Mail::raw($body, fn (Message $m) => $m->to($to)->subject($subject));

        return null;
    }
}
