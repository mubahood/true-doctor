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
    /**
     * Whether this payment's proof lives outside the system. Cash is its own
     * receipt; a transfer, a mobile-money payment or an insurance settlement
     * is somebody else's record, and without its reference there is no way
     * back to it when the figures are questioned.
     */
    public function needsReference(): bool
    {
        return in_array($this, [self::MobileMoney, self::Bank, self::Insurance], true);
    }

    /** @return list<self> the methods a person records by hand (a gateway reports its own) */
    public static function recordable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $m) => ! $m->isGateway()));
    }

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
