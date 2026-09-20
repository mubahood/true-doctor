<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\LabService;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Orders — the one shape for every piece of work done for a patient.
 *
 * The rule the whole thing rests on: NOTHING IS BILLED EXCEPT AS AN ITEM ON AN
 * ORDER, AND NO ORDER EXISTS EXCEPT ON A VISIT. So the bill is a child of the
 * work, and cancelling the work takes the money with it — which is the leak the
 * old loose charges left open (docs/orders.md).
 */
class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Visit $visit;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $this->doctor->syncSpatieRole();

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = app(VisitService::class)->open([
            'patient_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
        ]);
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    // ── Placing work ─────────────────────────────────────────────────────

    public function test_an_order_is_raised_on_a_visit_and_starts_pending(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Lab, 'Malaria RDT', [], $this->doctor->id);

        $this->assertSame($this->visit->id, $order->visit_id);
        $this->assertSame($this->visit->patient_id, $order->patient_id);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(OrderType::Lab, $order->type);
        $this->assertSame($this->doctor->id, $order->requested_by);
    }

    /** Someone is always answerable, even before the work is routed. */
    public function test_an_unassigned_order_falls_to_the_visits_doctor(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Lab, 'Bloods', [], $this->doctor->id);

        $this->assertSame($this->doctor->id, $order->assigned_to);
    }

    public function test_an_order_must_say_what_it_is(): void
    {
        $this->expectException(RuntimeException::class);
        $this->orders()->place($this->visit, OrderType::Lab, '   ', [], $this->doctor->id);
    }

    // ── Moving it along ──────────────────────────────────────────────────

    public function test_work_moves_pending_to_in_progress_to_completed(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Lab, 'Bloods', [], $this->doctor->id);

        $order = $this->orders()->transition($order, OrderStatus::InProgress, $this->doctor->id);
        $this->assertNotNull($order->started_at);

        // Something has to have been recorded before it can be called done.
        $this->orders()->saveReport($order, 'No growth after 48 hours.');

        $order = $this->orders()->transition($order, OrderStatus::Completed, $this->doctor->id);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_finished_work_cannot_be_moved_again(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Lab, 'Bloods', [], $this->doctor->id);
        $this->orders()->saveReport($order, 'Done.');
        $order = $this->orders()->transition($order, OrderStatus::Completed, $this->doctor->id);

        $this->expectException(RuntimeException::class);
        $this->orders()->transition($order, OrderStatus::InProgress, $this->doctor->id);
    }

    // ── The money follows the work ───────────────────────────────────────

    /**
     * THE POINT OF THE WHOLE CHANGE. Cancelling a piece of work takes its
     * charges off the bill. Before orders, a cancelled lab order left its
     * charge stranded with nothing pointing at it.
     */
    public function test_cancelling_work_takes_its_money_off_the_bill(): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '30000']);
        $order = $this->orders()->place($this->visit, OrderType::Lab, 'Bloods', [], $this->doctor->id);
        app(BillingService::class)->addServiceLine($order, $service, 2, $this->doctor->id);

        $before = app(BillingService::class)->totalsFor($this->visit->fresh());
        $this->assertSame(0, bccomp($before['subtotal'], '60000.00', 2));

        $this->orders()->transition($order, OrderStatus::Cancelled, $this->doctor->id, 'Sample spoiled');

        $after = app(BillingService::class)->totalsFor($this->visit->fresh());
        $this->assertSame(0, bccomp($after['subtotal'], '0.00', 2), 'the cancelled work is still on the bill');
        $this->assertSame(OrderItemStatus::Cancelled, $order->items()->first()->status);
        $this->assertSame('Sample spoiled', $order->fresh()->cancel_reason);
    }

    public function test_completing_work_records_what_it_used(): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '15000']);
        $order = $this->orders()->place($this->visit, OrderType::Procedure, 'Wound dressing', [], $this->doctor->id);

        $this->orders()->complete($order, [[
            'service_id' => $service->id,
            'name' => $service->name,
            'unit_price' => (string) $service->price,
            'quantity' => 1,
        ]], $this->doctor->id);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(
            0,
            bccomp(app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal'], '15000.00', 2),
        );
    }

    // ── Nothing floats free ──────────────────────────────────────────────

    /** An item reaches a visit through its order, as a claim does through its invoice. */
    public function test_every_item_reaches_a_visit_through_its_order(): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '1000']);
        $order = $this->orders()->place($this->visit, OrderType::Consultation, 'Consultation', [], $this->doctor->id);
        app(BillingService::class)->addServiceLine($order, $service, 1, $this->doctor->id);

        $this->assertSame(0, OrderItem::whereNull('order_id')->count());
        $this->assertSame(0, Order::whereNull('visit_id')->count());
        $this->assertSame($this->visit->id, OrderItem::first()->order->visit_id);
    }

    /** Deleting the work deletes its charges — they cannot outlive it. */
    public function test_items_die_with_the_order(): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '1000']);
        $order = $this->orders()->place($this->visit, OrderType::Consultation, 'Consultation', [], $this->doctor->id);
        app(BillingService::class)->addServiceLine($order, $service, 1, $this->doctor->id);

        Order::whereKey($order->id)->forceDelete();

        $this->assertSame(0, OrderItem::withTrashed()->count());
    }

    // ── The specialist services place real orders now ────────────────────

    public function test_ordering_a_lab_test_places_an_order_that_owns_the_charge(): void
    {
        $test = LabTest::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT', 'price' => '8000',
        ]);

        $labOrder = app(LabService::class)->order($this->visit, [$test->id], 'Rule out malaria.', $this->doctor->id);

        $order = Order::where('type', OrderType::Lab->value)->firstOrFail();
        $this->assertSame($labOrder->id, $order->subject_id, 'the order points at the lab record');
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(
            0,
            bccomp(app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal'], '8000.00', 2),
        );
    }

    /** And cancelling that work now clears its charge, which it could not before. */
    public function test_cancelling_a_lab_order_clears_its_charge(): void
    {
        $test = LabTest::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '8000']);
        app(LabService::class)->order($this->visit, [$test->id], null, $this->doctor->id);

        $order = Order::where('type', OrderType::Lab->value)->firstOrFail();
        $this->orders()->transition($order, OrderStatus::Cancelled, $this->doctor->id, 'Not needed');

        $this->assertSame(
            0,
            bccomp(app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal'], '0.00', 2),
        );
    }

    // ── The shelf and the bill agree ─────────────────────────────────────

    private function drug(string $onHand = '20', string $price = '500'): \App\Models\StockItem
    {
        return \App\Models\StockItem::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Amoxicillin 250mg',
            'unit' => 'tablets', 'sale_price' => $price, 'cost_price' => '200',
            'current_quantity' => $onHand, 'original_quantity' => $onHand, 'is_active' => true,
        ]);
    }

    private function movements(\App\Models\StockItem $item): int
    {
        return \App\Models\StockMovement::where('stock_item_id', $item->id)->count();
    }

    /**
     * On a visit where nothing has drifted, reconciling writes NOTHING.
     *
     * This is the property that makes it safe to run on every confirmation:
     * it compares the ledger to the line and posts only the difference, so it
     * can no more double-deduct than doing nothing could.
     */
    public function test_reconciling_a_visit_in_step_writes_no_movement(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Pharmacy, 'Take-home', [], $this->doctor->id);
        $drug = $this->drug('20');
        $this->orders()->addProductItem($order, $drug, '6', $this->doctor->id);

        $before = $this->movements($drug);
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);

        $result = $this->orders()->reconcileStock($this->visit, $this->doctor->id);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['fixed'], 'it moved stock that was already right');
        $this->assertSame($before, $this->movements($drug), 'it wrote a movement with nothing to correct');
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);
    }

    /** And running it again and again still writes nothing. */
    public function test_reconciling_is_idempotent(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Pharmacy, 'Take-home', [], $this->doctor->id);
        $drug = $this->drug('20');
        $this->orders()->addProductItem($order, $drug, '6', $this->doctor->id);

        foreach (range(1, 5) as $ignored) {
            $this->orders()->reconcileStock($this->visit, $this->doctor->id);
        }

        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity, 'the shelf was deducted more than once');
        $this->assertSame(1, $this->movements($drug));
    }

    /** A line billed but never deducted is put right. */
    public function test_reconciling_takes_what_was_billed_but_never_deducted(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Pharmacy, 'Take-home', [], $this->doctor->id);
        $drug = $this->drug('20');

        // Billed through the money path alone, as an import or a legacy row
        // would leave it: a charge with no movement behind it.
        app(BillingService::class)->addItem($order, [
            'stock_item_id' => $drug->id, 'name' => $drug->name,
            'unit_price' => '500', 'quantity' => '6',
        ], $this->doctor->id);

        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity);

        $result = $this->orders()->reconcileStock($this->visit, $this->doctor->id);

        $this->assertSame(1, $result['fixed']);
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity, 'the billed drugs never left the shelf');
    }

    /** A line taken off the bill should have nothing still out. */
    public function test_reconciling_puts_back_what_a_removed_line_still_holds(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Pharmacy, 'Take-home', [], $this->doctor->id);
        $drug = $this->drug('20');
        $line = $this->orders()->addProductItem($order, $drug, '6', $this->doctor->id);

        // Cancelled through the money path alone, so the shelf never heard.
        app(BillingService::class)->cancelLine($line);
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);

        $this->orders()->reconcileStock($this->visit, $this->doctor->id);

        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity, 'a removed line kept the drugs');
    }

    /** Confirming a visit runs the check, without anyone asking for it. */
    public function test_moving_a_visit_on_reconciles_the_shelf(): void
    {
        $order = $this->orders()->place($this->visit, OrderType::Pharmacy, 'Take-home', [], $this->doctor->id);
        $drug = $this->drug('20');

        app(BillingService::class)->addItem($order, [
            'stock_item_id' => $drug->id, 'name' => $drug->name,
            'unit_price' => '500', 'quantity' => '6',
        ], $this->doctor->id);

        $this->orders()->transition($order, OrderStatus::Completed, $this->doctor->id);
        app(\App\Services\VisitService::class)->advance($this->visit->fresh(), $this->doctor->id);

        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);
    }

    /** Only this visit's shelf is touched. */
    public function test_reconciling_never_reaches_another_visits_lines(): void
    {
        $theirs = app(\App\Services\VisitService::class)->open([
            'patient_id' => \App\Models\Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);
        $theirOrder = $this->orders()->place($theirs, OrderType::Pharmacy, 'Theirs', [], $this->doctor->id);
        $drug = $this->drug('20');

        app(BillingService::class)->addItem($theirOrder, [
            'stock_item_id' => $drug->id, 'name' => $drug->name,
            'unit_price' => '500', 'quantity' => '6',
        ], $this->doctor->id);

        $result = $this->orders()->reconcileStock($this->visit, $this->doctor->id);

        $this->assertSame(0, $result['checked']);
        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity);
    }
}
