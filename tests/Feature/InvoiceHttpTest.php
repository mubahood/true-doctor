<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\Visits\Panels\Charges;
use App\Models\Hospital;
use App\Models\Invoice;
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

/**
 * Invoice detail + payments. Generation moved to the Charges panel of the
 * visit workspace and the detail screen + "take payment" to
 * App\Livewire\Invoices\Show, so every money path is driven through a component
 * here; the PDF download is still a controller route.
 */
class InvoiceHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();   // the Charges panel is #[Lazy]
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function chargedVisit(Hospital $h): Visit
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '100.00']);
        app(BillingService::class)->orderService($c, $svc->id, 1);

        return $c->fresh();
    }

    public function test_generate_invoice_then_pay_cash(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->user($h, 'receptionist');
        $c = $this->chargedVisit($h);

        // The invoice is raised from the bill and stays there — the number,
        // the balance and the PDF appear in the section that raised it.
        Livewire::actingAs($user)->test(Charges::class, ['visitId' => $c->id])
            ->call('generateInvoice')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('100.00', (string) $invoice->total);

        Livewire::actingAs($user)->test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->call('openPayment')
            ->assertSet('amount', '100.00')          // defaults to the outstanding balance
            ->set('method', 'cash')
            ->call('recordPayment')
            ->assertHasNoErrors()
            ->assertSet('showPayment', false);

        $this->assertSame('0.00', (string) $invoice->fresh()->balance);
        $this->assertSame('paid', $invoice->fresh()->status->value);
        $this->assertSame(1, $invoice->payments()->count());
    }

    /**
     * The discount is agreed at the counter and stored on the visit, so the
     * invoice carries whatever was agreed rather than whatever the person
     * raising it happens to type.
     */
    public function test_a_discount_agreed_on_the_visit_reduces_the_invoice_total(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->user($h, 'receptionist');
        $c = $this->chargedVisit($h);

        Livewire::actingAs($user)->test(Charges::class, ['visitId' => $c->id])
            ->call('openDiscount')
            ->set('discountType', 'amount')
            ->set('discountValue', '25.00')
            ->set('discountReason', 'Staff relative')
            ->call('saveDiscount')
            ->assertHasNoErrors()
            ->call('generateInvoice')
            ->assertHasNoErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('25.00', (string) $invoice->discount);
        $this->assertSame('75.00', (string) $invoice->total);
    }

    /** A percentage is a rule, resolved against the bill of the moment. */
    public function test_a_percentage_discount_is_worked_out_against_the_bill(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->user($h, 'receptionist');
        $c = $this->chargedVisit($h);

        Livewire::actingAs($user)->test(Charges::class, ['visitId' => $c->id])
            ->call('openDiscount')
            ->set('discountType', 'percent')
            ->set('discountValue', '10')
            ->call('saveDiscount')
            ->assertHasNoErrors()
            ->call('generateInvoice')
            ->assertHasNoErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('10.00', (string) $invoice->discount);
        $this->assertSame('90.00', (string) $invoice->total);
    }

    public function test_a_second_invoice_for_the_same_visit_is_refused_inline(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->user($h, 'receptionist');
        $c = $this->chargedVisit($h);
        app(BillingService::class)->generateInvoice($c, '0.00', null);

        Livewire::actingAs($user)->test(Charges::class, ['visitId' => $c->id])
            ->call('generateInvoice')
            ->assertHasErrors('discount');

        $this->assertSame(1, Invoice::count());
    }

    /** Refused where it is agreed, not two screens later when it is invoiced. */
    public function test_a_discount_above_the_bill_is_refused_inline(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->user($h, 'receptionist');
        $c = $this->chargedVisit($h);

        Livewire::actingAs($user)->test(Charges::class, ['visitId' => $c->id])
            ->call('openDiscount')
            ->set('discountType', 'amount')
            ->set('discountValue', '500.00')
            ->call('saveDiscount')
            ->assertHasErrors('discountValue')
            ->assertSet('showDiscount', true);

        $this->assertSame('0.00', (string) $c->fresh()->discount_value);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_billing_reader_cannot_generate_an_invoice(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->chargedVisit($h);

        $reader = User::factory()->create(['hospital_id' => $h->id, 'role' => 'accountant']);
        $reader->syncRoles([]);
        $reader->givePermissionTo(['visits.view', 'billing.view']);

        Livewire::actingAs($reader)->test(Charges::class, ['visitId' => $c->id])
            ->assertOk()
            ->call('generateInvoice')
            ->assertForbidden();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_payment_above_the_balance_is_rejected_inline(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->user($h, 'receptionist');
        $c = $this->chargedVisit($h);
        $invoice = app(BillingService::class)->generateInvoice($c, '0.00', null);

        Livewire::actingAs($user)->test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->call('openPayment')
            ->set('amount', '150.00')
            ->call('recordPayment')
            ->assertHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('100.00', (string) $invoice->fresh()->balance);
        $this->assertSame('issued', $invoice->fresh()->status->value);
    }

    public function test_invoice_pdf_renders(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->user($h, 'accountant');
        $c = $this->chargedVisit($h);
        $invoice = app(BillingService::class)->generateInvoice($c, '0.00', null);

        $res = $this->actingAs($user)->get("/admin/invoices/{$invoice->uuid}/pdf");
        $res->assertOk();
        $res->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_role_without_billing_manage_cannot_take_payment(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $c = $this->chargedVisit($h);
        $invoice = app(BillingService::class)->generateInvoice($c, '0.00', null);

        // A nurse holds neither billing.view nor billing.manage: the page is closed.
        Livewire::actingAs($nurse)->test(InvoiceShow::class, ['invoice' => $invoice->uuid])->assertForbidden();

        // A billing reader may open the invoice but may not post money against it.
        $reader = User::factory()->create(['hospital_id' => $h->id, 'role' => 'accountant']);
        $reader->syncRoles([]);
        $reader->givePermissionTo('billing.view');

        Livewire::actingAs($reader)->test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->assertOk()
            ->call('openPayment')
            ->assertForbidden();

        Livewire::actingAs($reader)->test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->set('amount', '10.00')
            ->call('recordPayment')
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invoices_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $cB = $this->chargedVisit($b);
        $invoiceB = app(BillingService::class)->generateInvoice($cB, '0.00', null);

        $adminA = $this->user($a, 'hospital_admin');
        app(CurrentHospital::class)->set($a->id);

        $this->actingAs($adminA)->get("/admin/invoices/{$invoiceB->uuid}")->assertNotFound();
        $this->assertDatabaseCount('payments', 0);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($adminA)->test(InvoiceShow::class, ['invoice' => $invoiceB->uuid]);
    }
}
