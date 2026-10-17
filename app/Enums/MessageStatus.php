<?php

namespace App\Enums;

/** A patient message's life: queued (perhaps until quiet hours end) → sending → sent → delivered, or failed; skipped never left. */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to send',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Skipped => 'Not sent',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'neutral',
            self::Sending => 'neutral',
            self::Sent => 'info',
            self::Delivered => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warn',
        };
    }
}
