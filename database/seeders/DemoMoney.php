<?php

namespace Database\Seeders;

/**
 * One price list, two currencies.
 *
 * Every figure in the demo seeders is written once, in shillings, because
 * that is what they were written in and rewriting them twice invites the two
 * copies to drift. A dollar tenant divides by a thousand, which lands each of
 * them on an amount a clinic anywhere would recognise: a 20,000 consultation
 * becomes a $20 one, a 30,000 bed becomes $30 a night.
 *
 * It matters more than it looks. The demo hospital's currency is a setting,
 * and a price list seeded in raw shillings into a hospital that reads dollars
 * tells anybody being shown the system that a consultation costs twenty
 * thousand of them.
 */
final class DemoMoney
{
    /** Shillings per unit of the given currency. */
    private const PER_UNIT = [
        'USD' => 1000,
        'EUR' => 1000,
        'GBP' => 1000,
        'KES' => 8,
        'TZS' => 0.7,
        'RWF' => 3,
    ];

    /** How many decimal places that currency is written to. */
    private const DECIMALS = [
        'UGX' => 0,
        'TZS' => 0,
        'RWF' => 0,
    ];

    public static function in(?string $currency, int|float $shillings): string
    {
        $divisor = self::PER_UNIT[$currency] ?? 1;
        $decimals = self::DECIMALS[$currency] ?? 2;

        // Never a thousands separator: this is a value on its way into a
        // decimal column, not a label.
        return number_format($shillings / $divisor, $decimals, '.', '');
    }

    public static function decimals(?string $currency): int
    {
        return self::DECIMALS[$currency] ?? 2;
    }
}
