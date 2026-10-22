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

    /** The letters a printed chart writes in the tooth's box, as on a paper case sheet. */
    public function code(): string
    {
        return match ($this) {
            self::Sound => '',
            self::Caries => 'C',
            self::Filled => 'F',
            self::Missing => 'M',
            self::ToExtract => 'X',
            self::Crown => 'Cr',
            self::Bridge => 'Br',
            self::Implant => 'Im',
            self::RootCanal => 'RC',
            self::Fracture => 'Fr',
            self::Mobility => 'Mo',
            self::Note => 'N',
        };
    }

    /** Whether the finding is about the whole tooth (surfaces are ignored). */
    public function wholeTooth(): bool
    {
        return in_array($this, [self::Missing, self::ToExtract, self::Crown, self::Bridge, self::Implant, self::RootCanal, self::Mobility, self::Sound], true);
    }

    /**
     * What a finished procedure can leave on the tooth: the choices for a
     * price-list service's "Marks the tooth as".
     *
     * @return list<self>
     */
    public static function marks(): array
    {
        return [self::Filled, self::Missing, self::RootCanal, self::Crown, self::Bridge, self::Implant];
    }

    /** How the price list words a mark: an extraction leaves the tooth missing. */
    public function markLabel(): string
    {
        return $this === self::Missing ? 'Missing (extracted)' : $this->label();
    }

    /**
     * The mark a dental service most likely leaves, guessed from its name
     * ("Tooth extraction" leaves it missing, "Dental filling" filled), or
     * null when it leaves none (a check-up, scaling, an X-ray).
     */
    public static function guessFromService(string $name): ?self
    {
        $n = mb_strtolower($name);

        return match (true) {
            str_contains($n, 'root canal') => self::RootCanal,
            str_contains($n, 'extraction'), str_contains($n, 'tooth removal'), str_contains($n, 'wisdom tooth') => self::Missing,
            str_contains($n, 'crown') => self::Crown,
            str_contains($n, 'implant') => self::Implant,
            str_contains($n, 'bridge') => self::Bridge,
            str_contains($n, 'filling') => self::Filled,
            default => null,
        };
    }
}
