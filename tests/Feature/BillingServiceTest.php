<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function hospital(array $billing = []): Hospital
    {
        $h = Hospital::factory()->create([
            'currency' => $billing['currency_code'] ?? 'USD',
            'settings' => ['billing' => $billing],
        ]);
        app(CurrentHospital::class)->set($h->id);

        return $h;
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    public function test_line_total_is_quantity_times_price_in_bcmath(): void
    {
        $h = $this->hospital();
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Dressing', 'price' => '12.50']);

        $line = $this->billing()->orderService($c, $svc->id, 3);

        $this->assertSame('37.50', (string) $line->line_total);
        $this->assertSame('12.50', (string) $line->unit_price); // snapshot
        $this->assertSame('Dressing', $line->name);
    }

    public function test_snapshot_survives_a_later_catalogue_price_change(): void
    {
        $h = $this->hospital();
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '20.00']);
        $line = $this->billing()->orderService($c, $svc->id, 1);

        $svc->update(['price' => '999.00']); // price list changes later

        $this->assertSame('20.00', (string) $line->fresh()->unit_price); // history intact
    }

    public function test_totals_apply_configured_tax_only_to_non_exempt_lines(): void
    {
        $h = $this->hospital(['tax_enabled' => true, 'tax_rate' => 18]);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $taxed = Service::factory()->create(['hospital_id' => $h->id, 'price' => '100.00', 'tax_exempt' => false]);
        $exempt = Service::factory()->create(['hospital_id' => $h->id, 'price' => '50.00', 'tax_exempt' => true]);

        $this->billing()->orderService($c, $taxed->id, 1);   // 100, taxable
        $this->billing()->orderService($c, $exempt->id, 1);  // 50, exempt

        $t = $this->billing()->totalsFor($c->fresh());
        $this->assertSame('150.00', $t['subtotal']);
        $this->assertSame('100.00', $t['taxable']);
        $this->assertSame('18.00', $t['tax']);   // 18% of 100 only
        $this->assertSame('168.00', $t['total']);
    }

    public function test_tax_is_zero_when_disabled(): void
    {
        $h = $this->hospital(['tax_enabled' => false, 'tax_rate' => 18]);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '100.00']);
        $this->billing()->orderService($c, $svc->id, 1);

        $t = $this->billing()->totalsFor($c->fresh());
        $this->assertSame('0.00', $t['tax']);
        $this->assertSame('100.00', $t['total']);
    }

    public function test_cancelled_line_drops_out_of_totals(): void
    {
        $h = $this->hospital();
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '40.00']);
        $line = $this->billing()->orderService($c, $svc->id, 1);

        $this->billing()->cancelLine($line);

        $this->assertSame('0.00', $this->billing()->totalsFor($c->fresh())['subtotal']);
    }

    public function test_currency_formatting_is_hospital_configurable(): void
    {
        $this->hospital([
            'currency_code' => 'UGX', 'currency_symbol' => 'USh', 'currency_position' => 'before',
            'decimals' => 0, 'thousands_separator' => ',', 'decimal_separator' => '.',
        ]);
        $this->assertSame('USh1,500', app(HospitalSettings::class)->format('1500'));

        $this->hospital([
            'currency_code' => 'EUR', 'currency_symbol' => '€', 'currency_position' => 'after',
            'decimals' => 2, 'thousands_separator' => '.', 'decimal_separator' => ',',
        ]);
        $this->assertSame('1.234,50 €', app(HospitalSettings::class)->format('1234.5'));
    }
}
