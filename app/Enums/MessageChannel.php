<?php

namespace App\Enums;

/** How a message reaches a patient. WhatsApp joins SMS when its driver lands (roadmap 05 §1). */
enum MessageChannel: string
{
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
        };
    }
}
