<?php

namespace App\Support\Practice;

/**
 * Rough rates from UGX, for turning an indicative price into one that reads as
 * a price in another currency. These are a starting point a hospital edits —
 * not exchange rates, and nothing is ever converted with them after the fact.
 */
final class PriceScale
{
    /** Multiply a UGX amount by this. Covers every currency HospitalSettings offers. */
    public const RATES = [
        'UGX' => 1.0,
        'KES' => 0.035,
        'TZS' => 0.70,
        'RWF' => 0.38,
        'SSP' => 1.20,
        'BIF' => 0.80,
        'ETB' => 0.038,
        'USD' => 0.00027,
        'EUR' => 0.00024,
        'GBP' => 0.00021,
    ];

    /** Two significant figures, and never below the smallest sensible step. */
    public static function round(float $value): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        $step = 10 ** (floor(log10($value)) - 1);

        return round(max($step, round($value / $step) * $step), 2);
    }
}
