<?php

namespace App\Enums;

/**
 * What a message to a patient is for, which decides the consent it needs.
 * Care: their own appointments, reminders, receipts, results and recalls.
 * Marketing: anything else (news, offers, greetings), which needs its own yes.
 */
enum MessagePurpose: string
{
    case Care = 'care';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Care => 'About their care',
            self::Marketing => 'News and offers',
        };
    }
}
