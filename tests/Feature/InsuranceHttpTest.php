<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Livewire\InsuranceClaims\Index as InsuranceClaimsIndex;
use App\Livewire\InsuranceClaims\Show as InsuranceClaimShow;
use App\Models\Hospital;
use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InsuranceHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function invoice(Hospital $h)
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '100.00']);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);

        return [app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null), $patient];
    }

    public function test_accountant_creates_a_claim_and_marks_it_paid(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $acct = $this->user($h, 'accountant');
        [$invoice, $patient] = $this->invoice($h);
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $h->id]);

        // The classic create/store page is gone — the slide-over raises claims.
        $this->actingAs($acct);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(InsuranceClaimsIndex::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->set('insurance_provider_id', $provider->id)
            ->set('invoice_id', $invoice->id)
            ->set('amount', '100.00')
            ->call('save')
            // The dialog closes onto the list rather than navigating away.
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('showForm', false);
        $claim = InsuranceClaim::firstOrFail();

        // The lifecycle is a Livewire action on the claim detail page (Phase 3).
        foreach (['submitted', 'approved', 'paid'] as $s) {
            Livewire::actingAs($acct)->test(InsuranceClaimShow::class, ['insuranceClaim' => $claim->uuid])
                ->call('transition', $s)
                ->assertDispatched('toast', type: 'success');
        }

        $this->assertSame('paid', $claim->fresh()->status->value);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        // Paying the claim records an insurance payment on the linked invoice.
        $this->assertSame(1, $invoice->fresh()->payments()->count());
        $this->assertSame(PaymentMethod::Insurance, $invoice->fresh()->payments()->first()?->method);
        $this->assertNotNull($claim->fresh()->payment_id);
    }

    public function test_illegal_claim_transition_is_rejected_with_an_error_toast(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $acct = $this->user($h, 'accountant');
        [$invoice, $patient] = $this->invoice($h);
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $h->id]);
        $claim = app(\App\Services\InsuranceService::class)->createClaim([
            'patient_id' => $patient->id, 'insurance_provider_id' => $provider->id,
            'invoice_id' => $invoice->id, 'amount' => '100.00',
        ]);

        // draft → paid is not a legal move, and no payment may be recorded.
        Livewire::actingAs($acct)->test(InsuranceClaimShow::class, ['insuranceClaim' => $claim->uuid])
            ->call('transition', 'paid')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame('draft', $claim->fresh()->status->value);
        $this->assertSame(0, $invoice->fresh()->payments()->count());
    }

    /** insurance.view without insurance.manage reads the claim but cannot move it. */
    public function test_receptionist_cannot_transition_a_claim(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        [$invoice, $patient] = $this->invoice($h);
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $h->id]);
        $claim = app(\App\Services\InsuranceService::class)->createClaim([
            'patient_id' => $patient->id, 'insurance_provider_id' => $provider->id,
            'invoice_id' => $invoice->id, 'amount' => '100.00',
        ]);

        Livewire::actingAs($this->user($h, 'receptionist'))
            ->test(InsuranceClaimShow::class, ['insuranceClaim' => $claim->uuid])
            ->assertOk()
            ->assertSee($claim->claim_no)
            ->call('transition', 'submitted')
            ->assertForbidden();

        $this->assertSame('draft', $claim->fresh()->status->value);
    }

    public function test_receptionist_can_view_but_not_create_claims(): void
    {
        $h = Hospital::factory()->create();
        $recep = $this->user($h, 'receptionist');

        $this->actingAs($recep)->get('/admin/insurance-claims')->assertOk();

        app(CurrentHospital::class)->set($h->id);
        Livewire::test(InsuranceClaimsIndex::class)->call('create')->assertForbidden();
    }

    public function test_doctor_has_no_insurance_access(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'doctor'))->get('/admin/insurance-claims')->assertForbidden();
    }

    public function test_claims_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create(['currency' => 'UGX']);
        [$invoiceB, $patientB] = $this->invoice($b);
        $providerB = InsuranceProvider::factory()->create(['hospital_id' => $b->id]);
        $claimB = app(\App\Services\InsuranceService::class)->createClaim([
            'patient_id' => $patientB->id, 'insurance_provider_id' => $providerB->id, 'invoice_id' => $invoiceB->id, 'amount' => '50.00',
        ]);

        $acctA = $this->user($a, 'accountant');
        $this->actingAs($acctA)->get("/admin/insurance-claims/{$claimB->uuid}")->assertNotFound();

        app(CurrentHospital::class)->set($a->id);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($acctA)->test(InsuranceClaimShow::class, ['insuranceClaim' => $claimB->uuid]);
    }
}
