<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\InsuranceLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/** Insurance claims and insurers over the API: insurance.view / insurance.manage, InsuranceService for every move. */
class InsuranceApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private function as(string $role, ?Hospital $h = null): User
    {
        $this->seedRbac();
        $h ??= $this->hospital;
        $this->withHospital($h);
        $user = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $user->syncSpatieRole();
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0:Invoice,1:Patient} */
    private function invoice(string $price = '100000.00', ?Hospital $h = null): array
    {
        $h ??= $this->hospital;
        $this->withHospital($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => $price]);
        app(BillingService::class)->orderService($visit->fresh(), $svc->id, 1);

        return [app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null), $patient];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital(['currency' => 'UGX']);
        $this->withHospital($this->hospital);
    }

    public function test_a_claim_goes_through_its_lifecycle_and_paid_settles_the_invoice(): void
    {
        $this->as('accountant');
        [$invoice, $patient] = $this->invoice();
        $provider = InsuranceProvider::create(['name' => 'Jubilee', 'is_active' => true]);

        $claim = $this->postJson('/api/v1/insurance-claims', [
            'patient_uuid' => $patient->uuid, 'invoice_uuid' => $invoice->uuid, 'insurance_provider_id' => $provider->id, 'amount' => '60000',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertMatchesRegularExpression('/^CLM-\d{4}-\d{5}$/', $claim['claim_no']);
        $this->assertSame(['submitted', 'cancelled'], array_column($claim['next_statuses'], 'value'));

        $u = "/api/v1/insurance-claims/{$claim['uuid']}";
        // Illegal moves are refused; the legal ones advance.
        $this->postJson("$u/transition", ['status' => 'paid'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson("$u/transition", ['status' => 'submitted'])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("$u/transition", ['status' => 'approved'])->assertOk();
        $this->postJson("$u/transition", ['status' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->postJson("$u/transition", ['status' => 'paid'])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.is_terminal', true);

        $invoice->refresh();
        $this->assertSame('40000.00', (string) $invoice->balance);
        $this->assertSame('60000.00', (string) $invoice->amount_paid);
        $this->postJson("$u/transition", ['status' => 'cancelled'])->assertStatus(422);

        $this->getJson($u)->assertOk()->assertJsonPath('data.invoice.balance', '40000.00')->assertJsonPath('data.provider.name', 'Jubilee');
        $this->getJson('/api/v1/insurance-claims?status=paid')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/insurance-claims?status=draft')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/insurance-claims?q='.$claim['claim_no'])->assertJsonPath('meta.total', 1);
    }

    public function test_claim_validation_and_rejection_with_a_reason(): void
    {
        $this->as('accountant');
        [$invoice, $patient] = $this->invoice();
        $provider = InsuranceProvider::create(['name' => 'Jubilee', 'is_active' => true]);

        $this->postJson('/api/v1/insurance-claims', ['patient_uuid' => $patient->uuid, 'invoice_uuid' => $invoice->uuid, 'insurance_provider_id' => $provider->id, 'amount' => '0'])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/v1/insurance-claims', ['patient_uuid' => 'nope', 'invoice_uuid' => $invoice->uuid, 'insurance_provider_id' => $provider->id, 'amount' => '5'])->assertStatus(422)->assertJsonValidationErrors(['patient_uuid']);

        $c = $this->postJson('/api/v1/insurance-claims', ['patient_uuid' => $patient->uuid, 'invoice_uuid' => $invoice->uuid, 'insurance_provider_id' => $provider->id, 'amount' => '5000', 'notes' => 'First'])->assertCreated()->json('data');
        $this->postJson("/api/v1/insurance-claims/{$c['uuid']}/transition", ['status' => 'submitted'])->assertOk();
        $this->postJson("/api/v1/insurance-claims/{$c['uuid']}/transition", ['status' => 'rejected', 'note' => 'Not covered'])->assertOk()->assertJsonPath('data.notes', 'Not covered')->assertJsonPath('data.status', 'rejected');
        $this->assertSame('100000.00', (string) $invoice->fresh()->balance, 'a rejected claim moves no money');
    }

    public function test_view_only_roles_read_but_cannot_change_and_other_hospitals_are_invisible(): void
    {
        $this->as('accountant');
        [$invoice, $patient] = $this->invoice();
        $provider = InsuranceProvider::create(['name' => 'Jubilee', 'is_active' => true]);
        $claim = $this->postJson('/api/v1/insurance-claims', ['patient_uuid' => $patient->uuid, 'invoice_uuid' => $invoice->uuid, 'insurance_provider_id' => $provider->id, 'amount' => '100'])->json('data');

        // Receptionist: insurance.view, not insurance.manage.
        $this->as('receptionist');
        $this->getJson('/api/v1/insurance-claims')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/insurance-claims/{$claim['uuid']}")->assertOk();
        $this->getJson('/api/v1/insurance-providers')->assertOk();
        $this->postJson("/api/v1/insurance-claims/{$claim['uuid']}/transition", ['status' => 'submitted'])->assertForbidden();
        $this->postJson('/api/v1/insurance-claims', ['patient_uuid' => $patient->uuid, 'invoice_uuid' => $invoice->uuid, 'insurance_provider_id' => $provider->id, 'amount' => '100'])->assertForbidden();
        $this->postJson('/api/v1/insurance-providers', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/insurance-providers/{$provider->id}", ['name' => 'Y'])->assertForbidden();
        $this->postJson("/api/v1/insurance-providers/{$provider->id}/deposit", ['amount' => 5])->assertForbidden();

        // Doctor: no insurance permission at all.
        $this->as('doctor');
        $this->getJson('/api/v1/insurance-claims')->assertForbidden();
        $this->getJson('/api/v1/insurance-providers')->assertForbidden();

        // Another hospital sees none of it.
        $other = $this->hospital();
        $this->as('accountant', $other);
        $this->getJson('/api/v1/insurance-claims')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/insurance-claims/{$claim['uuid']}")->assertNotFound();
        $this->postJson("/api/v1/insurance-claims/{$claim['uuid']}/transition", ['status' => 'submitted'])->assertNotFound();
        $this->getJson("/api/v1/insurance-providers/{$provider->id}")->assertNotFound();
        $this->postJson("/api/v1/insurance-providers/{$provider->id}/deposit", ['amount' => 5])->assertNotFound();
        $this->getJson('/api/v1/insurance-providers')->assertJsonPath('meta.total', 0);
    }

    public function test_providers_create_update_and_the_float_ledger(): void
    {
        $this->as('accountant');

        $p = $this->postJson('/api/v1/insurance-providers', ['name' => 'Britam', 'code' => 'BRT', 'contact_email' => 'a@b.test'])->assertCreated()->assertJsonPath('data.is_active', true)->json('data');
        $this->postJson('/api/v1/insurance-providers', ['name' => 'Britam'])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson('/api/v1/insurance-providers', ['name' => 'Bad', 'contact_email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['contact_email']);
        $this->putJson("/api/v1/insurance-providers/{$p['id']}", ['name' => 'Britam Ltd', 'code' => '', 'is_active' => false])->assertOk()->assertJsonPath('data.name', 'Britam Ltd')->assertJsonPath('data.code', null)->assertJsonPath('data.is_active', false);
        $this->putJson("/api/v1/insurance-providers/{$p['id']}", ['name' => 'Britam Ltd'])->assertOk()->assertJsonPath('data.is_active', true);
        $this->getJson('/api/v1/insurance-providers?q=BRT')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/insurance-providers?q=Britam')->assertJsonCount(1, 'data');

        $id = $p['id'];
        $this->postJson("/api/v1/insurance-providers/$id/deposit", ['amount' => '10000', 'reference' => 'RTGS-1'])->assertCreated()->assertJsonPath('data.float_balance', '10000.00');
        $this->postJson("/api/v1/insurance-providers/$id/deposit", ['amount' => '-5'])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson("/api/v1/insurance-providers/$id/adjust", ['direction' => 'remove', 'amount' => '2500', 'reason' => 'Bank fee'])->assertCreated()->assertJsonPath('data.float_balance', '7500.00');
        $this->postJson("/api/v1/insurance-providers/$id/adjust", ['direction' => 'remove', 'amount' => '999999', 'reason' => 'Too much'])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson("/api/v1/insurance-providers/$id/adjust", ['direction' => 'add', 'amount' => '1'])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        // A member in debt; settle-all clears what the float covers; the float agrees with its ledger.
        $provider = InsuranceProvider::find($id);
        $cards = app(CardService::class);
        $card = $cards->issue(Patient::factory()->create(['hospital_id' => $this->hospital->id]), ['insurance_provider_id' => $id, 'accepts_credit' => true, 'max_credit' => '50000']);
        $cards->debit($card, '3000.00', 'Visit');

        $show = $this->getJson("/api/v1/insurance-providers/$id")->assertOk();
        $show->assertJsonPath('data.figures.cards', 1)->assertJsonPath('data.figures.in_debt', 1)->assertJsonPath('data.figures.owed', '3000.00')->assertJsonPath('data.figures.shortfall', '4500.00')
            ->assertJsonPath('data.proof.agrees', true)->assertJsonPath('data.can_manage', true);
        $this->assertSame($provider->outstanding(), $show->json('data.figures.owed'));
        $show->assertJsonPath('data.usage.spent', '3000.00');

        $this->postJson("/api/v1/insurance-providers/$id/settle")->assertOk()->assertJsonPath('data.settled', 1)->assertJsonPath('data.paid', '3000.00');
        $this->postJson("/api/v1/insurance-providers/$id/settle")->assertOk()->assertJsonPath('message', 'Nothing to clear.');
        $this->assertSame('4500.00', (string) $provider->fresh()->float_balance);
        $this->assertTrue(app(InsuranceLedgerService::class)->reconcile($provider->fresh())['agrees']);
        $this->getJson("/api/v1/insurance-providers/$id/ledger")->assertOk()->assertJsonPath('meta.total', 3);

        // The usage PDF is the web's own.
        $this->get("/api/v1/insurance-providers/$id/usage")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
