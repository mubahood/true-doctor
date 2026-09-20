<?php

namespace Tests\Feature;

use App\Enums\ClaimStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\InsuranceService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsuranceClaimTest extends TestCase
{
    use RefreshDatabase;

    private InsuranceService $svc;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(InsuranceService::class);
        $this->hospital = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function invoice(string $price = '100.00')
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $c = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $price]);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);

        return [app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null), $patient];
    }

    private function claim(array $o = [])
    {
        [$invoice, $patient] = $this->invoice($o['price'] ?? '100.00');
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $this->hospital->id]);

        return [$this->svc->createClaim([
            'patient_id' => $patient->id,
            'insurance_provider_id' => $provider->id,
            'invoice_id' => $invoice->id,
            'amount' => $o['amount'] ?? '100.00',
        ]), $invoice];
    }

    public function test_claim_gets_a_number_and_starts_draft(): void
    {
        [$claim] = $this->claim();

        $this->assertSame(ClaimStatus::Draft, $claim->status);
        $this->assertMatchesRegularExpression('/^CLM-\d{4}-\d{5}$/', $claim->claim_no);
    }

    public function test_full_lifecycle_paid_records_an_insurance_payment(): void
    {
        [$claim, $invoice] = $this->claim();

        $this->svc->transition($claim, ClaimStatus::Submitted);
        $this->svc->transition($claim, ClaimStatus::Approved);
        $paid = $this->svc->transition($claim, ClaimStatus::Paid);

        $this->assertSame(ClaimStatus::Paid, $paid->status);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(PaymentMethod::Insurance, $invoice->payments()->first()->method);
        $this->assertNotNull($paid->payment_id);
    }

    public function test_partial_claim_leaves_a_balance(): void
    {
        [$claim, $invoice] = $this->claim(['price' => '100.00', 'amount' => '60.00']);
        foreach ([ClaimStatus::Submitted, ClaimStatus::Approved, ClaimStatus::Paid] as $s) {
            $this->svc->transition($claim, $s);
        }

        $invoice->refresh();
        $this->assertSame('40.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    public function test_rejected_claim_records_no_payment(): void
    {
        [$claim, $invoice] = $this->claim();
        $this->svc->transition($claim, ClaimStatus::Submitted);
        $this->svc->transition($claim, ClaimStatus::Rejected, null, 'Not covered');

        $this->assertSame(ClaimStatus::Rejected, $claim->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->payments()->count());
    }

    public function test_illegal_transition_is_rejected(): void
    {
        [$claim] = $this->claim();

        $this->expectException(\RuntimeException::class);
        $this->svc->transition($claim, ClaimStatus::Paid); // draft → paid not allowed
    }
}
