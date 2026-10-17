<?php

namespace App\Enums;

/** A hospital's full export: queued, building, then ready (until it expires) or failed. */
enum DataExportStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to start',
            self::Building => 'Being prepared',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Queued, self::Building => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Expired => 'neutral',
        };
    }

    public function pending(): bool
    {
        return $this === self::Queued || $this === self::Building;
    }
}
