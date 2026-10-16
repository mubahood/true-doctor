<?php

namespace App\Exceptions;

use RuntimeException;

class PlanLimitExceededException extends RuntimeException
{
    public static function make(string $resource, int $limit): self
    {
        $subject = \App\Support\Edition::isStandalone() ? 'licence' : 'plan';
        $action = \App\Support\Edition::isStandalone() ? 'Contact True-Doctor about changing the limit.' : 'Upgrade to add more.';

        // Who to ask is one tap away; the number comes from SupportContact, never typed here.
        $support = \App\Support\SupportContact::current();
        if ($support->hasWhatsapp()) {
            $action .= ' WhatsApp us on '.$support->whatsappDisplay().'.';
        }

        return new self("Your {$subject}'s limit for {$resource} ({$limit}) has been reached. {$action}");
    }
}
