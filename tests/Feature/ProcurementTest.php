<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementReason;
use App\Livewire\PurchaseOrders\Index as OrdersIndex;
use App\Livewire\PurchaseOrders\Show as OrderShow;
use App\Livewire\Suppliers\Index as SuppliersIndex;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Hospital;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\ProcurementService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/** Suppliers, purchase orders, goods received and returns (roadmap 04 §7, docs/procurement.md). */
class ProcurementTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private User $pharmacist;

    private Supplier $supplier;

    private StockItem $paracetamol;

    private StockItem $amoxicillin;

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::withoutLazyLoading();

        $this->hospital = $this->hospital();
        $this->pharmacist = $this->actingAsRole('pharmacist', $this->hospital);
        $this->supplier = Supplier::create(['name' => 'Nile Pharma Distributors', 'is_active' => true, 'payment_terms_days' => 30]);
        $this->paracetamol = $this->item('Paracetamol 500mg', '0', '100.00');
        $this->amoxicillin = $this->item('Amoxicillin 250mg', '40', '300.00', ['batch_no' => 'OLD-1', 'expiry_date' => now()->addMonths(6)->toDateString()]);
    }

    private function item(string $name, string $qty, string $cost, array $extra = []): StockItem
    {
        return StockItem::factory()->create(array_merge([
            'hospital_id' => $this->hospital->id,
            'name' => $name,
            'current_quantity' => $qty,
            'cost_price' => $cost,
            'current_stock_value' => bcmul($qty, $cost, 2),
            'reorder_level' => '10',
            'batch_no' => null,
            'expiry_date' => null,
            'is_active' => true,
        ], $extra));
    }

    private function procurement(): ProcurementService
    {
        return app(ProcurementService::class);
    }

    private function sentOrder(): PurchaseOrder
    {
        $order = $this->procurement()->createOrder($this->supplier, ['expected_date' => now()->addWeek()->toDateString()], [
            ['stock_item_id' => $this->paracetamol->id, 'quantity' => '100', 'unit_cost' => '90'],
            ['stock_item_id' => $this->amoxicillin->id, 'quantity' => '50', 'unit_cost' => '280.50'],
        ], $this->pharmacist);

        return $this->procurement()->markOrdered($order, $this->pharmacist);
    }

    /** @return array<int,int> stock item id => order line id */
    private function lineIds(PurchaseOrder $order): array
    {
        return $order->items()->pluck('id', 'stock_item_id')->all();
    }

    // ── Orders ─────────────────────────────────────────────────────────

    public function test_an_order_is_numbered_totalled_and_the_same_item_twice_is_one_line(): void
    {
        $order = $this->procurement()->createOrder($this->supplier, [], [
            ['stock_item_id' => $this->paracetamol->id, 'quantity' => '100', 'unit_cost' => '90'],
            ['stock_item_id' => $this->paracetamol->id, 'quantity' => '20', 'unit_cost' => '95'],
            ['stock_item_id' => $this->amoxicillin->id, 'quantity' => '3', 'unit_cost' => '0.10'],
        ], $this->pharmacist);
        $second = $this->procurement()->createOrder($this->supplier, [], [], $this->pharmacist);

        $year = now()->format('Y');
        $this->assertSame("PO-{$year}-00001", $order->po_no);
        $this->assertSame("PO-{$year}-00002", $second->po_no);
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertCount(2, $order->items);
        $this->assertSame('120.00', (string) $order->items->firstWhere('stock_item_id', $this->paracetamol->id)->quantity);
        // 120 × 95 + 3 × 0.10, in bcmath.
        $this->assertSame('11400.30', (string) $order->total);
    }

    public function test_the_status_machine_is_enforced(): void
    {
        $empty = $this->procurement()->createOrder($this->supplier, [], [], $this->pharmacist);
        $this->assertThrows(fn () => $this->procurement()->markOrdered($empty, $this->pharmacist), ValidationException::class);
        $this->assertThrows(fn () => $this->procurement()->receive($empty, [], [], $this->pharmacist), ValidationException::class);
        $this->assertThrows(fn () => $this->procurement()->close($empty, 'no', $this->pharmacist), ValidationException::class);

        $order = $this->sentOrder();
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->status);
        $this->assertThrows(fn () => $this->procurement()->addLine($order, ['stock_item_id' => $this->paracetamol->id, 'quantity' => 1]), ValidationException::class);

        $this->procurement()->receive($order, [], [['purchase_order_item_id' => $this->lineIds($order)[$this->paracetamol->id], 'quantity' => '10']], $this->pharmacist);
        $this->assertThrows(fn () => $this->procurement()->cancel($order->fresh(), 'Changed our mind', $this->pharmacist), ValidationException::class);

        $closed = $this->procurement()->close($order->fresh(), 'Supplier out of stock', $this->pharmacist);
        $this->assertSame(PurchaseOrderStatus::Closed, $closed->status);
        $this->assertSame('Supplier out of stock', $closed->end_reason);
        $this->assertThrows(fn () => $this->procurement()->receive($closed, [], [['purchase_order_item_id' => $this->lineIds($order)[$this->paracetamol->id], 'quantity' => '1']], $this->pharmacist), ValidationException::class);

        $draft = $this->procurement()->createOrder($this->supplier, [], [['stock_item_id' => $this->paracetamol->id, 'quantity' => '5']], $this->pharmacist);
        $this->assertSame(PurchaseOrderStatus::Cancelled, $this->procurement()->cancel($draft, 'Duplicate', $this->pharmacist)->status);
    }

    // ── Goods received ─────────────────────────────────────────────────

    public function test_a_part_delivery_goes_on_the_shelf_through_the_stock_ledger_and_into_the_books(): void
    {
        $order = $this->sentOrder();
        $ids = $this->lineIds($order);

        $grn = $this->procurement()->receive($order, ['supplier_invoice_no' => 'INV-77'], [
            ['purchase_order_item_id' => $ids[$this->paracetamol->id], 'quantity' => '60', 'unit_cost' => '92', 'batch_no' => 'B-1', 'expiry_date' => now()->addYear()->toDateString()],
            ['purchase_order_item_id' => $ids[$this->amoxicillin->id], 'quantity' => '0'],
        ], $this->pharmacist);

        $this->assertSame('GRN-'.now()->format('Y').'-00001', $grn->grn_no);
        $this->assertCount(1, $grn->items);
        $this->assertSame('5520.00', (string) $grn->total);
        $this->assertSame(PurchaseOrderStatus::PartReceived, $order->fresh()->status);

        $shelf = $this->paracetamol->fresh();
        $this->assertSame('60.00', (string) $shelf->current_quantity);
        $this->assertSame('92.00', (string) $shelf->cost_price, 'the invoice cost becomes the item cost');
        $this->assertSame('B-1', $shelf->batch_no, 'an empty shelf takes the delivery batch');

        $movement = StockMovement::query()->where('stock_item_id', $this->paracetamol->id)->sole();
        $this->assertSame(StockMovementReason::Received, $movement->reason);
        $this->assertSame((new GoodsReceiptItem)->getMorphClass(), $movement->source_type);
        $this->assertSame($grn->items->first()->id, (int) $movement->source_id);

        $entry = JournalEntry::query()->where('source_type', $movement->getMorphClass())->where('source_id', $movement->id)->sole();
        $this->assertSame('Nile Pharma Distributors', $entry->party);
        $this->assertSame($grn->grn_no.' / INV-77', $entry->reference);
        $this->assertSame('5520.00', (string) $entry->total);

        // Over-receipt is refused, and nothing moves.
        $this->assertThrows(fn () => $this->procurement()->receive($order->fresh(), [], [
            ['purchase_order_item_id' => $ids[$this->paracetamol->id], 'quantity' => '41'],
        ], $this->pharmacist), ValidationException::class);
        $this->assertSame('60.00', (string) $this->paracetamol->fresh()->current_quantity);

        $this->procurement()->receive($order->fresh(), [], [
            ['purchase_order_item_id' => $ids[$this->paracetamol->id], 'quantity' => '40'],
            ['purchase_order_item_id' => $ids[$this->amoxicillin->id], 'quantity' => '50', 'batch_no' => 'NEW-2', 'expiry_date' => now()->addMonths(2)->toDateString()],
        ], $this->pharmacist);

        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $amox = $this->amoxicillin->fresh();
        $this->assertSame('90.00', (string) $amox->current_quantity);
        $this->assertSame('NEW-2', $amox->batch_no, 'the earlier expiry is the one the shelf shows');
        $this->assertSame(now()->addMonths(2)->toDateString(), $amox->expiry_date?->toDateString());
        $this->assertSame(2, GoodsReceipt::count());
    }

    public function test_the_same_line_twice_in_one_delivery_cannot_receive_more_than_is_owed(): void
    {
        $order = $this->sentOrder();
        $id = $this->lineIds($order)[$this->paracetamol->id];

        $this->assertThrows(fn () => $this->procurement()->receive($order, [], [
            ['purchase_order_item_id' => $id, 'quantity' => '100'],
            ['purchase_order_item_id' => $id, 'quantity' => '100'],
        ], $this->pharmacist), ValidationException::class);

        foreach (['1e3', '1.999', '12345678901'] as $bad) {
            $this->assertThrows(fn () => $this->procurement()->receive($order->fresh(), [], [
                ['purchase_order_item_id' => $id, 'quantity' => $bad],
            ], $this->pharmacist), ValidationException::class);
        }

        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame('0.00', (string) $this->paracetamol->fresh()->current_quantity);
    }

    public function test_a_later_expiry_does_not_hide_stock_that_expires_sooner(): void
    {
        $order = $this->sentOrder();
        $this->procurement()->receive($order, [], [
            ['purchase_order_item_id' => $this->lineIds($order)[$this->amoxicillin->id], 'quantity' => '10', 'batch_no' => 'LATE', 'expiry_date' => now()->addYears(2)->toDateString()],
        ], $this->pharmacist);

        $this->assertSame('OLD-1', $this->amoxicillin->fresh()->batch_no);
        $this->assertSame(now()->addMonths(6)->toDateString(), $this->amoxicillin->fresh()->expiry_date?->toDateString());
        $this->assertSame('LATE', GoodsReceiptItem::sole()->batch_no, 'the delivery line keeps its own batch');
    }

    // ── Returns ────────────────────────────────────────────────────────

    public function test_a_return_takes_stock_off_the_shelf_at_the_delivery_cost_and_reduces_the_payable(): void
    {
        $order = $this->sentOrder();
        $grn = $this->procurement()->receive($order, [], [
            ['purchase_order_item_id' => $this->lineIds($order)[$this->amoxicillin->id], 'quantity' => '50'],
        ], $this->pharmacist);
        $line = $grn->items->first();

        $return = $this->procurement()->returnToSupplier($line, '10', 'Damaged', $this->pharmacist);

        $this->assertSame('SR-'.now()->format('Y').'-00001', $return->return_no);
        $this->assertSame('2805.00', (string) $return->total);
        $this->assertSame('80.00', (string) $this->amoxicillin->fresh()->current_quantity);

        $movement = StockMovement::query()->where('reason', StockMovementReason::ReturnedToSupplier->value)->sole();
        $this->assertSame('280.50', (string) $movement->unit_cost);
        $this->assertSame((new SupplierReturn)->getMorphClass(), $movement->source_type);
        $entry = JournalEntry::query()->where('source_type', $movement->getMorphClass())->where('source_id', $movement->id)->sole();
        $this->assertSame('Nile Pharma Distributors', $entry->party);
        $this->assertSame($return->return_no, $entry->reference);

        // Capped at what that delivery brought in, less what already went back.
        $this->assertThrows(fn () => $this->procurement()->returnToSupplier($line->fresh(), '41', 'More', $this->pharmacist), ValidationException::class);
        $this->assertThrows(fn () => $this->procurement()->returnToSupplier($line->fresh(), '1', '  ', $this->pharmacist), ValidationException::class);
        $this->procurement()->returnToSupplier($line->fresh(), '40', 'Recalled', $this->pharmacist);
        $this->assertSame('0.00', $line->fresh()->returnable());
        $this->assertSame('40.00', (string) $this->amoxicillin->fresh()->current_quantity);
    }

    public function test_nothing_goes_back_that_is_no_longer_on_the_shelf(): void
    {
        $order = $this->sentOrder();
        $grn = $this->procurement()->receive($order, [], [
            ['purchase_order_item_id' => $this->lineIds($order)[$this->paracetamol->id], 'quantity' => '20'],
        ], $this->pharmacist);
        app(\App\Services\StockService::class)->adjust($this->paracetamol->fresh(), StockMovementReason::Dispensed, '15');

        $this->assertThrows(fn () => $this->procurement()->returnToSupplier($grn->items->first(), '10', 'Damaged', $this->pharmacist), ValidationException::class);
        $this->assertSame(0, SupplierReturn::count());
    }

    // ── Tenancy and permissions ────────────────────────────────────────

    public function test_another_hospital_cannot_see_or_touch_an_order(): void
    {
        $order = $this->sentOrder();

        $other = $this->hospital();
        $this->actingAsRole('pharmacist', $other);

        $this->assertNull(PurchaseOrder::find($order->id));
        $this->assertSame(0, Supplier::count());
        $this->get(route('admin.purchase-orders.show', $order->uuid))->assertNotFound();
        $this->get(route('admin.purchase-orders.pdf', $order->uuid))->assertNotFound();

        Sanctum::actingAs(auth()->user());
        $this->getJson("/api/v1/purchase-orders/{$order->uuid}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", ['lines' => [['purchase_order_item_id' => 1, 'quantity' => 1]]])->assertNotFound();
        $this->getJson('/api/v1/purchase-orders')->assertOk()->assertJsonCount(0, 'data');

        // A supplier name is unique per hospital, not across them.
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['name' => 'Nile Pharma Distributors'], \App\Http\Requests\SupplierRequest::rulesFor())->passes());
    }

    public function test_only_the_store_may_open_procurement(): void
    {
        $order = $this->sentOrder();

        $this->get(route('admin.purchase-orders.index'))->assertOk()->assertSee($order->po_no);
        $this->get(route('admin.suppliers.index'))->assertOk()->assertSee('Nile Pharma Distributors');
        $this->get(route('admin.purchase-orders.show', $order))->assertOk()->assertSee('Receive goods');
        $pdf = $this->get(route('admin.purchase-orders.pdf', $order))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $this->actingAsRole('doctor', $this->hospital);
        $this->get(route('admin.purchase-orders.index'))->assertForbidden();
        $this->get(route('admin.suppliers.index'))->assertForbidden();
        $this->get(route('admin.purchase-orders.show', $order))->assertForbidden();
        $this->get(route('admin.purchase-orders.pdf', $order))->assertForbidden();

        Sanctum::actingAs(auth()->user());
        $this->getJson('/api/v1/purchase-orders')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/order")->assertForbidden();
    }

    // ── Screens ────────────────────────────────────────────────────────

    public function test_the_supplier_directory_creates_and_refuses_a_duplicate(): void
    {
        Livewire::test(SuppliersIndex::class)
            ->call('create')
            ->set('name', 'Kampala Medical Supplies')
            ->set('phone', '+256 700 111 222')
            ->set('payment_terms_days', '14')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(14, Supplier::where('name', 'Kampala Medical Supplies')->sole()->payment_terms_days);

        Livewire::test(SuppliersIndex::class)
            ->call('create')->set('name', 'Nile Pharma Distributors')->call('save')
            ->assertHasErrors(['name']);

        Livewire::test(SuppliersIndex::class)
            ->call('peek', $this->supplier->id)
            ->assertSee('Nothing has been ordered from this supplier yet.');
    }

    public function test_an_order_is_raised_filled_sent_received_and_returned_from_the_screens(): void
    {
        Livewire::test(OrdersIndex::class)
            ->call('openCreate')
            ->set('supplier_id', (string) $this->supplier->id)
            ->call('createOrder')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.purchase-orders.show', PurchaseOrder::sole()));

        $order = PurchaseOrder::sole();

        $page = Livewire::test(OrderShow::class, ['purchaseOrder' => $order])
            ->call('send')
            ->assertHasErrors(['status'])
            ->call('picked', 'po_stock_item_id', $this->paracetamol->id)
            ->assertSet('line_cost', '100')
            ->set('line_quantity', '30')
            ->call('addLine')
            ->assertHasNoErrors()
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(PurchaseOrderStatus::Ordered, $order->fresh()->status);
        $this->assertSame('3000.00', (string) $order->fresh()->total);

        $lineId = $order->items()->value('id');
        $page->call('openReceive')
            ->assertSet("receiving.{$lineId}.quantity", '30')
            ->set("receiving.{$lineId}.quantity", '31')
            ->call('receive')
            ->assertHasErrors(["receiving.{$lineId}.quantity"])
            ->set("receiving.{$lineId}.quantity", '30')
            ->set("receiving.{$lineId}.batch_no", 'PCM-9')
            ->call('receive')
            ->assertHasNoErrors()
            ->assertSet('showReceive', false);

        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame('30.00', (string) $this->paracetamol->fresh()->current_quantity);

        $grnLine = GoodsReceiptItem::sole();
        $page->call('openReturn', $grnLine->id)
            ->set('return_quantity', '5')
            ->set('return_reason', 'Damaged')
            ->call('sendBack')
            ->assertHasNoErrors()
            ->assertSee('SR-');

        $this->assertSame('25.00', (string) $this->paracetamol->fresh()->current_quantity);
    }

    public function test_a_reader_without_store_rights_cannot_act_on_an_order(): void
    {
        $order = $this->sentOrder();
        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $reader->syncSpatieRole();
        $reader->givePermissionTo('pharmacy.view');
        $this->actingAs($reader);

        Livewire::test(OrderShow::class, ['purchaseOrder' => $order])
            ->assertOk()
            ->assertDontSee('wire:click="openReceive"', false)
            ->call('openReceive')
            ->assertForbidden();
    }

    // ── API ────────────────────────────────────────────────────────────

    public function test_the_app_raises_sends_and_receives_an_order_and_a_retried_delivery_is_received_once(): void
    {
        Sanctum::actingAs($this->pharmacist);

        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Quality Meds Ltd', 'phone' => '0700000000'])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/suppliers', ['name' => 'Quality Meds Ltd'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $order = $this->postJson('/api/v1/purchase-orders', [
            'supplier_uuid' => $supplier['uuid'],
            'lines' => [['stock_item_id' => $this->paracetamol->id, 'quantity' => 10, 'unit_cost' => 90]],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '900.00')->json('data');

        $this->postJson("/api/v1/purchase-orders/{$order['uuid']}/order")->assertOk()->assertJsonPath('data.status', 'ordered')
            ->assertJsonPath('data.can.receive', true);

        $body = ['supplier_invoice_no' => 'Q-1', 'lines' => [['purchase_order_item_id' => $order['lines'][0]['id'], 'quantity' => 10, 'batch_no' => 'X1', 'expiry_date' => now()->addYear()->toDateString()]]];
        $key = (string) Str::uuid();
        $first = $this->postJson("/api/v1/purchase-orders/{$order['uuid']}/receive", $body, ['Idempotency-Key' => $key])->assertCreated();
        $again = $this->postJson("/api/v1/purchase-orders/{$order['uuid']}/receive", $body, ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame($first->json('data.receipt.grn_no'), $again->json('data.receipt.grn_no'));
        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame('10.00', (string) $this->paracetamol->fresh()->current_quantity);
        $first->assertJsonPath('data.order.status', 'received')->assertJsonPath('data.receipt.lines.0.batch_no', 'X1');

        // Over-receipt is a 422 in the standard envelope.
        $this->postJson("/api/v1/purchase-orders/{$order['uuid']}/receive", $body, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $return = $this->postJson("/api/v1/purchase-orders/{$order['uuid']}/returns", [
            'goods_receipt_item_id' => $first->json('data.receipt.lines.0.id'), 'quantity' => 2, 'reason' => 'Damaged',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $return->assertJsonPath('data.return.total', '180.00')->assertJsonPath('data.order.figures.returned', '180.00');

        $this->getJson('/api/v1/purchase-orders?status=received')->assertOk()->assertJsonPath('data.0.po_no', $order['po_no'])->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/suppliers/{$supplier['uuid']}")->assertOk()->assertJsonPath('data.figures.received', '900.00');
        $this->get("/api/v1/purchase-orders/{$order['uuid']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_app_must_send_a_key_for_a_delivery(): void
    {
        $order = $this->sentOrder();
        Sanctum::actingAs($this->pharmacist);

        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", ['lines' => [['purchase_order_item_id' => $order->items()->value('id'), 'quantity' => 1]]], ['X-App-Version' => '9.9.9'])
            ->assertStatus(422)->assertJsonPath('errors.Idempotency-Key.0', 'The Idempotency-Key header is required.');
        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_the_api_asks_for_a_sign_in(): void
    {
        auth()->logout();
        app(CurrentHospital::class)->set(null);

        $this->getJson('/api/v1/purchase-orders')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    // ── Demo ───────────────────────────────────────────────────────────

    public function test_the_demo_scenario_is_consistent_and_safe_to_run_twice(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $demo = Hospital::where('slug', 'general-hospital-a')->firstOrFail();

        \Database\Seeders\DemoScenarios\Procurement::run($demo);
        \Database\Seeders\DemoScenarios\Procurement::run($demo);

        app(CurrentHospital::class)->set($demo->id);
        $this->assertSame(2, Supplier::count());
        $this->assertSame(4, PurchaseOrder::count());
        $this->assertSame(
            ['draft', 'ordered', 'part_received', 'received'],
            PurchaseOrder::pluck('status')->map(fn ($s) => $s->value)->sort()->values()->all(),
        );
        $this->assertSame(1, SupplierReturn::count());

        // The shelf and the ledger agree for every item a delivery touched.
        foreach (StockItem::all() as $item) {
            $last = StockMovement::where('stock_item_id', $item->id)->latest('id')->first();
            if ($last !== null) {
                $this->assertSame((string) $item->current_quantity, (string) $last->balance_after, $item->name);
            }
        }
        $this->assertTrue(GoodsReceipt::where('received_on', '<=', now()->toDateString())->count() === 2);
    }
}
