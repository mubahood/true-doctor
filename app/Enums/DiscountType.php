<?php

namespace App\Enums;

/**
 * How a discount was agreed — see docs/orders.md.
 *
 * The distinction is not cosmetic. A fixed amount is a number somebody
 * negotiated and it stays that number; a percentage is a rule, and it follows
 * the bill as lines are added and taken off. Storing the resolved money for
 * either would freeze a rule that was never meant to be frozen.
 */
enum DiscountType: string
{
    case Amount = 'amount';
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Amount => 'Fixed amount',
            self::Percent => 'Percentage',
        };
    }

    public function suffix(): string
    {
        return $this === self::Percent ? '%' : '';
    }

    /**
     * What this discount is worth against a given bill.
     *
     * A percentage is rounded to the money's own scale once, here, so that the
     * figure shown on the screen is the figure written on the invoice.
     */
    public function resolve(string $value, string $subtotal): string
    {
        $value = \App\Support\HospitalSettings::decimal($value, 4);

        if (bccomp($value, '0', 4) <= 0) {
            return '0.00';
        }

        $money = $this === self::Percent
            ? bcmul($subtotal, bcdiv($value, '100', 6), 2)
            : bcadd($value, '0', 2);

        // Never more than the bill: a discount larger than the charge would be
        // the hospital paying the patient.
        return bccomp($money, $subtotal, 2) > 0 ? $subtotal : $money;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $t) => $c + [$t->value => $t->label()], []);
    }
}
