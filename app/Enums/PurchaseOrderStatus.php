<?php

namespace App\Enums;

/**
 * A purchase order's life. Enforced by ProcurementService, never by a screen.
 *
 *   draft ─► ordered ─► part received ─► received
 *     │         │             └────────► closed (the rest will not come)
 *     └─────────┴─► cancelled (only while nothing has been received)
 *
 * "Part received" and "received" are reached by receiving goods, not chosen.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartReceived = 'part_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Ordered',
            self::PartReceived => 'Part received',
            self::Received => 'Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Badge tone for x-ui.badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Ordered => 'info',
            self::PartReceived => 'warn',
            self::Received => 'success',
            self::Closed => 'neutral',
            self::Cancelled => 'danger',
        };
    }

    /** The same tone as a badge class, for the catalogue the app reads (`/meta`). */
    public function badge(): string
    {
        return 'badge-'.$this->tone();
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Received, self::Closed, self::Cancelled], true);
    }

    /** May goods be received against an order in this state? */
    public function canReceive(): bool
    {
        return in_array($this, [self::Ordered, self::PartReceived], true);
    }

    /** @return list<self> */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::PartReceived, self::Received, self::Cancelled],
            self::PartReceived => [self::PartReceived, self::Received, self::Closed],
            self::Received, self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitionsTo(), true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
