<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Dispensations\Show as DispensationShow;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\LabOrders\Show as LabOrderShow;
use App\Livewire\RadiologyOrders\Show as RadiologyOrderShow;
use App\Livewire\Stock\Alerts as StockAlerts;
use App\Models\Dispensation;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RadiologyStudy;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\DispensationService;
use App\Services\LabService;
use App\Services\RadiologyService;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Render / RBAC / tenancy for the five Phase 3 detail + board screens
 * (house rule 18). The behavioural assertions (money invariants, transitions,
 * result persistence) live with their domain suites.
 */
class DetailPagesTest extends TestCase
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

    private function stripped(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncRoles([]);

        return $u;
    }

    private function visit(Hospital $h): Visit
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ada', 'last_name' => 'Nakato']);

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
    }

    // ── Invoices\Show ──────────────────────────────────────────
    public function test_invoice_show_renders_lines_totals_and_payments(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Visit fee', 'price' => '100.00']);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null);

        $accountant = $this->user($h, 'accountant');

        Livewire::actingAs($accountant)->test(InvoiceShow::class, ['invoice' => $invoice->uuid])
            ->assertOk()
            ->assertSee($invoice->invoice_no)
            ->assertSee('Visit fee')
            ->assertSee('Ada Nakato')
            ->assertSet('invoiceId', $invoice->id)
            ->assertSet('amount', '100.00')
            ->call('openPayment')
            ->assertSet('showPayment', true)
            ->assertSee('Record payment');

        // Full-page smoke: route + layout + title.
        $this->actingAs($accountant)->get("/admin/invoices/{$invoice->uuid}")
            ->assertOk()
            ->assertSee($invoice->invoice_no)
            ->assertSee('<title>'.$invoice->invoice_no.' · True-Doctor</title>', false);
    }

    public function test_invoice_show_is_forbidden_without_billing_view(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '10.00']);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null);

        Livewire::actingAs($this->stripped($h))->test(InvoiceShow::class, ['invoice' => $invoice->uuid])->assertForbidden();
    }

    // ── LabOrders\Show ─────────────────────────────────────────
    public function test_lab_order_show_renders_items(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $test = LabTest::factory()->create(['hospital_id' => $h->id, 'name' => 'Malaria RDT', 'price' => '15.00']);
        $order = app(LabService::class)->order($c->fresh(), [$test->id], 'febrile', null);

        $tech = $this->user($h, 'lab_technician');

        Livewire::actingAs($tech)->test(LabOrderShow::class, ['labOrder' => $order->uuid])
            ->assertOk()
            ->assertSee('Malaria RDT')
            ->assertSee('febrile')
            ->assertSet('orderId', $order->id);

        $this->actingAs($tech)->get("/admin/lab-orders/{$order->uuid}")->assertOk()->assertSee('Malaria RDT');
    }

    public function test_lab_order_show_is_forbidden_without_lab_view(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $test = LabTest::factory()->create(['hospital_id' => $h->id]);
        $order = app(LabService::class)->order($c->fresh(), [$test->id], null, null);

        Livewire::actingAs($this->stripped($h))->test(LabOrderShow::class, ['labOrder' => $order->uuid])->assertForbidden();
    }

    public function test_lab_result_validation_errors_surface_inline(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $test = LabTest::factory()->create(['hospital_id' => $h->id]);
        $order = app(LabService::class)->order($c->fresh(), [$test->id], null, null);
        $item = $order->items()->first();

        Livewire::actingAs($this->user($h, 'lab_technician'))->test(LabOrderShow::class, ['labOrder' => $order->uuid])
            ->set("results.{$item->id}.result_value", str_repeat('x', 200))
            ->assertHasErrors("results.{$item->id}.result_value");

        $this->assertNull($item->fresh()->result_value);
    }

    // ── RadiologyOrders\Show ───────────────────────────────────
    public function test_radiology_order_show_renders_studies_and_report(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $study = RadiologyStudy::factory()->create(['hospital_id' => $h->id, 'name' => 'Chest X-ray', 'price' => '40.00']);
        $order = app(RadiologyService::class)->order($c->fresh(), [$study->id], null, null);
        app(RadiologyService::class)->recordReport($order, 'Clear lung fields.', 'Normal.', null);

        $rad = $this->user($h, 'radiologist');

        Livewire::actingAs($rad)->test(RadiologyOrderShow::class, ['radiologyOrder' => $order->uuid])
            ->assertOk()
            ->assertSee('Chest X-ray')
            ->assertSet('findings', 'Clear lung fields.')
            ->assertSet('impression', 'Normal.');

        $this->actingAs($rad)->get("/admin/radiology-orders/{$order->uuid}")->assertOk()->assertSee('Chest X-ray');
    }

    public function test_radiology_order_show_is_forbidden_without_radiology_view(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $study = RadiologyStudy::factory()->create(['hospital_id' => $h->id]);
        $order = app(RadiologyService::class)->order($c->fresh(), [$study->id], null, null);

        Livewire::actingAs($this->stripped($h))->test(RadiologyOrderShow::class, ['radiologyOrder' => $order->uuid])->assertForbidden();
    }

    // ── Dispensations\Show ─────────────────────────────────────
    private function dispensation(Hospital $h): Dispensation
    {
        $c = $this->visit($h);
        $item = StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'Paracetamol 500mg', 'sale_price' => '2.00']);
        app(StockService::class)->receive($item, '50', '1.00');

        return app(DispensationService::class)->dispense(
            $c->fresh(),
            [['stock_item_id' => $item->id, 'quantity' => '10']],
            'take after meals',
            null,
            null,
        );
    }

    public function test_dispensation_show_renders_items_for_a_pharmacist(): void
    {
        $h = Hospital::factory()->create();
        $dispensation = $this->dispensation($h);

        $pharm = $this->user($h, 'pharmacist');

        Livewire::actingAs($pharm)->test(DispensationShow::class, ['dispensation' => $dispensation->uuid])
            ->assertOk()
            ->assertSee('Paracetamol 500mg')
            ->assertSee('take after meals')
            ->assertSet('dispensationId', $dispensation->id);

        $this->actingAs($pharm)->get("/admin/dispensations/{$dispensation->uuid}")->assertOk()->assertSee('Paracetamol 500mg');
    }

    public function test_dispensation_show_is_forbidden_without_pharmacy_permissions(): void
    {
        $h = Hospital::factory()->create();
        $dispensation = $this->dispensation($h);

        Livewire::actingAs($this->stripped($h))->test(DispensationShow::class, ['dispensation' => $dispensation->uuid])->assertForbidden();
    }

    public function test_dispensation_show_is_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $dispensationB = $this->dispensation($b);

        $pharmA = $this->user($a, 'pharmacist');
        app(CurrentHospital::class)->set($a->id);

        $this->actingAs($pharmA)->get("/admin/dispensations/{$dispensationB->uuid}")->assertNotFound();

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($pharmA)->test(DispensationShow::class, ['dispensation' => $dispensationB->uuid]);
    }

    // ── Stock\Alerts ───────────────────────────────────────────
    public function test_stock_alerts_board_is_forbidden_without_pharmacy_view(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);

        Livewire::actingAs($this->stripped($h))->test(StockAlerts::class)->assertForbidden();
    }

    public function test_stock_alerts_board_is_tenant_isolated_and_polls(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        app(CurrentHospital::class)->set($b->id);
        StockItem::factory()->create(['hospital_id' => $b->id, 'name' => 'SecretDrug', 'current_quantity' => '0', 'reorder_level' => '5']);
        app(CurrentHospital::class)->set($a->id);
        StockItem::factory()->create(['hospital_id' => $a->id, 'name' => 'OwnDrug', 'current_quantity' => '0', 'reorder_level' => '5']);

        Livewire::actingAs($this->user($a, 'pharmacist'))->test(StockAlerts::class)
            ->assertOk()
            ->assertSee('OwnDrug')
            ->assertDontSee('SecretDrug')
            ->assertSeeHtml('wire:poll.60s.visible');
    }
}
