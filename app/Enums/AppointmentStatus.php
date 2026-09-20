<?php

namespace App\Enums;

/**
 * Appointment lifecycle (HMS_PLAN.md §4). The allowed transitions are the
 * single source of truth for the state machine in AppointmentService — nothing
 * moves an appointment except transition(), which consults transitionsTo().
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::NoShow => 'No-show',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Scheduled => 'badge-info',
            self::Confirmed => 'badge-info',
            self::CheckedIn => 'badge-warn',
            self::InProgress => 'badge-warn',
            self::Completed => 'badge-success',
            self::NoShow => 'badge-danger',
            self::Cancelled => 'badge-danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::NoShow, self::Cancelled], true);
    }

    /** A booking that still occupies the doctor's/room's time (blocks overlaps). */
    public function occupiesSlot(): bool
    {
        return ! in_array($this, [self::Cancelled, self::NoShow], true);
    }

    /** @return list<self> states reachable from this one */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Scheduled => [self::Confirmed, self::CheckedIn, self::Cancelled, self::NoShow],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::InProgress, self::Cancelled, self::NoShow],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::NoShow, self::Cancelled => [],
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
