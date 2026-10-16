<?php

namespace App\Enums;

/** What a dentist records about a tooth or its surfaces. */
enum DentalFinding: string
{
    case Sound = 'sound';
    case Caries = 'caries';
    case Filled = 'filled';
    case Missing = 'missing';
    case ToExtract = 'to_extract';
    case Crown = 'crown';
    case Bridge = 'bridge';
    case Implant = 'implant';
    case RootCanal = 'root_canal';
    case Fracture = 'fracture';
    case Mobility = 'mobility';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Sound => 'Sound',
            self::Caries => 'Caries',
            self::Filled => 'Filled',
            self::Missing => 'Missing',
            self::ToExtract => 'To extract',
            self::Crown => 'Crown',
            self::Bridge => 'Bridge',
            self::Implant => 'Implant',
            self::RootCanal => 'Root canal',
            self::Fracture => 'Fracture',
            self::Mobility => 'Mobility',
            self::Note => 'Note',
        };
    }

    /** Badge tone, as the web and the app draw it. */
    public function tone(): string
    {
        return match ($this) {
            self::Sound => 'success',
            self::Caries, self::Fracture, self::ToExtract => 'danger',
            self::Missing => 'neutral',
            self::Mobility => 'warn',
            default => 'info',
        };
    }

    /** Whether the finding is about the whole tooth (surfaces are ignored). */
    public function wholeTooth(): bool
    {
        return in_array($this, [self::Missing, self::ToExtract, self::Crown, self::Bridge, self::Implant, self::RootCanal, self::Mobility, self::Sound], true);
    }
}
