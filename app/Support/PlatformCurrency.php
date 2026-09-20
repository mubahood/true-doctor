<?php

namespace App\Support;

/**
 * Platform (SaaS) billing currency — distinct from App\Support\HospitalSettings,
 * which formats a *patient's* bill in the hospital's own chosen currency.
 *
 * Subscription plans are priced, quoted and charged in **UGX**: that is what
 * Pesapal settles in, so the amount a hospital is shown is exactly the amount
 * that leaves its account — no conversion sits anywhere on the money path, and
 * there is no rounding drift between the quote and the charge.
 *
 * The USD figure shown beside a price is presentation only, converted at a
 * fixed platform rate (config('services.pesapal.usd_to_ugx_rate')) rather than
 * a live forex feed: a stable reference number for anyone reading in dollars,
 * never something that is billed.
 */
final class PlatformCurrency
{
    /** The currency plans are priced, quoted and charged in. */
    public const CHARGE = 'UGX';

    /** The reference currency shown beside a price. */
    public const REFERENCE = 'USD';

    public static function rate(): float
    {
        return (float) config('services.pesapal.usd_to_ugx_rate', 3600);
    }

    /** "USh 10,000" — whole shillings; nobody prices in cents of UGX. */
    public static function format(string|int|float $ugx): string
    {
        return 'USh '.number_format((float) self::normalize($ugx), 0);
    }

    /** The reference USD value of a UGX amount, scale-2. */
    public static function toUsd(string|int|float $ugx): string
    {
        $rate = self::rate();

        return $rate > 0 ? bcdiv(self::normalize($ugx), (string) $rate, 2) : '0.00';
    }

    /** "$2.78" */
    public static function formatUsd(string|int|float $usd): string
    {
        return '$'.number_format((float) self::normalize($usd), 2);
    }

    /** "USh 10,000 (≈ $2.78)" — the pairing shown wherever there is room for both. */
    public static function formatDual(string|int|float $ugx): string
    {
        return self::format($ugx).' (≈ '.self::formatUsd(self::toUsd($ugx)).')';
    }

    /** A price for N months, scale-2 — the amount actually charged. */
    public static function forMonths(string|int|float $monthlyUgx, int $months): string
    {
        return bcmul(self::normalize($monthlyUgx), (string) max(1, $months), 2);
    }

    private static function normalize(string|int|float $value): string
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_numeric($value) ? (string) $value : '0';
    }
}
