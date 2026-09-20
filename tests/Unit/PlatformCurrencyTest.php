<?php

namespace Tests\Unit;

use App\Support\PlatformCurrency;
use Tests\TestCase;

/**
 * Plans are priced, quoted and charged in UGX — the currency Pesapal settles
 * in — so no conversion sits on the money path at all. The USD figure beside a
 * price is presentation only, at a fixed platform rate. All arithmetic is
 * bcmath, never float.
 */
class PlatformCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.pesapal.usd_to_ugx_rate', 3600);
    }

    public function test_it_formats_shillings_the_way_they_are_read(): void
    {
        $this->assertSame('USh 10,000', PlatformCurrency::format('10000.00'));
        $this->assertSame('USh 100,000', PlatformCurrency::format(100000));
        $this->assertSame('USh 0', PlatformCurrency::format('0'));
    }

    public function test_the_usd_reference_is_derived_at_the_configured_rate(): void
    {
        $this->assertSame('2.77', PlatformCurrency::toUsd('10000'));      // 10000 / 3600
        $this->assertSame('27.77', PlatformCurrency::toUsd('100000'));
        $this->assertSame('USh 10,000 (≈ $2.77)', PlatformCurrency::formatDual('10000'));
    }

    public function test_the_rate_is_configurable_and_only_affects_the_reference(): void
    {
        config()->set('services.pesapal.usd_to_ugx_rate', 4000);

        $this->assertSame(4000.0, PlatformCurrency::rate());
        $this->assertSame('2.50', PlatformCurrency::toUsd('10000'));
        // The shilling price itself is untouched by the rate — it is the real price.
        $this->assertSame('USh 10,000', PlatformCurrency::format('10000'));
    }

    /** Buying N months multiplies the monthly price — the only arithmetic on the money path. */
    public function test_it_multiplies_a_monthly_price_by_the_months_bought(): void
    {
        $this->assertSame('10000.00', PlatformCurrency::forMonths('10000', 1));
        $this->assertSame('30000.00', PlatformCurrency::forMonths('10000', 3));
        $this->assertSame('600000.00', PlatformCurrency::forMonths('50000', 12));
        // Never less than one month, whatever is passed in.
        $this->assertSame('10000.00', PlatformCurrency::forMonths('10000', 0));
        $this->assertSame('10000.00', PlatformCurrency::forMonths('10000', -5));
    }

    public function test_non_numeric_input_is_treated_as_zero_rather_than_blowing_up(): void
    {
        $this->assertSame('USh 0', PlatformCurrency::format('not a number'));
        $this->assertSame('0.00', PlatformCurrency::toUsd(''));
    }

    public function test_a_zero_rate_cannot_divide_by_zero(): void
    {
        config()->set('services.pesapal.usd_to_ugx_rate', 0);

        $this->assertSame('0.00', PlatformCurrency::toUsd('10000'));
    }
}
