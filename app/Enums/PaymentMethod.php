<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';            // patient prepaid card (debits the card ledger)
    case MobileMoney = 'mobile_money';
    case Bank = 'bank';
    case Flutterwave = 'flutterwave'; // online payment via the Flutterwave gateway
    case Insurance = 'insurance';     // settled by an approved insurance claim

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Prepaid card',
            self::MobileMoney => 'Mobile money',
            self::Bank => 'Bank transfer',
            self::Flutterwave => 'Online (Flutterwave)',
            self::Insurance => 'Insurance',
        };
    }

    /** Settled by an external gateway (recorded server-side after verification). */
    public function isGateway(): bool
    {
        return $this === self::Flutterwave;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
