<?php

namespace Tests\Feature\Api;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Hospital;
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
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * What the phone's suppliers and purchase-order screens need from the API
 * (docs/api/procurement.md): the web directory's search and order counts, a
 * supplier's figures and orders, archiving a supplier, everything the order
 * page shows, and receipts, returns and new orders that a retry never doubles.
 */
class ProcurementAppApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private User $pharmacist;

    private Supplier $supplier;

    private StockItem $paracetamol;

    private StockItem $gloves;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = $this->hospital();
        $this->pharmacist = $this->actingAsRole('pharmacist', $this->hospital, ['name' => 'Store Keeper']);
        Sanctum::actingAs($this->pharmacist);
        $this->supplier = Supplier::create(['name' => 'Lake Medical Supplies', 'contact_person' => 'Grace Store', 'phone' => '0700 111 222', 'is_active' => true, 'payment_terms_days' => 30]);
        // Out of stock: what has run out is what gets ordered.
        $this->paracetamol = $this->item('Paracetamol 500mg', '0', '100.00', 'tabs');
        $this->gloves = $this->item('Examination gloves', '12', '2500.00', 'boxes');
    }

    private function item(string $name, string $qty, string $cost, string $unit): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => $name,
            'unit' => $unit,
            'current_quantity' => $qty,
            'cost_price' => $cost,
            'current_stock_value' => bcmul($qty, $cost, 2),
            'batch_no' => null,
            'expiry_date' => null,
            'is_active' => true,
        ]);
    }

    private function procurement(): ProcurementService
    {
        return app(ProcurementService::class);
    }

    /** An order sent to the supplier: 100 paracetamol at 90, 10 boxes of gloves at 2,400. */
    private function sentOrder(?string $expected = null): PurchaseOrder
    {
        $order = $this->procurement()->createOrder($this->supplier, ['expected_date' => $expected ?? now()->addWeek()->toDateString()], [
            ['stock_item_id' => $this->paracetamol->id, 'quantity' => '100', 'unit_cost' => '90'],
            ['stock_item_id' => $this->gloves->id, 'quantity' => '10', 'unit_cost' => '2400'],
        ], $this->pharmacist);

        return $this->procurement()->markOrdered($order, $this->pharmacist);
    }

    /** @return array<int,int> stock item id => order line id */
    private function lineIds(PurchaseOrder $order): array
    {
        return $order->items()->pluck('id', 'stock_item_id')->all();
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'X-App-Version' => '9.9.9'];
    }

    // ── Suppliers ──────────────────────────────────────────────────────

    public function test_the_directory_finds_a_supplier_by_name_contact_or_phone_and_counts_its_orders(): void
    {
        Supplier::create(['name' => 'Old Pharma Ltd', 'is_active' => false]);
        $this->sentOrder();

        $this->getJson('/api/v1/suppliers?q=Grace')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Lake Medical Supplies')
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/suppliers?q=111 222')->assertOk()->assertJsonPath('data.0.uuid', $this->supplier->uuid);
        $this->getJson('/api/v1/suppliers?q=Lake')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/suppliers?status=inactive')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Old Pharma Ltd')->assertJsonPath('data.0.orders_count', 0);
        $this->getJson('/api/v1/suppliers?status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_supplier_carries_its_figures_and_its_orders_are_one_filter_away(): void
    {
        $order = $this->sentOrder();
        $lines = $this->lineIds($order);
        $this->procurement()->receive($order, [], [['purchase_order_item_id' => $lines[$this->paracetamol->id], 'quantity' => '40']], $this->pharmacist);
        $other = Supplier::create(['name' => 'Other Supplies', 'is_active' => true]);
        $this->procurement()->createOrder($other, [], [], $this->pharmacist);

        $this->getJson("/api/v1/suppliers/{$this->supplier->uuid}")->assertOk()
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.figures.orders', 1)
            ->assertJsonPath('data.figures.open', 1)
            ->assertJsonPath('data.figures.received', '3600.00')
            ->assertJsonPath('data.figures.returned', '0.00')
            ->assertJsonPath('data.figures.received_label', \App\Support\HospitalSettings::money('3600.00'));

        $this->getJson("/api/v1/purchase-orders?supplier_uuid={$this->supplier->uuid}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.po_no', $order->po_no);
        $this->getJson("/api/v1/purchase-orders?supplier_uuid={$other->uuid}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'draft');
    }

    public function test_a_supplier_is_archived_but_never_one_with_orders(): void
    {
        $unused = Supplier::create(['name' => 'Never Used Ltd', 'is_active' => true]);
        $headers = $this->key();

        $this->deleteJson("/api/v1/suppliers/{$unused->uuid}", [], $headers)->assertOk()->assertJsonPath('message', 'Supplier archived.');
        $this->assertSoftDeleted('suppliers', ['id' => $unused->id]);
        // Sent again after a lost answer, it is already gone: a 404, and still archived
        // once (binding runs before the replay, as for every DELETE; the app reads it as done).
        $this->deleteJson("/api/v1/suppliers/{$unused->uuid}", [], $headers)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertSame(1, Supplier::onlyTrashed()->count());
        $this->getJson('/api/v1/suppliers?q=Never')->assertOk()->assertJsonCount(0, 'data');

        $this->sentOrder();
        $this->deleteJson("/api/v1/suppliers/{$this->supplier->uuid}", [], $this->key())
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', 'This supplier has purchase orders and cannot be deleted. Deactivate it instead.');
        $this->assertNotSoftDeleted('suppliers', ['id' => $this->supplier->id]);
    }

    // ── The order page ─────────────────────────────────────────────────

    public function test_the_full_order_carries_what_the_web_page_shows(): void
    {
        $order = $this->sentOrder(now()->subDays(2)->toDateString());
        $lines = $this->lineIds($order);

        $this->getJson('/api/v1/purchase-orders')->assertOk()->assertJsonPath('data.0.is_overdue', true);

        $receipt = $this->procurement()->receive($order, ['supplier_invoice_no' => 'LMS-77'], [
            ['purchase_order_item_id' => $lines[$this->gloves->id], 'quantity' => '6', 'batch_no' => 'G-1', 'expiry_date' => now()->addYear()->toDateString()],
        ], $this->pharmacist);
        $this->procurement()->returnToSupplier($receipt->items()->sole(), '2', 'Damaged', $this->pharmacist);

        $r = $this->getJson("/api/v1/purchase-orders/{$order->uuid}")->assertOk()
            ->assertJsonPath('data.status', 'part_received')
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.can.receive', true)
            ->assertJsonPath('data.can.close', true)
            ->assertJsonPath('data.can.cancel', false)
            ->assertJsonPath('data.lines.1.name', 'Examination gloves')
            ->assertJsonPath('data.lines.1.unit', 'boxes')
            ->assertJsonPath('data.lines.1.received_quantity', '6.00')
            ->assertJsonPath('data.lines.1.outstanding', '4.00')
            ->assertJsonPath('data.receipts.0.received_by', 'Store Keeper')
            ->assertJsonPath('data.receipts.0.supplier_invoice_no', 'LMS-77')
            ->assertJsonPath('data.receipts.0.lines.0.unit', 'boxes')
            ->assertJsonPath('data.receipts.0.lines.0.batch_no', 'G-1')
            ->assertJsonPath('data.receipts.0.lines.0.returned_quantity', '2.00')
            ->assertJsonPath('data.receipts.0.lines.0.returnable', '4.00')
            ->assertJsonPath('data.receipts.0.lines.0.returns.0.reason', 'Damaged')
            ->assertJsonPath('data.figures.received', '14400.00')
            ->assertJsonPath('data.figures.returned', '4800.00')
            ->assertJsonPath('data.ended_by', null);
        $this->assertStringEndsWith("/api/v1/purchase-orders/{$order->uuid}/pdf", $r->json('data.pdf_url'));

        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/close", ['reason' => 'The rest is not coming'])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.ended_by', 'Store Keeper')
            ->assertJsonPath('data.end_reason', 'The rest is not coming')
            ->assertJsonPath('data.lines.1.outstanding', '0.00');
    }

    public function test_an_item_that_has_run_out_can_be_ordered_and_a_line_added_twice_is_one_line(): void
    {
        $order = $this->postJson('/api/v1/purchase-orders', [
            'supplier_uuid' => $this->supplier->uuid,
            'expected_date' => now()->toDateString(),
            'notes' => 'Deliver to the back door',
            'lines' => [['stock_item_id' => $this->paracetamol->id, 'quantity' => '50']],
        ], $this->key())->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lines.0.unit_cost', '100.00') // the item's cost when none is given
            ->assertJsonPath('data.total', '5000.00')
            ->json('data');

        $this->postJson("/api/v1/purchase-orders/{$order['uuid']}/lines", ['stock_item_id' => $this->paracetamol->id, 'quantity' => '25', 'unit_cost' => '95'], $this->key())
            ->assertCreated()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.quantity', '75.00')
            ->assertJsonPath('data.total', '7125.00');

        $lineId = $order['lines'][0]['id'];
        $this->deleteJson("/api/v1/purchase-orders/{$order['uuid']}/lines/{$lineId}")->assertOk()->assertJsonCount(0, 'data.lines')->assertJsonPath('data.can.order', false);
        $this->postJson("/api/v1/purchase-orders/{$order['uuid']}/order")->assertStatus(422)->assertJsonPath('errors.status.0', 'Add at least one item before sending the order.');
    }

    // ── Retries ────────────────────────────────────────────────────────

    public function test_a_retried_delivery_is_received_once_even_after_it_completed_the_order(): void
    {
        $order = $this->sentOrder();
        $lines = $this->lineIds($order);
        $body = [
            'received_on' => now()->toDateString(),
            'supplier_invoice_no' => 'LMS-1',
            'lines' => [
                ['purchase_order_item_id' => $lines[$this->paracetamol->id], 'quantity' => '100', 'unit_cost' => '90', 'batch_no' => 'P-9', 'expiry_date' => now()->addYears(2)->toDateString()],
                ['purchase_order_item_id' => $lines[$this->gloves->id], 'quantity' => '10'],
            ],
        ];
        $headers = $this->key();

        $first = $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", $body, $headers)->assertCreated()
            ->assertJsonPath('data.order.status', 'received');
        // The answer was lost; the phone sends the same thing with the same key.
        $again = $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", $body, $headers)->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json('data.receipt.grn_no'), $again->json('data.receipt.grn_no'));
        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame(2, GoodsReceiptItem::count());
        $this->assertSame('100.00', (string) $this->paracetamol->fresh()->current_quantity);
        $this->assertSame('22.00', (string) $this->gloves->fresh()->current_quantity);
        $this->assertSame(1, StockMovement::where('stock_item_id', $this->paracetamol->id)->count());

        // A new attempt (a new key) is judged afresh: nothing is still to come.
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", $body, $this->key())
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(1, GoodsReceipt::count());
    }

    public function test_a_retried_part_delivery_never_takes_more_than_was_typed(): void
    {
        $order = $this->sentOrder();
        $lines = $this->lineIds($order);
        $body = ['lines' => [['purchase_order_item_id' => $lines[$this->paracetamol->id], 'quantity' => '30']]];
        $headers = $this->key();

        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", $body, $headers)->assertCreated()->assertJsonPath('data.order.status', 'part_received');
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", $body, $headers)->assertCreated();
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", $body, $headers)->assertCreated();

        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame('30.00', (string) $this->paracetamol->fresh()->current_quantity);
        $this->assertSame('30.00', (string) $order->items()->where('stock_item_id', $this->paracetamol->id)->value('received_quantity'));

        // The same key with a different body is refused, not received.
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", ['lines' => [['purchase_order_item_id' => $lines[$this->paracetamol->id], 'quantity' => '31']]], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
        $this->assertSame('30.00', (string) $this->paracetamol->fresh()->current_quantity);
    }

    public function test_a_retried_return_goes_back_once(): void
    {
        $order = $this->sentOrder();
        $lines = $this->lineIds($order);
        $receipt = $this->procurement()->receive($order, [], [['purchase_order_item_id' => $lines[$this->gloves->id], 'quantity' => '10']], $this->pharmacist);
        $line = $receipt->items()->sole();
        $body = ['goods_receipt_item_id' => $line->id, 'quantity' => '3', 'reason' => 'Wrong item'];
        $headers = $this->key();

        $first = $this->postJson("/api/v1/purchase-orders/{$order->uuid}/returns", $body, $headers)->assertCreated()
            ->assertJsonPath('data.order.receipts.0.lines.0.returnable', '7.00');
        $again = $this->postJson("/api/v1/purchase-orders/{$order->uuid}/returns", $body, $headers)->assertCreated();

        $this->assertSame($first->json('data.return.return_no'), $again->json('data.return.return_no'));
        $this->assertSame(1, SupplierReturn::count());
        $this->assertSame('19.00', (string) $this->gloves->fresh()->current_quantity);
    }

    public function test_a_retried_new_order_is_started_once(): void
    {
        $body = ['supplier_uuid' => $this->supplier->uuid, 'lines' => [['stock_item_id' => $this->gloves->id, 'quantity' => '5', 'unit_cost' => '2400']]];
        $headers = $this->key();

        $first = $this->postJson('/api/v1/purchase-orders', $body, $headers)->assertCreated();
        $again = $this->postJson('/api/v1/purchase-orders', $body, $headers)->assertCreated();

        $this->assertSame($first->json('data.uuid'), $again->json('data.uuid'));
        $this->assertSame(1, PurchaseOrder::count());

        $line = ['stock_item_id' => $this->paracetamol->id, 'quantity' => '20'];
        $lineKey = $this->key();
        $this->postJson("/api/v1/purchase-orders/{$first->json('data.uuid')}/lines", $line, $lineKey)->assertCreated();
        $this->postJson("/api/v1/purchase-orders/{$first->json('data.uuid')}/lines", $line, $lineKey)->assertCreated();
        $this->assertSame('20.00', (string) PurchaseOrder::sole()->items()->where('stock_item_id', $this->paracetamol->id)->value('quantity'));
    }

    // ── Refusals ───────────────────────────────────────────────────────

    public function test_validation_answers_in_the_envelope_beside_the_field(): void
    {
        $order = $this->sentOrder();
        $draft = $this->procurement()->createOrder($this->supplier, [], [], $this->pharmacist);

        $this->postJson('/api/v1/suppliers', ['phone' => '0700'])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['name']]);
        $this->putJson("/api/v1/suppliers/{$this->supplier->uuid}", ['email' => 'not an email'])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
        $this->putJson("/api/v1/suppliers/{$this->supplier->uuid}", ['payment_terms_days' => 400])->assertStatus(422)->assertJsonStructure(['errors' => ['payment_terms_days']]);

        // As the web's form: a delivery cannot be expected yesterday.
        $this->postJson('/api/v1/purchase-orders', ['supplier_uuid' => $this->supplier->uuid, 'expected_date' => now()->subDay()->toDateString()], $this->key())
            ->assertStatus(422)->assertJsonStructure(['errors' => ['expected_date']]);
        $zero = $this->postJson('/api/v1/purchase-orders', ['supplier_uuid' => $this->supplier->uuid, 'lines' => [['stock_item_id' => $this->gloves->id, 'quantity' => '0']]], $this->key())
            ->assertStatus(422)->assertJsonStructure(['errors' => ['lines.0.quantity']]);
        // In the web form's words, since the phone shows it as it is.
        $this->assertSame(['The quantity field must be greater than 0.'], $zero->json('errors')['lines.0.quantity']);
        $this->supplier->update(['is_active' => false]);
        $this->postJson('/api/v1/purchase-orders', ['supplier_uuid' => $this->supplier->uuid], $this->key())
            ->assertStatus(422)->assertJsonPath('errors.supplier_id.0', 'This supplier is inactive. Reactivate it before ordering from it.');
        $this->supplier->update(['is_active' => true]);

        $this->postJson("/api/v1/purchase-orders/{$draft->uuid}/lines", ['stock_item_id' => $this->gloves->id, 'quantity' => '-1'], $this->key())
            ->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/lines", ['stock_item_id' => $this->gloves->id, 'quantity' => '1'], $this->key())
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'Only a draft order can be changed. This one is ordered.');

        $line = $this->lineIds($order)[$this->paracetamol->id];
        $over = $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", ['lines' => [['purchase_order_item_id' => $line, 'quantity' => '101']]], $this->key())
            ->assertStatus(422);
        // Keyed by the line's position in what was sent, so the phone can put it beside that row.
        $this->assertSame(['Only 100 of Paracetamol 500mg is still to come.'], $over->json('errors')['lines.0.quantity']);
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", ['received_on' => now()->addDays(2)->toDateString(), 'lines' => [['purchase_order_item_id' => $line, 'quantity' => '1']]], $this->key())
            ->assertStatus(422)->assertJsonStructure(['errors' => ['received_on']]);
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", ['lines' => [['purchase_order_item_id' => $line, 'quantity' => '0']]], $this->key())
            ->assertStatus(422)->assertJsonPath('errors.lines.0', 'Enter how many of at least one item came in.');
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/returns", ['goods_receipt_item_id' => 1, 'quantity' => '1'], $this->key())
            ->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/cancel", [])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->postJson("/api/v1/purchase-orders/{$draft->uuid}/close", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('errors.status.0', 'An order that is draft cannot be closed.');

        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->fresh()->status);
    }

    public function test_reading_needs_the_store_and_acting_needs_store_rights(): void
    {
        $order = $this->sentOrder();
        $draft = $this->procurement()->createOrder($this->supplier, [], [], $this->pharmacist);

        $nurse = $this->actingAsRole('nurse', $this->hospital);
        Sanctum::actingAs($nurse);
        $this->getJson('/api/v1/suppliers')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/v1/suppliers/{$this->supplier->uuid}")->assertForbidden();
        $this->getJson('/api/v1/purchase-orders')->assertForbidden();
        $this->getJson("/api/v1/purchase-orders/{$order->uuid}")->assertForbidden();

        // A reader of the store (pharmacy.view) sees everything and changes nothing.
        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $reader->syncSpatieRole();
        $reader->givePermissionTo('pharmacy.view');
        Sanctum::actingAs($reader);
        $this->getJson('/api/v1/suppliers')->assertOk();
        $this->getJson("/api/v1/purchase-orders/{$order->uuid}")->assertOk();
        $this->get("/api/v1/purchase-orders/{$order->uuid}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $line = $this->lineIds($order)[$this->paracetamol->id];
        $refused = [
            ['POST', '/api/v1/suppliers', ['name' => 'New One']],
            ['PUT', "/api/v1/suppliers/{$this->supplier->uuid}", ['name' => 'Renamed']],
            ['DELETE', "/api/v1/suppliers/{$this->supplier->uuid}", []],
            ['POST', '/api/v1/purchase-orders', ['supplier_uuid' => $this->supplier->uuid]],
            ['POST', "/api/v1/purchase-orders/{$draft->uuid}/lines", ['stock_item_id' => $this->gloves->id, 'quantity' => '1']],
            ['POST', "/api/v1/purchase-orders/{$draft->uuid}/order", []],
            ['POST', "/api/v1/purchase-orders/{$order->uuid}/receive", ['lines' => [['purchase_order_item_id' => $line, 'quantity' => '1']]]],
            ['POST', "/api/v1/purchase-orders/{$order->uuid}/returns", ['goods_receipt_item_id' => 1, 'quantity' => '1', 'reason' => 'Damaged']],
            ['POST', "/api/v1/purchase-orders/{$order->uuid}/close", ['reason' => 'x']],
            ['POST', "/api/v1/purchase-orders/{$order->uuid}/cancel", ['reason' => 'x']],
        ];
        foreach ($refused as [$method, $uri, $body]) {
            $this->json($method, $uri, $body, $this->key())->assertForbidden()->assertJsonPath('code', 'forbidden');
        }

        $this->assertSame('Lake Medical Supplies', $this->supplier->fresh()->name);
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_another_hospitals_supplier_and_order_are_not_found(): void
    {
        $order = $this->sentOrder();
        $line = $this->lineIds($order)[$this->paracetamol->id];

        $other = $this->hospital();
        $stranger = $this->actingAsRole('pharmacist', $other);
        Sanctum::actingAs($stranger);

        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/suppliers/{$this->supplier->uuid}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->putJson("/api/v1/suppliers/{$this->supplier->uuid}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/v1/suppliers/{$this->supplier->uuid}", [], $this->key())->assertNotFound();
        $this->getJson("/api/v1/purchase-orders?supplier_uuid={$this->supplier->uuid}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/purchase-orders/{$order->uuid}")->assertNotFound();
        $this->get("/api/v1/purchase-orders/{$order->uuid}/pdf")->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/receive", ['lines' => [['purchase_order_item_id' => $line, 'quantity' => '1']]], $this->key())->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$order->uuid}/close", ['reason' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/purchase-orders', ['supplier_uuid' => $this->supplier->uuid], $this->key())->assertNotFound();

        app(CurrentHospital::class)->set($this->hospital->id);
        $this->assertNotSoftDeleted('suppliers', ['id' => $this->supplier->id]);
        $this->assertSame('Lake Medical Supplies', $this->supplier->fresh()->name);
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(1, PurchaseOrder::count());
    }
}
