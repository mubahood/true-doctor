<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Livewire\Dashboard\Section;
use App\Livewire\Settings\Billing;
use App\Livewire\Stock\Index as StockIndex;
use App\Livewire\Visits\Panels\Charges;
use App\Livewire\Visits\Panels\Dispense;
use App\Livewire\Visits\Panels\OrderDetail;
use App\Models\Hospital;
use App\Models\JournalLine;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\DispensationService;
use App\Services\Finance\ChartOfAccounts;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A product used on a visit can be Billed or Not billed (docs/orders.md);
 * a stock item's selling price is optional; and the admin dashboard reads
 * the day at a glance (a dental clinic's request).
 */
class ProductsNotBilledTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->useHospital(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
    }

    /** @param  array<string,mixed>  $attrs */
    private function useHospital(array $attrs = []): void
    {
        $this->hospital = Hospital::factory()->create($attrs);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
        $this->actingAs($this->admin);

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = app(VisitService::class)->open(['patient_id' => $patient->id, 'doctor_user_id' => $this->admin->id]);
    }

    private function item(string $onHand = '20', string $cost = '200', string $price = '500'): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Composite resin',
            'unit' => 'capsule', 'sale_price' => $price, 'cost_price' => $cost,
            'current_quantity' => $onHand, 'original_quantity' => $onHand, 'is_active' => true,
        ]);
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    private function procedure(): \App\Models\Order
    {
        return $this->orders()->place($this->visit, OrderType::Procedure, 'Filling', [], $this->admin->id);
    }

    private function service(string $price = '50000'): Service
    {
        return Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Filling', 'price' => $price]);
    }

    private function cogs(): string
    {
        $account = app(ChartOfAccounts::class)->key('cogs');

        return HospitalSettings::decimal((string) JournalLine::where('ledger_account_id', $account->id)->sum('debit'), 2);
    }

    // ── Billed or not billed ─────────────────────────────────────────────

    public function test_a_dental_clinic_starts_at_not_billed_and_a_hospital_at_billed(): void
    {
        $this->assertFalse(app(HospitalSettings::class)->productsBilledByDefault());

        $this->useHospital();
        $this->assertTrue(app(HospitalSettings::class)->productsBilledByDefault());
    }

    public function test_not_billed_takes_the_stock_charges_nothing_and_still_books_the_cost(): void
    {
        $order = $this->procedure();
        $this->orders()->addServiceItem($order, $this->service(), '1', $this->admin->id);
        $item = $this->item();

        $line = $this->orders()->addProductItem($order, $item, '3', $this->admin->id);

        $this->assertFalse($line->billed, 'a dental clinic starts at Not billed');
        $this->assertSame('0.00', (string) $line->line_total);
        $this->assertSame('200.00', (string) $line->unit_cost);
        $this->assertSame($this->admin->id, (int) $line->billing_set_by);
        $this->assertSame('17.00', (string) $item->fresh()->current_quantity, 'the stock still leaves the shelf');
        $this->assertSame('600.00', $this->cogs(), 'the cost still reaches the books as goods used');

        $totals = app(BillingService::class)->totalsFor($this->visit->fresh());
        $this->assertSame('50000.00', $totals['due'], 'only the service is billed');

        $invoice = app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);
        $this->assertSame('50000.00', (string) $invoice->total);
        $this->assertSame(['Filling'], $invoice->items()->pluck('description')->all(), 'the invoice leaves the product out');
    }

    public function test_a_line_can_be_switched_until_the_visit_is_invoiced(): void
    {
        $order = $this->procedure();
        $line = $this->orders()->addProductItem($order, $this->item(), '2', $this->admin->id, false);

        $line = $this->orders()->setBilled($line, true, $this->admin->id);
        $this->assertTrue($line->billed);
        $this->assertSame('1000.00', (string) $line->line_total, 'billed at the shelf price');
        $this->assertSame('1000.00', app(BillingService::class)->totalsFor($this->visit->fresh())['due']);

        $line = $this->orders()->setBilled($line, false, $this->admin->id);
        $this->assertSame('0.00', (string) $line->line_total);
        $this->assertSame('18.00', (string) StockItem::find($line->stock_item_id)->current_quantity, 'switching never moves stock');

        $this->orders()->addServiceItem($order, $this->service(), '1', $this->admin->id);
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        try {
            $this->orders()->setBilled($line, true, $this->admin->id);
            $this->fail('switching after invoicing was allowed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has been invoiced', $e->getMessage());
        }
        $this->assertFalse($line->fresh()->billed);
    }

    public function test_a_service_is_always_billed(): void
    {
        $line = $this->orders()->addServiceItem($this->procedure(), $this->service(), '1', $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->orders()->setBilled($line, false, $this->admin->id);
    }

    public function test_a_general_hospital_bills_products_as_before(): void
    {
        $this->useHospital();
        $line = $this->orders()->addProductItem($this->procedure(), $this->item(), '2', $this->admin->id);

        $this->assertTrue($line->billed);
        $this->assertSame('1000.00', (string) $line->line_total);
    }

    public function test_dispensing_and_what_was_used_carry_the_choice(): void
    {
        $item = $this->item();
        app(DispensationService::class)->dispense($this->visit, [
            ['stock_item_id' => $item->id, 'quantity' => '2', 'billed' => true],
            ['stock_item_id' => $item->id, 'quantity' => '1', 'billed' => false],
        ], null, $this->admin->id);

        $lines = OrderItem::whereNotNull('stock_item_id')->orderBy('id')->get();
        $this->assertSame([true, false], $lines->pluck('billed')->all());
        $this->assertSame('17.00', (string) $item->fresh()->current_quantity);

        $order = $this->procedure();
        $this->orders()->syncLines($order, [['kind' => 'product', 'id' => $item->id, 'quantity' => '1', 'billed' => true]], $this->admin->id);
        $this->orders()->syncLines($order, [['kind' => 'product', 'id' => $item->id, 'quantity' => '1', 'billed' => false]], $this->admin->id);

        $this->assertFalse($order->items()->sole()->billed, 'an existing line follows the choice');
        $this->assertSame('16.00', (string) $item->fresh()->current_quantity);
    }

    public function test_the_visit_screens_offer_and_record_the_choice(): void
    {
        Livewire::withoutLazyLoading();
        $item = $this->item();

        Livewire::test(Charges::class, ['visitId' => $this->visit->id])
            ->call('openAdd')
            ->assertSet('billed', false)
            ->set('kind', 'product')
            ->assertSee('Not billed')
            ->call('picked', 'stock_item_id', $item->id)
            ->set('quantity', '2')
            ->call('addLine')
            ->assertHasNoErrors();
        $line = OrderItem::whereNotNull('stock_item_id')->sole();
        $this->assertFalse($line->billed);

        Livewire::test(OrderDetail::class, ['visitId' => $this->visit->id])
            ->call('open', $this->visit->id, $line->order_id)
            ->assertSee('Not billed')
            ->call('setLineBilled', $line->id, true)
            ->assertDispatched('toast', type: 'success');
        $this->assertTrue($line->fresh()->billed);
        $this->assertSame('1000.00', (string) $line->fresh()->line_total);

        Livewire::withoutLazyLoading();
        Livewire::test(Dispense::class, ['visitId' => $this->visit->id])
            ->call('openForm')
            ->assertSee('Not billed')
            ->set('items.0.stock_item_id', $item->id)
            ->set('items.0.quantity', '1')
            ->call('setRowBilled', 0, false)
            ->call('dispense')
            ->assertHasNoErrors();
        $this->assertFalse(OrderItem::whereNotNull('stock_item_id')->latest('id')->first()->billed);
    }

    public function test_the_billing_setting_changes_where_the_choice_starts(): void
    {
        Livewire::test(Billing::class)
            ->assertSet('products_billed', false)
            ->set('products_billed', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(app(HospitalSettings::class)->productsBilledByDefault());
    }

    public function test_the_api_adds_switches_and_dispenses_with_the_choice(): void
    {
        Sanctum::actingAs($this->admin);
        $item = $this->item();
        $order = $this->procedure();

        $res = $this->postJson("/api/v1/orders/{$order->uuid}/items", [
            'kind' => 'product', 'stock_item_id' => $item->id, 'quantity' => 2, 'billed' => false,
        ])->assertCreated()->assertJsonPath('data.items.0.billed', false)->assertJsonPath('data.total', '0.00');
        $lineId = $res->json('data.items.0.id');

        $this->putJson("/api/v1/orders/{$order->uuid}/items/{$lineId}/billed", ['billed' => true])
            ->assertOk()->assertJsonPath('data.items.0.billed', true)->assertJsonPath('data.total', '1000.00');

        $this->postJson("/api/v1/visits/{$this->visit->uuid}/dispensations", [
            'items' => [['stock_item_id' => $item->id, 'quantity' => 1, 'billed' => false]],
        ])->assertCreated();
        $this->assertFalse(OrderItem::whereNotNull('stock_item_id')->latest('id')->first()->billed);
        $this->assertSame('17.00', (string) $item->fresh()->current_quantity);

        $this->orders()->addServiceItem($order, $this->service(), '1', $this->admin->id);
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);
        $this->putJson("/api/v1/orders/{$order->uuid}/items/{$lineId}/billed", ['billed' => false])
            ->assertStatus(422);
    }

    // ── A selling price left empty ───────────────────────────────────────

    public function test_an_empty_selling_price_is_the_cost_on_the_form_the_api_and_the_import(): void
    {
        Livewire::test(StockIndex::class)
            ->call('create')
            ->set('name', 'Gauze')
            ->set('unit', 'pack')
            ->set('reorder_level', '5')
            ->set('cost_price', '1500')
            ->set('sale_price', '')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('1500.00', (string) StockItem::where('name', 'Gauze')->sole()->sale_price);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/stock-items', ['name' => 'Cotton rolls', 'unit' => 'pack', 'cost_price' => 800, 'reorder_level' => 2])
            ->assertCreated()->assertJsonPath('data.sale_price', '800.00');

        $import = app(\App\Services\DataImportService::class);
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "Item,Qty,Unit,Cost\nLidocaine cartridge,10,cartridge,1200\n");
        $batch = $import->start(\App\Enums\ImportKind::Stock, $path, 'stock.csv', $this->admin->id);
        $batch = $import->map($batch, $batch->column_map ?? []);
        for ($i = 0; $i < 50 && $batch->status->isWorking(); $i++) {
            $batch = $import->step($batch, $this->admin->id);
        }
        $batch = $import->import($batch);
        for ($i = 0; $i < 50 && $batch->status->isWorking(); $i++) {
            $batch = $import->step($batch, $this->admin->id);
        }
        $this->assertSame('1200.00', (string) StockItem::where('name', 'Lidocaine cartridge')->sole()->sale_price);
    }

    // ── The day at a glance ──────────────────────────────────────────────

    public function test_the_dashboard_shows_the_day_and_what_went_unbilled(): void
    {
        $order = $this->procedure();
        $this->orders()->addServiceItem($order, $this->service(), '1', $this->admin->id);
        $this->orders()->addProductItem($order, $this->item(), '3', $this->admin->id, false);
        $this->orders()->transition($order, OrderStatus::Completed, $this->admin->id);

        $invoice = app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);
        app(BillingService::class)->recordPayment($invoice, \App\Enums\PaymentMethod::Cash, '20000.00', [], $this->admin->id);

        $svc = app(\App\Support\Dashboard\DashboardService::class);
        $this->assertSame(1, $svc->patientsSeenToday());
        $this->assertSame(1, $svc->treatmentsDoneToday());
        $this->assertSame(['count' => 1, 'cost' => '600.00'], $svc->notBilledThisMonth());
        $this->assertSame('20000.00', $svc->income()['today']);
        $this->assertSame('20000.00', $svc->income()['month']);

        Livewire::withoutLazyLoading();
        Livewire::test(Section::class, ['widget' => 'admin.stats'])
            ->assertSee('Patients today')
            ->assertSee('1 treatment done')
            ->assertSee('Collected this month')
            ->assertSee('Not billed this month')
            ->assertSee('Low stock');
    }

    public function test_the_patient_summary_leaves_a_product_given_without_billing_off_the_bill(): void
    {
        $order = $this->procedure();
        $this->orders()->addServiceItem($order, $this->service(), '1', $this->admin->id);
        $this->orders()->addProductItem($order, $this->item(), '1', $this->admin->id);

        $summary = app(\App\Services\VisitReportService::class)->summary($this->visit->fresh());

        $this->assertSame(['Filling'], array_column($summary['lines'], 'name'), 'the composite was not billed, so it is not on the bill');
    }

    public function test_the_visit_preview_shows_the_dental_chart_and_the_dosage(): void
    {
        $sections = \Livewire\Livewire::test(\App\Livewire\Visits\Actions::class)
            ->call('openAction', $this->visit->id)
            ->assertSee('Dental chart')->assertSee('Prescription')
            ->instance()->sections;
        $this->assertSame(['summary', 'chart', 'plan', 'prescription', 'orders'], array_slice(array_keys($sections), 0, 5));

        // A general hospital's preview is as it was.
        $this->useHospital();
        $keys = array_keys(\Livewire\Livewire::test(\App\Livewire\Visits\Actions::class)->call('openAction', $this->visit->id)->instance()->sections);
        $this->assertNotContains('chart', $keys);
        $this->assertNotContains('prescription', $keys);
    }
}
