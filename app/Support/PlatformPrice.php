<?php

namespace App\Support;

/**
 * A subscription price, written once and quoted in two currencies.
 *
 * Plans are stored in shillings because that is what the subscription is
 * settled in. A reader in Nairobi should see that number; a reader in Berlin
 * should see something they can judge. Both have to come from the same stored
 * figure, or the price on the pricing page and the price on the sign-up form
 * will eventually disagree — which is the one disagreement on a pricing page
 * nobody forgives.
 *
 * Deliberately NOT HospitalSettings::money(). That formats a hospital's own
 * billing currency, which is what a hospital charges its patients. What we
 * charge the hospital is a different number in a different currency, and
 * running them through the same formatter is how a Ugandan clinic's price
 * list starts rendering our subscription in dollars.
 */
final readonly class PlatformPrice
{
    private function __construct(
        /** The stored figure, in the platform's base currency. */
        public string $base,
        public string $currency,
        public float $amount,
    ) {}

    public static function make(int|float|string|null $baseAmount, ?string $currency = null): self
    {
        $base = (string) ($baseAmount ?? '0');
        $currency = strtoupper($currency ?? app(VisitorRegion::class)->currency());

        return new self(
            base: $base,
            currency: $currency,
            amount: self::convert((float) $base, $currency),
        );
    }

    private static function convert(float $base, string $currency): float
    {
        if ($currency === strtoupper((string) config('pricing.base_currency', 'UGX'))) {
            return $base;
        }

        $rate = (float) config('pricing.usd_rate', 3800);

        // A rate of zero would divide by nothing and hand the page an INF.
        return $rate > 0 ? $base / $rate : $base;
    }

    /**
     * The price as it appears on a page.
     *
     * Shillings are written whole — nobody quotes fifty thousand and twenty
     * cents. Dollars keep their cents, because they are a conversion and
     * rounding one to a tidy number would make it a different price from the
     * one we would actually charge.
     */
    public function label(): string
    {
        return $this->currency === 'USD'
            ? '$'.number_format($this->amount, 2)
            : 'USh '.number_format($this->amount, 0);
    }

    /** Just the figure, for a page that draws its own currency mark. */
    public function figure(): string
    {
        return $this->currency === 'USD'
            ? number_format($this->amount, 2)
            : number_format($this->amount, 0);
    }

    public function symbol(): string
    {
        return $this->currency === 'USD' ? '$' : 'USh';
    }

    /** What a year costs, for a page that shows both cycles. */
    public function yearly(): string
    {
        $year = $this->amount * 12;

        return $this->currency === 'USD'
            ? '$'.number_format($year, 2)
            : 'USh '.number_format($year, 0);
    }

    public function isConverted(): bool
    {
        return $this->currency !== strtoupper((string) config('pricing.base_currency', 'UGX'));
    }

    /** The sentence a converted price has to carry to be honest about itself. */
    public static function conversionNote(): string
    {
        $rate = (float) config('pricing.usd_rate', 3800);

        return 'Converted at USh '.number_format($rate, 0).' to US$1. '
            .'Subscriptions are settled in Uganda shillings; your bank applies its own rate on the day.';
    }
}
