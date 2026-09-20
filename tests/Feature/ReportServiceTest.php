<?php

namespace Tests\Feature;

use App\Enums\BedStatus;
use App\Enums\PaymentMethod;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\BillingService;
use App\Services\ReportService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $svc;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ReportService::class);
        $this->hospital = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function paidInvoice(string $price, string $pay, string $method = 'cash'): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $c = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $s = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $price, 'name' => 'Svc '.uniqid()]);
        app(BillingService::class)->orderService($c->fresh(), $s->id, 1);
        $inv = app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null);
        app(BillingService::class)->recordPayment($inv, PaymentMethod::from($method), $pay, [], null);
    }

    public function test_revenue_sums_payments_in_bcmath_by_method(): void
    {
        $this->paidInvoice('100.00', '100.00', 'cash');
        $this->paidInvoice('50.00', '50.00', 'mobile_money');

        $r = $this->svc->revenue(Carbon::now()->subDay(), Carbon::now()->addDay());

        $this->assertSame('150.00', $r['total']);
        $this->assertSame('100.00', $r['by_method']['cash']);
        $this->assertSame('50.00', $r['by_method']['mobile_money']);
        $this->assertSame(2, $r['count']);
    }

    public function test_demographics_bucket_by_sex_and_age(): void
    {
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'male', 'dob' => now()->subYears(30)]);
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subYears(70)]);
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => 'female', 'dob' => now()->subYears(10)]);

        $d = $this->svc->demographics();
        $this->assertSame(3, $d['total']);
        $this->assertSame(1, $d['by_sex']['male']);
        $this->assertSame(2, $d['by_sex']['female']);
        $this->assertSame(1, $d['by_age']['18-39']);
        $this->assertSame(1, $d['by_age']['65+']);
        $this->assertSame(1, $d['by_age']['0-17']);
    }

    public function test_stock_valuation_totals_and_flags(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'current_quantity' => '100', 'current_stock_value' => '200.00', 'reorder_level' => '10']);
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'current_quantity' => '5', 'current_stock_value' => '10.00', 'reorder_level' => '10']); // low
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'current_quantity' => '50', 'current_stock_value' => '90.00', 'reorder_level' => '1', 'expiry_date' => now()->addDays(20)]); // expiring

        $v = $this->svc->stockValuation();
        $this->assertSame('300.00', $v['total_value']);
        $this->assertSame(1, $v['low_stock']);
        $this->assertSame(1, $v['expiring']);
    }

    public function test_occupancy_rate(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'status' => BedStatus::Occupied]);
        Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'status' => BedStatus::Occupied]);
        Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'status' => BedStatus::Available]);
        Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'status' => BedStatus::Available]);

        $o = $this->svc->occupancy();
        $this->assertSame(4, $o['total']);
        $this->assertSame(2, $o['occupied']);
        $this->assertSame(50, $o['rate']);
    }
}
