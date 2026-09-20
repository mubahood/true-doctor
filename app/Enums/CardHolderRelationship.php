<?php

namespace App\Enums;

/**
 * Why somebody other than the person a card was issued to may spend on it.
 *
 * The same words `patient_dependents` uses, plus `employee` — a company or
 * insurer card is held by staff who are nobody's child or spouse (docs/cards.md).
 */
enum CardHolderRelationship: string
{
    case Spouse = 'spouse';
    case Child = 'child';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Ward = 'ward';
    case Employee = 'employee';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spouse => 'Spouse',
            self::Child => 'Child',
            self::Parent => 'Parent',
            self::Sibling => 'Sibling',
            self::Ward => 'Ward',
            self::Employee => 'Employee',
            self::Other => 'Other',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn (array $c, self $r) => $c + [$r->value => $r->label()], []);
    }
}
