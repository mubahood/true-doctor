<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\DispensationService;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The bill at the counter through the API — the visit's Charges panel, through
 * the same BillingService: the lines with the order behind each and whether it
 * is billed, Add charge, the discount, putting the bill back in step; who may
 * do which; and the dispensing list's billed marks (docs/api/orders-and-bill.md).
 */
class CounterBillApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Visit $visit;

    private User $clerk;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->clinic();
    }

    private function clinic(array $attrs = []): void
    {
        $this->h = Hospital::factory()->create($attrs);
        app(CurrentHospital::class)->set($this->h->id);
        $this->doctor = $this->staff('doctor');
        $this->clerk = $this->staff('receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $this->visit = app(VisitService::class)->open(['patient_id' => $patient->id, 'doctor_user_id' => $this->doctor->id]);
        Sanctum::actingAs($this->clerk);
    }

    private function staff(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function service(string $name = 'Consultation', string $price = '20000', ?string $max = null): Service
    {
        return Service::factory()->create(['hospital_id' => $this->h->id, 'name' => $name, 'price' => $price, 'max_price' => $max, 'is_active' => true]);
    }

    private function drug(string $onHand = '20', string $price = '500'): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->h->id, 'name' => 'Composite resin', 'unit' => 'capsule',
            'sale_price' => $price, 'cost_price' => '200', 'current_quantity' => $onHand, 'original_quantity' => $onHand, 'is_active' => true,
        ]);
    }

    private function bill(): string
    {
        return "/api/v1/visits/{$this->visit->uuid}/bill";
    }

    // ── Reading ──────────────────────────────────────────────────────────

    public function test_the_bill_carries_each_line_with_its_order_and_whether_it_is_billed(): void
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Procedure, 'Filling', [], $this->doctor->id);
        app(OrderService::class)->addServiceItem($order, $this->service('Filling', '40000'), '1', $this->doctor->id);
        app(OrderService::class)->addProductItem($order, $this->drug(), '2', $this->doctor->id, false);
        $gone = app(OrderService::class)->addServiceItem($order, $this->service('X-ray', '10000'), '1', $this->doctor->id);
        app(OrderService::class)->removeItem($gone, $this->doctor->id);

        // Newest first, as the panel lists them.
        $res = $this->getJson($this->bill())->assertOk();
        $res->assertJsonPath('data.lines.1.name', 'Filling')
            ->assertJsonPath('data.lines.1.kind', 'service')
            ->assertJsonPath('data.lines.1.order.uuid', $order->uuid)
            ->assertJsonPath('data.lines.1.order.title', 'Filling')
            ->assertJsonPath('data.lines.1.changeable', true)
            ->assertJsonPath('data.lines.0.name', 'Composite resin')
            ->assertJsonPath('data.lines.0.kind', 'product')
            ->assertJsonPath('data.lines.0.billed', false)
            ->assertJsonPath('data.lines.0.line_total', '0.00')
            ->assertJsonPath('data.removed_lines.0.name', 'X-ray')
            ->assertJsonPath('data.totals.subtotal', '40000.00')
            ->assertJsonPath('data.discount.type', 'amount')
            ->assertJsonPath('data.discount.amount', '0.00')
            ->assertJsonPath('data.products_billed', true)
            ->assertJsonPath('data.can_add', true)
            ->assertJsonPath('data.can_discount', true)
            ->assertJsonPath('data.can_rebill', true);
        $this->assertCount(2, $res->json('data.lines'), 'a removed line is never listed as a charge');
        $this->assertIsString($res->json('data.tax_label'));
    }

    public function test_the_bill_is_read_as_the_panel_is(): void
    {
        $this->service();
        // A doctor sees the bills of their own patients only (VisitPolicy::viewBill).
        Sanctum::actingAs($this->doctor);
        $this->getJson($this->bill())->assertOk()->assertJsonPath('data.can_discount', false)->assertJsonPath('data.can_add', true);
        Sanctum::actingAs($this->staff('doctor'));
        $this->getJson($this->bill())->assertForbidden();
        Sanctum::actingAs($this->staff('nurse'));
        $this->getJson($this->bill())->assertForbidden();
    }

    // ── Add charge ───────────────────────────────────────────────────────

    public function test_a_service_added_here_gets_its_own_completed_order(): void
    {
        $svc = $this->service('Consultation', '20000');

        $res = $this->postJson("{$this->bill()}/lines", ['kind' => 'service', 'service_id' => $svc->id, 'quantity' => '2', 'note' => 'Review and a dressing'])
            ->assertCreated()->assertJsonPath('message', 'Added to the bill.')
            ->assertJsonPath('data.lines.0.name', 'Consultation')
            ->assertJsonPath('data.lines.0.line_total', '40000.00')
            ->assertJsonPath('data.lines.0.notes', 'Review and a dressing')
            ->assertJsonPath('data.totals.due', '40000.00');

        $order = Order::where('uuid', $res->json('data.lines.0.order.uuid'))->firstOrFail();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(OrderType::Consultation, $order->type);
    }

    public function test_a_product_leaves_the_shelf_and_may_be_given_without_billing(): void
    {
        $drug = $this->drug('20');

        $this->postJson("{$this->bill()}/lines", ['kind' => 'product', 'stock_item_id' => $drug->id, 'quantity' => '3', 'billed' => false])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.kind', 'product')
            ->assertJsonPath('data.lines.0.billed', false)
            ->assertJsonPath('data.totals.due', '0.00');
        $this->assertSame('17.00', (string) $drug->fresh()->current_quantity, 'Not billed still leaves the shelf');

        // Left out, the hospital's choice: a general hospital bills it.
        $this->postJson("{$this->bill()}/lines", ['kind' => 'product', 'stock_item_id' => $drug->id, 'quantity' => '2'])
            ->assertCreated()->assertJsonPath('data.lines.0.billed', true)->assertJsonPath('data.lines.1.billed', false)->assertJsonPath('data.totals.due', '1000.00');
    }

    public function test_a_dental_clinic_starts_products_at_not_billed(): void
    {
        $this->clinic(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        $drug = $this->drug('20');

        $this->getJson($this->bill())->assertOk()->assertJsonPath('data.products_billed', false);
        $this->postJson("{$this->bill()}/lines", ['kind' => 'product', 'stock_item_id' => $drug->id, 'quantity' => '1'])
            ->assertCreated()->assertJsonPath('data.lines.0.billed', false);
        $this->getJson("/api/v1/visits/{$this->visit->uuid}/dispensations")->assertOk()->assertJsonPath('meta.products_billed', false);
    }

    public function test_a_ranged_service_asks_where_in_the_range(): void
    {
        $svc = $this->service('Scaling', '50000', '80000');
        $url = "{$this->bill()}/lines";

        $this->postJson($url, ['kind' => 'service', 'service_id' => $svc->id, 'quantity' => '1'])
            ->assertStatus(422)->assertJsonPath('errors.unit_price.0', 'Say what to charge within the range.');
        $this->postJson($url, ['kind' => 'service', 'service_id' => $svc->id, 'quantity' => '1', 'unit_price' => '90000'])
            ->assertStatus(422)->assertJsonPath('errors.unit_price.0', fn ($m) => str_starts_with($m, 'Charge between'));
        $this->postJson($url, ['kind' => 'service', 'service_id' => $svc->id, 'quantity' => '1', 'unit_price' => '65000'])
            ->assertCreated()->assertJsonPath('data.lines.0.unit_price', '65000.00');
    }

    public function test_add_charge_refuses_what_the_panel_refuses(): void
    {
        $url = "{$this->bill()}/lines";
        $this->postJson($url, ['kind' => 'service', 'quantity' => '1'])->assertStatus(422)->assertJsonPath('errors.service_id.0', 'Choose a service.');
        $this->postJson($url, ['kind' => 'product', 'quantity' => '1'])->assertStatus(422)->assertJsonPath('errors.stock_item_id.0', 'Choose a product.');
        $this->postJson($url, ['kind' => 'service', 'service_id' => $this->service()->id, 'quantity' => '0'])->assertStatus(422)->assertJsonPath('errors.quantity.0', 'Quantity must be more than zero.');
        $this->postJson($url, ['kind' => 'thing', 'quantity' => '1'])->assertStatus(422)->assertJsonValidationErrors(['kind'], 'errors');
        $inactive = Service::factory()->create(['hospital_id' => $this->h->id, 'is_active' => false]);
        $this->postJson($url, ['kind' => 'service', 'service_id' => $inactive->id, 'quantity' => '1'])->assertStatus(422)->assertJsonPath('errors.service_id.0', 'That service is not on the price list.');

        $drug = $this->drug('2');
        $this->postJson($url, ['kind' => 'product', 'stock_item_id' => $drug->id, 'quantity' => '5', 'billed' => true])
            ->assertStatus(422)->assertJsonPath('errors.stock_item_id.0', fn ($m) => str_contains($m, 'Not enough Composite resin in stock'));
        $this->assertSame('2.00', (string) $drug->fresh()->current_quantity);
        $this->assertSame(0, OrderItem::count(), 'nothing was billed');
    }

    public function test_nothing_is_added_once_the_visit_is_invoiced(): void
    {
        $svc = $this->service();
        app(BillingService::class)->orderService($this->visit, $svc->id, 1, $this->clerk->id);
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->clerk->id);

        $this->getJson($this->bill())->assertJsonPath('data.can_add', false)->assertJsonPath('data.can_discount', false)
            ->assertJsonPath('data.can_rebill', false)->assertJsonPath('data.lines.0.changeable', false);
        $this->postJson("{$this->bill()}/lines", ['kind' => 'service', 'service_id' => $svc->id, 'quantity' => '1'])
            ->assertStatus(422)->assertJsonPath('message', 'This visit has been invoiced, so its charges can no longer be changed.');
        $this->assertSame(1, OrderItem::count());
    }

    public function test_the_web_panel_refuses_a_charge_on_an_invoiced_visit_too(): void
    {
        $svc = $this->service();
        app(BillingService::class)->orderService($this->visit, $svc->id, 1, $this->clerk->id);
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->clerk->id);

        $this->expectException(\RuntimeException::class);
        app(BillingService::class)->addCharge($this->visit, 'service', $svc->id, '1', $this->clerk->id);
    }

    public function test_writing_needs_billing_manage_and_reading_the_bill(): void
    {
        $svc = $this->service();
        $reader = $this->staff('nurse');
        $reader->givePermissionTo('billing.view');
        Sanctum::actingAs($reader);

        $this->getJson($this->bill())->assertOk()->assertJsonPath('data.can_add', false);
        $this->postJson("{$this->bill()}/lines", ['kind' => 'service', 'service_id' => $svc->id, 'quantity' => '1'])->assertForbidden();
        $this->putJson("{$this->bill()}/discount", ['type' => 'amount', 'value' => '100'])->assertForbidden();
        $this->postJson("{$this->bill()}/rebill")->assertForbidden();

        // A doctor charges their own patients' bills but gives no discounts.
        Sanctum::actingAs($this->doctor);
        $this->postJson("{$this->bill()}/lines", ['kind' => 'service', 'service_id' => $svc->id, 'quantity' => '1'])->assertCreated();
        $this->putJson("{$this->bill()}/discount", ['type' => 'amount', 'value' => '100'])->assertForbidden();
    }

    public function test_another_hospitals_visit_is_not_found(): void
    {
        $mine = $this->service();
        $hospitalA = $this->h;
        $clerkA = $this->clerk;

        $this->clinic();
        $visitB = $this->visit;

        app(CurrentHospital::class)->set($hospitalA->id);
        Sanctum::actingAs($clerkA);
        $this->getJson("/api/v1/visits/{$visitB->uuid}/bill")->assertNotFound();
        $this->postJson("/api/v1/visits/{$visitB->uuid}/bill/lines", ['kind' => 'service', 'service_id' => $mine->id, 'quantity' => '1'])->assertNotFound();
        $this->putJson("/api/v1/visits/{$visitB->uuid}/bill/discount", ['type' => 'amount', 'value' => '1'])->assertNotFound();
        $this->postJson("/api/v1/visits/{$visitB->uuid}/bill/rebill")->assertNotFound();
        $this->assertSame(0, OrderItem::withoutGlobalScopes()->count());
    }

    public function test_another_hospitals_service_cannot_be_charged_here(): void
    {
        $visitA = $this->visit;
        $clerkA = $this->clerk;
        $hospitalA = $this->h;
        $this->clinic();
        $theirs = $this->service('Theirs', '999');

        app(CurrentHospital::class)->set($hospitalA->id);
        Sanctum::actingAs($clerkA);
        $this->postJson("/api/v1/visits/{$visitA->uuid}/bill/lines", ['kind' => 'service', 'service_id' => $theirs->id, 'quantity' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors(['service_id'], 'errors');
    }

    public function test_a_replayed_charge_is_added_once(): void
    {
        $drug = $this->drug('20');
        $body = ['kind' => 'product', 'stock_item_id' => $drug->id, 'quantity' => '2', 'billed' => true];

        $first = $this->postJson("{$this->bill()}/lines", $body, ['Idempotency-Key' => 'charge-1'])->assertCreated();
        $second = $this->postJson("{$this->bill()}/lines", $body, ['Idempotency-Key' => 'charge-1'])->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, OrderItem::count());
        $this->assertSame('18.00', (string) $drug->fresh()->current_quantity);
        $this->postJson("{$this->bill()}/lines", $body, ['X-App-Version' => '9.9.9'])->assertStatus(422)->assertJsonPath('errors.Idempotency-Key.0', 'The Idempotency-Key header is required.');
    }

    // ── The discount ─────────────────────────────────────────────────────

    public function test_a_discount_is_agreed_with_its_reason_and_the_hand_that_gave_it(): void
    {
        app(BillingService::class)->orderService($this->visit, $this->service('Consultation', '8000')->id, 1, $this->clerk->id);

        $this->putJson("{$this->bill()}/discount", ['type' => 'amount', 'value' => '3000', 'reason' => 'Staff rate'])
            ->assertOk()->assertJsonPath('message', 'Discount saved.')
            ->assertJsonPath('data.discount.type', 'amount')
            ->assertJsonPath('data.discount.value', '3000.00')
            ->assertJsonPath('data.discount.reason', 'Staff rate')
            ->assertJsonPath('data.discount.by', $this->clerk->name)
            ->assertJsonPath('data.totals.discount', '3000.00')
            ->assertJsonPath('data.totals.due', '5000.00');

        $this->putJson("{$this->bill()}/discount", ['type' => 'percent', 'value' => '25'])
            ->assertOk()->assertJsonPath('data.discount.type', 'percent')->assertJsonPath('data.totals.discount', '2000.00');

        // Nothing clears it, and its trail with it.
        $this->putJson("{$this->bill()}/discount", ['type' => 'amount', 'value' => null])
            ->assertOk()->assertJsonPath('data.totals.discount', '0.00')->assertJsonPath('data.discount.by', null);
    }

    public function test_a_discount_the_bill_cannot_carry_is_refused_by_the_value(): void
    {
        app(BillingService::class)->orderService($this->visit, $this->service('Consultation', '8000')->id, 1, $this->clerk->id);

        $this->putJson("{$this->bill()}/discount", ['type' => 'amount', 'value' => '9000'])
            ->assertStatus(422)->assertJsonPath('errors.value.0', fn ($m) => str_starts_with($m, 'A discount cannot be more than the bill'));
        $this->putJson("{$this->bill()}/discount", ['type' => 'percent', 'value' => '120'])
            ->assertStatus(422)->assertJsonPath('errors.value.0', 'A discount cannot be more than 100%.');
        $this->putJson("{$this->bill()}/discount", ['type' => 'amount', 'value' => '-5'])->assertStatus(422)->assertJsonValidationErrors(['value'], 'errors');
        $this->putJson("{$this->bill()}/discount", ['type' => 'gift', 'value' => '5'])->assertStatus(422)->assertJsonValidationErrors(['type'], 'errors');

        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->clerk->id);
        $this->putJson("{$this->bill()}/discount", ['type' => 'amount', 'value' => '100'])
            ->assertStatus(422)->assertJsonPath('errors.value.0', 'This visit has been invoiced — its discount is on the invoice now.');
    }

    public function test_a_replayed_discount_is_one_change(): void
    {
        app(BillingService::class)->orderService($this->visit, $this->service('Consultation', '8000')->id, 1, $this->clerk->id);
        $body = ['type' => 'amount', 'value' => '1000', 'reason' => 'Goodwill'];

        $this->putJson("{$this->bill()}/discount", $body, ['Idempotency-Key' => 'disc-1'])->assertOk();
        $this->putJson("{$this->bill()}/discount", $body, ['Idempotency-Key' => 'disc-1'])->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame('1000.00', (string) $this->visit->fresh()->discount_value);
    }

    // ── Putting the bill back in step ────────────────────────────────────

    public function test_rebilling_puts_a_drifted_line_right_and_says_so(): void
    {
        $line = app(BillingService::class)->orderService($this->visit, $this->service('Consultation', '8000')->id, 2, $this->clerk->id);
        $line->forceFill(['line_total' => '1.00'])->save();

        $this->postJson("{$this->bill()}/rebill")->assertOk()
            ->assertJsonPath('message', '1 line(s) were out of step and have been put right.')
            ->assertJsonPath('data.rebill.fixed', 1)
            ->assertJsonPath('data.lines.0.line_total', '16000.00');

        $this->postJson("{$this->bill()}/rebill", [], ['Idempotency-Key' => 'rebill-2'])->assertOk()->assertJsonPath('message', 'The bill is in step — 1 line(s) checked.');
        $this->postJson("{$this->bill()}/rebill", [], ['Idempotency-Key' => 'rebill-2'])->assertOk()->assertHeader('Idempotent-Replay', 'true');

        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->clerk->id);
        $this->postJson("{$this->bill()}/rebill")->assertStatus(422)->assertJsonPath('message', 'This visit has been invoiced — its lines are fixed.');
    }

    // ── Dispensing ───────────────────────────────────────────────────────

    public function test_the_dispensing_list_says_which_lines_are_on_the_bill(): void
    {
        $pharmacist = $this->staff('pharmacist');
        $drug = $this->drug('20');
        app(DispensationService::class)->dispense($this->visit, [
            ['stock_item_id' => $drug->id, 'quantity' => '2', 'billed' => true],
            ['stock_item_id' => $drug->id, 'quantity' => '1', 'billed' => false],
        ], null, $pharmacist->id);

        Sanctum::actingAs($pharmacist);
        $this->getJson("/api/v1/visits/{$this->visit->uuid}/dispensations")->assertOk()
            ->assertJsonPath('meta.products_billed', true)
            ->assertJsonPath('data.0.items.0.billed', true)
            ->assertJsonPath('data.0.items.1.billed', false);
    }
}
