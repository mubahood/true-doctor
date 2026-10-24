<?php

namespace Tests\Feature\Api;

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
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One line of an order corrected in place through the API — the order
 * dialog's pencil (OrderDetail::saveItem), through the same
 * OrderService::updateItem: the quantity (a product's moves the shelf), the
 * note, and a ranged service's price inside its range (docs/api/orders-and-bill.md).
 */
class OrderLineEditApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Visit $visit;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        [$this->h, $this->visit, $this->doctor] = $this->clinic();
        Sanctum::actingAs($this->doctor);
    }

    /** @return array{0:Hospital,1:Visit,2:User} */
    private function clinic(array $attrs = []): array
    {
        $h = Hospital::factory()->create($attrs);
        app(CurrentHospital::class)->set($h->id);
        $doctor = $this->staff('doctor', $h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $visit = app(VisitService::class)->open(['patient_id' => $patient->id, 'doctor_user_id' => $doctor->id]);

        return [$h, $visit, $doctor];
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function service(string $name, string $price, ?string $max = null): Service
    {
        return Service::factory()->create(['hospital_id' => $this->h->id, 'name' => $name, 'price' => $price, 'max_price' => $max, 'is_active' => true]);
    }

    private function drug(string $onHand = '20'): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->h->id, 'name' => 'Amoxicillin 250mg', 'unit' => 'tablets',
            'sale_price' => '500', 'cost_price' => '200', 'current_quantity' => $onHand, 'original_quantity' => $onHand, 'is_active' => true,
        ]);
    }

    private function order(string $title = 'Wound care'): Order
    {
        return app(OrderService::class)->place($this->visit, OrderType::Procedure, $title, [], $this->doctor->id);
    }

    private function url(Order $order, OrderItem $line): string
    {
        return "/api/v1/orders/{$order->uuid}/items/{$line->id}";
    }

    public function test_a_service_line_takes_a_new_quantity_and_note(): void
    {
        $order = $this->order();
        $line = app(OrderService::class)->addServiceItem($order, $this->service('Dressing', '15000'), '1', $this->doctor->id);

        $res = $this->patchJson($this->url($order, $line), ['quantity' => '2', 'notes' => 'Two wounds'])
            ->assertOk()->assertJsonPath('message', 'Line updated.')
            ->assertJsonPath('data.items.0.quantity', '2.00')
            ->assertJsonPath('data.items.0.line_total', '30000.00')
            ->assertJsonPath('data.items.0.notes', 'Two wounds')
            ->assertJsonPath('data.total', '30000.00');
        $this->assertSame($this->doctor->name, $res->json('data.items.0.changed_by'), 'a corrected line says who corrected it');

        // A field left out keeps what the line has.
        $this->patchJson($this->url($order, $line), ['quantity' => '3'])->assertOk()->assertJsonPath('data.items.0.notes', 'Two wounds');
    }

    public function test_a_product_quantity_moves_the_shelf_both_ways(): void
    {
        $drug = $this->drug('20');
        $order = $this->order('Dispensing');
        $line = app(OrderService::class)->addProductItem($order, $drug, '4', $this->doctor->id, true);
        $this->assertSame('16.00', (string) $drug->fresh()->current_quantity);

        $this->patchJson($this->url($order, $line), ['quantity' => '6'])->assertOk()->assertJsonPath('data.items.0.line_total', '3000.00');
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity, 'two more left the shelf');

        $this->patchJson($this->url($order, $line), ['quantity' => '1'])->assertOk();
        $this->assertSame('19.00', (string) $drug->fresh()->current_quantity, 'five went back');
    }

    public function test_more_than_the_shelf_holds_is_refused_by_the_quantity(): void
    {
        $drug = $this->drug('5');
        $order = $this->order('Dispensing');
        $line = app(OrderService::class)->addProductItem($order, $drug, '2', $this->doctor->id, true);

        $this->patchJson($this->url($order, $line), ['quantity' => '50'])
            ->assertStatus(422)->assertJsonPath('errors.quantity.0', fn ($m) => str_contains($m, 'Not enough Amoxicillin 250mg in stock'));
        $this->assertSame('3.00', (string) $drug->fresh()->current_quantity, 'nothing moved');
        $this->assertSame('2.00', (string) $line->fresh()->quantity);
    }

    public function test_a_ranged_service_moves_only_inside_its_range(): void
    {
        $order = $this->order();
        $range = $this->service('Scaling', '50000', '80000');
        $line = app(OrderService::class)->addServiceItem($order, $range, '1', $this->doctor->id);

        $shown = $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
        $shown->assertJsonPath('data.items.0.price_range', ['from' => '50000.00', 'to' => '80000.00']);

        $this->patchJson($this->url($order, $line), ['quantity' => '1', 'unit_price' => '90000'])
            ->assertStatus(422)->assertJsonPath('errors.unit_price.0', fn ($m) => str_contains($m, 'is charged between'));
        $this->patchJson($this->url($order, $line), ['quantity' => '1', 'unit_price' => '65000'])
            ->assertOk()->assertJsonPath('data.items.0.unit_price', '65000.00')->assertJsonPath('data.items.0.line_total', '65000.00');
    }

    public function test_a_fixed_price_is_not_changed_here(): void
    {
        $order = $this->order();
        $line = app(OrderService::class)->addServiceItem($order, $this->service('Dressing', '15000'), '1', $this->doctor->id);

        $this->getJson("/api/v1/orders/{$order->uuid}")->assertJsonPath('data.items.0.price_range', null);
        $this->patchJson($this->url($order, $line), ['quantity' => '1', 'unit_price' => '9000'])
            ->assertStatus(422)->assertJsonPath('errors.unit_price.0', 'Dressing has a fixed price, so its price cannot be changed here.');
        // The price it already has is no change at all.
        $this->patchJson($this->url($order, $line), ['quantity' => '1', 'unit_price' => '15000'])->assertOk();
        $this->assertSame('15000.00', (string) $line->fresh()->unit_price);
    }

    public function test_validation_speaks_by_the_field(): void
    {
        $order = $this->order();
        $line = app(OrderService::class)->addServiceItem($order, $this->service('Dressing', '15000'), '1', $this->doctor->id);

        $this->patchJson($this->url($order, $line), ['quantity' => '0'])->assertStatus(422)->assertJsonPath('errors.quantity.0', 'Quantity must be more than zero.');
        $this->patchJson($this->url($order, $line), ['quantity' => '1e3'])->assertStatus(422)->assertJsonValidationErrors(['quantity'], 'errors');
        $this->patchJson($this->url($order, $line), ['quantity' => '1', 'notes' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors(['notes'], 'errors');
        $this->patchJson($this->url($order, $line), ['unit_price' => '-1'])->assertStatus(422)->assertJsonValidationErrors(['unit_price'], 'errors');
    }

    public function test_an_invoiced_visit_and_a_removed_line_are_refused_with_the_reason(): void
    {
        $order = $this->order();
        $kept = app(OrderService::class)->addServiceItem($order, $this->service('Dressing', '15000'), '1', $this->doctor->id);
        $gone = app(OrderService::class)->addServiceItem($order, $this->service('Suture', '5000'), '1', $this->doctor->id);
        app(OrderService::class)->removeItem($gone, $this->doctor->id);

        $this->patchJson($this->url($order, $gone), ['quantity' => '2'])
            ->assertStatus(422)->assertJsonPath('message', 'That line was removed, so it cannot be changed.');

        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->doctor->id);
        $this->patchJson($this->url($order, $kept), ['quantity' => '2'])
            ->assertStatus(422)->assertJsonPath('message', 'This visit has been invoiced, so its charges can no longer be changed.');
        $this->assertSame('1.00', (string) $kept->fresh()->quantity);
    }

    public function test_someone_who_cannot_put_work_on_a_visit_is_refused(): void
    {
        $order = $this->order();
        $line = app(OrderService::class)->addServiceItem($order, $this->service('Dressing', '15000'), '1', $this->doctor->id);

        Sanctum::actingAs($this->staff('nurse'));
        $this->patchJson($this->url($order, $line), ['quantity' => '2'])->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->assertSame('1.00', (string) $line->fresh()->quantity);
    }

    public function test_another_hospitals_order_and_a_line_of_another_order_are_not_found(): void
    {
        $order = $this->order();
        $line = app(OrderService::class)->addServiceItem($order, $this->service('Dressing', '15000'), '1', $this->doctor->id);
        $other = $this->order('Second');
        $this->patchJson("/api/v1/orders/{$other->uuid}/items/{$line->id}", ['quantity' => '2'])->assertNotFound();

        [, $visitB, $doctorB] = $this->clinic();
        $orderB = app(OrderService::class)->place($visitB, OrderType::Procedure, 'Elsewhere', [], $doctorB->id);
        $lineB = app(OrderService::class)->addServiceItem($orderB, Service::factory()->create(['hospital_id' => $visitB->hospital_id, 'price' => '100']), '1', $doctorB->id);

        app(CurrentHospital::class)->set($this->h->id);
        Sanctum::actingAs($this->doctor);
        $this->patchJson("/api/v1/orders/{$orderB->uuid}/items/{$lineB->id}", ['quantity' => '2'])->assertNotFound();
        $this->assertSame('1.00', (string) OrderItem::withoutGlobalScopes()->find($lineB->id)->quantity);
    }

    public function test_a_replay_with_the_same_key_moves_the_shelf_once(): void
    {
        $drug = $this->drug('20');
        $order = $this->order('Dispensing');
        $line = app(OrderService::class)->addProductItem($order, $drug, '2', $this->doctor->id, true);

        $first = $this->patchJson($this->url($order, $line), ['quantity' => '5'], ['Idempotency-Key' => 'line-k1'])->assertOk();
        $second = $this->patchJson($this->url($order, $line), ['quantity' => '5'], ['Idempotency-Key' => 'line-k1'])
            ->assertOk()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame('15.00', (string) $drug->fresh()->current_quantity, 'three left the shelf, once');
    }

    public function test_the_app_must_name_its_attempt(): void
    {
        $order = $this->order();
        $line = app(OrderService::class)->addServiceItem($order, $this->service('Dressing', '15000'), '1', $this->doctor->id);

        $this->patchJson($this->url($order, $line), ['quantity' => '2'], ['X-App-Version' => '9.9.9'])
            ->assertStatus(422)->assertJsonPath('errors.Idempotency-Key.0', 'The Idempotency-Key header is required.');
    }

    public function test_the_order_carries_the_practice_words_and_where_a_product_starts(): void
    {
        [$this->h, $this->visit, $this->doctor] = $this->clinic(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        Sanctum::actingAs($this->doctor);
        $order = $this->order('Filling');

        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk()
            ->assertJsonPath('data.labels.kind', 'Treatment')
            ->assertJsonPath('data.labels.assignee', 'Dentist assigned')
            ->assertJsonPath('data.products_billed', false)
            ->assertJsonPath('data.charge_swappable', true);
        $this->getJson("/api/v1/visits/{$this->visit->uuid}/orders")->assertOk()
            ->assertJsonPath('data.labels.title', 'Service')->assertJsonPath('data.products_billed', false);
        $this->getJson('/api/v1/orders/catalogue?type=procedure')->assertOk()->assertJsonPath('data.labels.kind', 'Treatment');

        // A general hospital: its own words, and products are billed.
        [$this->h, $this->visit, $this->doctor] = $this->clinic();
        Sanctum::actingAs($this->doctor);
        $order = $this->order('Dressing');
        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk()
            ->assertJsonPath('data.labels.kind', 'Type')->assertJsonPath('data.labels.assignee', 'Clinician assigned')
            ->assertJsonPath('data.products_billed', true);
    }

    public function test_a_ranged_service_carries_its_top_price_in_the_picker(): void
    {
        $this->service('Scaling', '50000', '80000');
        $this->service('Dressing', '15000');

        $rows = collect($this->getJson('/api/v1/orders/lines?kind=service')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame('80000.00', $rows['Scaling']['max_price']);
        $this->assertNull($rows['Dressing']['max_price']);
    }
}
