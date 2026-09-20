<?php

namespace Tests\Feature;

use App\Enums\FinancialYearStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\ClosedPeriodException;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\FinancialYearService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialYearTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYearService $svc;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FinancialYearService::class);
        $this->hospital = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    public function test_create_rejects_an_overlapping_period(): void
    {
        $this->svc->create(['name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);

        $this->expectException(\RuntimeException::class);
        $this->svc->create(['name' => 'FY overlap', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
    }

    public function test_close_and_reopen(): void
    {
        $fy = $this->svc->create(['name' => 'FY 2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);

        $this->svc->close($fy);
        $this->assertSame(FinancialYearStatus::Closed, $fy->fresh()->status);
        $this->assertNotNull($fy->fresh()->closed_at);

        $this->svc->reopen($fy->fresh());
        $this->assertSame(FinancialYearStatus::Open, $fy->fresh()->status);
    }

    public function test_posting_into_a_closed_period_is_blocked(): void
    {
        // A period covering "now" that is closed → invoice generation must fail.
        $fy = $this->svc->create(['name' => 'FY now', 'starts_on' => now()->subDays(1)->toDateString(), 'ends_on' => now()->addDays(1)->toDateString()]);
        $this->svc->close($fy);

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $c = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '50.00']);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);

        $this->expectException(ClosedPeriodException::class);
        app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null);
    }

    public function test_report_rolls_up_payments_and_invoices_in_the_period(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $c = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '100.00']);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '60.00', [], null);

        $fy = $this->svc->create(['name' => 'FY report', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $report = $this->svc->report($fy);

        $this->assertSame('60.00', $report['payments_total']);
        $this->assertSame('100.00', $report['invoices_total']);
        $this->assertSame('40.00', $report['outstanding']);
        $this->assertSame('60.00', $report['by_method']['cash']);
        $this->assertSame(1, $report['invoice_count']);
    }
}
