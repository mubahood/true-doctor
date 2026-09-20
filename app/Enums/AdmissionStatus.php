<?php

namespace App\Enums;

/**
 * Inpatient admission lifecycle (HMS_PLAN.md §4). A bed transfer is a separate
 * event, not a status change — the patient stays "admitted" across transfers.
 */
enum AdmissionStatus: string
{
    case Admitted = 'admitted';
    case Discharged = 'discharged';
    case Deceased = 'deceased';
    case Absconded = 'absconded';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Admitted => 'badge-warn',
            self::Discharged => 'badge-success',
            self::Deceased, self::Absconded => 'badge-danger',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Admitted;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Admitted;
    }

    /** Terminal outcomes an admission can be closed with. */
    public static function outcomes(): array
    {
        return [self::Discharged, self::Deceased, self::Absconded];
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
