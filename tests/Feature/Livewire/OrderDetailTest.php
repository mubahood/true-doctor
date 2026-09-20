<?php

namespace Tests\Feature\Livewire;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\StockMovementReason;
use App\Livewire\Visits\Panels\OrderDetail;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\StockService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The order dialog: view AND manage — see docs/orders.md.
 *
 * Three things go in here and nowhere else: what the work used (its items,
 * which are the bill), what was found (the report and its files), and where it
 * has got to. Everything saves as it is done, and cancelling shows what it
 * costs before it costs it.
 */
class OrderDetailTest extends TestCase
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

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->doctor->syncSpatieRole();
        $this->actingAs($this->doctor);

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = app(VisitService::class)->open([
            'patient_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
        ]);
    }

    private function order(string $title = 'Wound dressing', OrderType $type = OrderType::Procedure): Order
    {
        return app(OrderService::class)->place($this->visit, $type, $title, [], $this->doctor->id);
    }

    /** Mounted, then opened the way a row opens it. */
    private function dialog(?Order $order = null): \Livewire\Features\SupportTesting\Testable
    {
        $component = Livewire::test(OrderDetail::class, ['visitId' => $this->visit->id]);

        return $order === null
            ? $component
            : $component->call('open', $this->visit->id, $order->id);
    }

    private function service(string $price = '8000'): Service
    {
        return Service::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Consultation',
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function drug(string $onHand = '20', string $price = '500'): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Amoxicillin 250mg',
            'unit' => 'tablets',
            'sale_price' => $price,
            'cost_price' => '200',
            'current_quantity' => $onHand,
            'original_quantity' => $onHand,
            'is_active' => true,
        ]);
    }

    // ── Opening ──────────────────────────────────────────────────────────

    public function test_a_row_opens_the_order_and_its_three_sections(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->assertSet('show', true)
            ->assertSet('orderId', $order->id)
            ->assertSee('What it used')
            ->assertSee('Report')
            ->assertSee('Mark in progress');
    }

    /** The dialog answers only for its own visit. */
    public function test_another_visits_order_cannot_be_opened(): void
    {
        $other = app(VisitService::class)->open([
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);
        $theirs = app(OrderService::class)->place($other, OrderType::Lab, 'Not mine', [], $this->doctor->id);

        $this->expectException(ModelNotFoundException::class);
        $this->dialog()->call('open', $this->visit->id, $theirs->id);
    }

    /** An event meant for a different visit's dialog is ignored, not obeyed. */
    public function test_an_event_for_another_visit_is_ignored(): void
    {
        $order = $this->order();

        $this->dialog()
            ->call('open', $this->visit->id + 999, $order->id)
            ->assertSet('show', false);
    }

    /** The four things the item form asks, and nothing else. */
    public function test_the_dialog_offers_both_kinds_a_quantity_and_a_drop_target(): void
    {
        $html = $this->dialog($this->order())->html();

        foreach (['Service', 'Product', 'Qty', 'Total', 'drop them here'] as $expected) {
            $this->assertStringContainsString($expected, $html, "the dialog does not offer: {$expected}");
        }

        $this->assertStringContainsString('tdDrop', $html, 'the drop target is not wired up');

        // The arrows move by one. step="0.01" made them nudge a hundredth at a
        // time — three clicks from 1 landed on 1.03 — while step="1" would
        // have made a half-tablet invalid. "any" is the one value that gives
        // whole-number arrows AND lets a decimal be typed.
        $this->assertStringContainsString('id="oi-qty" type="number" step="any"', $html,
            'the quantity arrows step by a fraction');
        $this->assertStringNotContainsString('id="oi-qty" type="number" step="0.01"', $html);
        // Two choices is a switch, not a pair of cards as tall as the fields.
        $this->assertStringContainsString('tb-seg-btn', $html, 'the kind is not a switch');
        $this->assertStringContainsString('aria-pressed', $html, 'the switch does not say which is on');
        // Said where it stays true, not in a placeholder that vanishes the
        // moment typing starts.
        $this->assertStringContainsString('Saves as you type', $html, 'nothing says the report autosaves');
    }

    // ── What it used ─────────────────────────────────────────────────────

    public function test_a_service_is_charged_to_the_order(): void
    {
        $order = $this->order();
        $service = $this->service('8000');

        $this->dialog($order)
            ->set('itemKind', 'service')
            ->call('picked', 'service_id', $service->id)
            ->set('qty', '2')
            ->call('addItem')
            ->assertHasNoErrors()
            ->assertDispatched('visit-updated');

        $line = $order->items()->firstOrFail();
        $this->assertSame($service->id, $line->service_id);
        $this->assertSame('2.00', (string) $line->quantity);
        $this->assertSame('16000.00', (string) $line->line_total, 'the total was not price × quantity');
    }

    /** The total is computed, never typed. */
    public function test_the_total_follows_the_pick_and_the_quantity(): void
    {
        $order = $this->order();
        $service = $this->service('8000');

        $dialog = $this->dialog($order)
            ->call('picked', 'service_id', $service->id)
            ->set('qty', '3');

        $this->assertSame('8000.00', $dialog->get('pickedPrice'));
        $this->assertSame('24000.00', $dialog->get('lineTotal'));
    }

    /**
     * A product is sold AND leaves the shelf. Billing it without deducting it
     * is the same class of bug the order model exists to end.
     */
    public function test_a_product_is_billed_and_comes_off_the_shelf(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem')
            ->assertHasNoErrors();

        $line = $order->items()->firstOrFail();
        $this->assertSame($drug->id, $line->stock_item_id);
        $this->assertSame('3000.00', (string) $line->line_total);
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity, 'the drugs never left the shelf');
    }

    /** Drugs come in halves; the line has to be able to say so. */
    public function test_a_fractional_quantity_is_kept_as_itself(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '2.5')
            ->call('addItem')
            ->assertHasNoErrors();

        $line = $order->items()->firstOrFail();
        $this->assertSame('2.50', (string) $line->quantity);
        $this->assertSame('1250.00', (string) $line->line_total);
        $this->assertSame('2.5', $line->tidyQuantity(), 'the column printed its own noughts');
    }

    public function test_more_than_the_shelf_holds_is_refused_and_nothing_is_billed(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '3', price: '500');

        $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '10')
            ->call('addItem')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(0, $order->items()->count(), 'the charge survived a failed dispense');
        $this->assertSame('3.00', (string) $drug->fresh()->current_quantity);
    }

    public function test_an_item_needs_something_picked_and_a_real_quantity(): void
    {
        $order = $this->order();

        $this->dialog($order)->set('qty', '1')->call('addItem')->assertHasErrors('service_id');

        $this->dialog($order)
            ->call('picked', 'service_id', $this->service()->id)
            ->set('qty', '0')
            ->call('addItem')
            ->assertHasErrors('qty');
    }

    /** Switching between the price list and the shelf drops the other pick. */
    public function test_switching_kind_clears_the_previous_pick(): void
    {
        $order = $this->order();
        $service = $this->service();

        $this->dialog($order)
            ->call('picked', 'service_id', $service->id)
            ->assertSet('service_id', $service->id)
            ->set('itemKind', 'product')
            ->assertSet('service_id', null)
            ->assertSet('stock_item_id', null);
    }

    // ── Taking one back off ──────────────────────────────────────────────

    /** Removing a line drops it off the bill and keeps it in the trail. */
    public function test_removing_a_service_line_takes_it_off_the_bill(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service('8000'), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $this->dialog($order)->call('removeItem', $line->id)->assertDispatched('visit-updated');

        $this->assertSame(OrderItemStatus::Cancelled, $line->fresh()->status);
        $this->assertSame(0, bccomp(
            app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal'], '0.00', 2,
        ), 'the money stayed on the bill');
    }

    /** And a product line goes back on the shelf it came off. */
    public function test_removing_a_product_line_puts_the_stock_back(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $dialog = $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem');

        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);

        $line = $order->items()->firstOrFail();
        $dialog->call('removeItem', $line->id);

        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity, 'the drugs never came back');
        $this->assertTrue(
            StockMovement::where('stock_item_id', $drug->id)
                ->where('reason', StockMovementReason::ReturnedToStock->value)->exists(),
            'the return was not written to the ledger',
        );
    }

    /** Asked twice, it returns once: the ledger is not a suggestion. */
    public function test_a_return_is_posted_only_once(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem');

        $line = $order->items()->firstOrFail();
        app(StockService::class)->returnFor($line, $this->doctor->id);
        app(StockService::class)->returnFor($line, $this->doctor->id);

        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity, 'the stock was returned twice');
    }

    // ── What was found ───────────────────────────────────────────────────

    /** Typing is the save. There is no Save button, so there had better not be. */
    public function test_the_report_saves_as_it_is_typed(): void
    {
        $order = $this->order();

        $dialog = $this->dialog($order)->set('report', 'No growth after 48 hours.');

        $this->assertSame('No growth after 48 hours.', $order->fresh()->report);
        $this->assertNotNull($order->fresh()->report_updated_at);
        $this->assertNotNull($dialog->get('reportSavedAt'), 'nothing told the reader it had saved');
    }

    public function test_emptying_the_report_clears_it_and_its_stamp(): void
    {
        $order = $this->order();

        $this->dialog($order)->set('report', 'Something')->set('report', '   ');

        $this->assertNull($order->fresh()->report);
        $this->assertNull($order->fresh()->report_updated_at);
    }

    public function test_a_dropped_file_is_stored_privately_and_listed(): void
    {
        Storage::fake('local');
        $order = $this->order();

        $this->dialog($order)
            ->set('files', [UploadedFile::fake()->create('result.pdf', 40, 'application/pdf')])
            ->assertHasNoErrors();

        $attachment = $order->attachments()->firstOrFail();
        $this->assertSame('result.pdf', $attachment->original_name);
        $this->assertSame($this->doctor->id, $attachment->uploaded_by);
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertStringStartsWith("orders/{$this->hospital->id}/{$order->id}/", $attachment->file_path);
        $this->assertStringNotContainsString('result.pdf', $attachment->file_path, 'the stored name is guessable');
    }

    public function test_an_executable_cannot_be_attached(): void
    {
        Storage::fake('local');
        $order = $this->order();

        $this->dialog($order)
            ->set('files', [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')])
            ->assertHasErrors('files.0');

        $this->assertSame(0, $order->attachments()->count());
    }

    public function test_removing_a_file_deletes_it_from_the_disk(): void
    {
        Storage::fake('local');
        $order = $this->order();

        $dialog = $this->dialog($order)
            ->set('files', [UploadedFile::fake()->image('film.png')]);

        /** @var OrderAttachment $attachment */
        $attachment = $order->attachments()->firstOrFail();
        $path = $attachment->file_path;

        $dialog->call('removeAttachment', $attachment->id);

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $order->attachments()->count());
    }

    /** PHI is streamed behind the policy, never served off a public disk. */
    public function test_an_attachment_is_only_reachable_through_the_gated_route(): void
    {
        Storage::fake('local');
        $order = $this->order();
        $this->dialog($order)->set('files', [UploadedFile::fake()->create('result.pdf', 20, 'application/pdf')]);

        /** @var OrderAttachment $attachment */
        $attachment = $order->attachments()->firstOrFail();

        $this->get(route('admin.orders.attachments.download', [$order, $attachment]))->assertOk();

        $outsider = User::factory()->create([
            'hospital_id' => Hospital::factory()->create()->id, 'role' => 'hospital_admin',
        ]);
        $outsider->syncSpatieRole();

        $this->actingAs($outsider)
            ->get(route('admin.orders.attachments.download', [$order, $attachment]))
            ->assertNotFound();
    }

    // ── Where it has got to ──────────────────────────────────────────────

    public function test_the_work_can_be_marked_in_progress_and_then_done(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);

        $dialog = $this->dialog($order)
            ->call('move', OrderStatus::InProgress->value)
            ->assertDispatched('visit-updated');

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->started_at);

        $dialog->call('move', OrderStatus::Completed->value);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->completed_at);
    }

    /** Cancelling never goes straight through move(); it goes through the ask. */
    public function test_cancelling_cannot_be_reached_by_move(): void
    {
        $order = $this->order();

        $this->dialog($order)->call('move', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status, 'cancelling skipped the confirmation');
    }

    // ── What cancelling costs ────────────────────────────────────────────

    /** Shown before anything is undone: the money and the goods, itemised. */
    public function test_the_confirmation_lists_the_money_and_the_stock(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $dialog = $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem')
            ->call('askCancel')
            ->assertSet('confirming', true)
            ->assertSee('Cancel this order?')
            ->assertSee('Off the bill')
            ->assertSee('Back on the shelf')
            ->assertSee('Amoxicillin 250mg');

        $plan = $dialog->get('plan');
        $this->assertSame('3000.00', $plan['total']);
        $this->assertSame('6', $plan['stock'][0]['quantity']);
        $this->assertSame('tablets', $plan['stock'][0]['unit']);

        // Shown, not done: nothing has moved yet.
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_keeping_the_order_undoes_nothing(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->call('askCancel')
            ->call('keepOrder')
            ->assertSet('confirming', false);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    /** And when it is confirmed, both really are reversed. */
    public function test_cancelling_reverses_the_bill_and_the_shelf_and_records_why(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');
        app(BillingService::class)->addServiceLine($order, $this->service('8000'), 1, $this->doctor->id);

        $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem')
            ->call('askCancel')
            ->set('cancelReason', 'Patient declined')
            ->call('cancelOrder')
            ->assertSet('confirming', false)
            ->assertDispatched('visit-updated');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertSame('Patient declined', $fresh->cancel_reason, 'the reason was never written down');
        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity, 'the drugs stayed gone');
        $this->assertSame(0, bccomp(
            app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal'], '0.00', 2,
        ), 'the charges stayed on the bill');
    }

    /** The old dispensing path hangs its movements off the dispensation. */
    public function test_cancelling_a_dispensing_order_returns_its_drugs(): void
    {
        $drug = $this->drug(onHand: '20', price: '500');

        app(\App\Services\DispensationService::class)->dispense(
            $this->visit,
            [['stock_item_id' => $drug->id, 'quantity' => '6']],
            null,
            $this->doctor->id,
        );

        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);

        /** @var Order $pharmacy */
        $pharmacy = Order::where('visit_id', $this->visit->id)->where('type', OrderType::Pharmacy->value)->firstOrFail();

        $this->dialog($pharmacy)->call('askCancel')->call('cancelOrder');

        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity, 'a cancelled dispensing kept the drugs');
    }

    // ── The lock ─────────────────────────────────────────────────────────

    public function test_a_cancelled_orders_items_are_fixed(): void
    {
        $order = $this->order();
        app(OrderService::class)->transition($order, OrderStatus::Cancelled, $this->doctor->id, 'Done in error');

        $this->dialog($order->fresh())
            ->assertSet('editable', false)
            ->assertSee('This order was cancelled')
            ->assertDontSee('What kind of thing is being added')
            ->call('picked', 'service_id', $this->service()->id)
            ->call('addItem')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(0, $order->items()->count(), 'a cancelled order took a new charge');
    }

    /** The invoice snapshots its lines; letting the order drift would desync it. */
    public function test_an_invoiced_visit_freezes_its_charges(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service('8000'), 1, $this->doctor->id);
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->doctor->id);

        $this->dialog($order)
            ->assertSet('editable', false)
            ->assertSee('This visit has been invoiced')
            ->assertSet('canManage', true, 'the work can still be marked done');
    }

    // ── Who may ──────────────────────────────────────────────────────────

    public function test_a_role_without_visit_writes_cannot_change_anything(): void
    {
        $order = $this->order();
        $service = $this->service();

        // A lab technician sees the visit (visits.view) and writes none of it.
        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'lab_technician']);
        $reader->syncSpatieRole();
        $this->actingAs($reader);

        $this->assertTrue($reader->can('visits.view'), 'the read-only case cannot even open the dialog');
        $this->assertFalse($reader->can('visits.create') || $reader->can('visits.manage'));

        $this->dialog($order)->assertSet('editable', false)->assertSet('canManage', false);

        // A fresh dialog per attempt: a 403 leaves no snapshot to keep using.
        $this->dialog($order)->call('picked', 'service_id', $service->id)->call('addItem')->assertForbidden();
        $this->dialog($order)->call('move', OrderStatus::InProgress->value)->assertForbidden();

        $this->assertSame(0, $order->items()->count());
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // ── The words beside a charge ────────────────────────────────────────

    /** A charge nobody can explain is one somebody argues about at the counter. */
    public function test_a_line_can_carry_a_note_when_it_is_added(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->call('picked', 'service_id', $this->service('8000')->id)
            ->set('qty', '1')
            ->set('itemNote', 'Second opinion requested by the family')
            ->call('addItem')
            ->assertHasNoErrors();

        $this->assertSame('Second opinion requested by the family', $order->items()->firstOrFail()->notes);
    }

    public function test_the_note_and_who_added_it_are_shown_on_the_row(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->call('picked', 'service_id', $this->service()->id)
            ->set('itemNote', 'Family asked for it')
            ->call('addItem')
            ->assertSee('Family asked for it')
            ->assertSee($this->doctor->name);
    }

    // ── Correcting one ───────────────────────────────────────────────────

    public function test_a_line_can_be_corrected_in_place(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service('8000'), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $this->dialog($order)
            ->call('editItem', $line->id)
            ->assertSet('editingId', $line->id)
            ->assertSet('editQty', '1')
            ->assertSee('step="any"', escape: false)
            ->set('editQty', '3')
            ->set('editNote', 'Three sessions')
            ->call('saveItem')
            ->assertHasNoErrors()
            ->assertSet('editingId', null)
            ->assertDispatched('visit-updated');

        $line->refresh();
        $this->assertSame('3.00', (string) $line->quantity);
        $this->assertSame('24000.00', (string) $line->line_total, 'the total did not follow the quantity');
        $this->assertSame('Three sessions', $line->notes);
    }

    /** The name and price are snapshots; a correction must not touch them. */
    public function test_correcting_a_line_never_moves_its_snapshotted_price(): void
    {
        $order = $this->order();
        $service = $this->service('8000');
        app(BillingService::class)->addServiceLine($order, $service, 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $service->update(['price' => '99000', 'name' => 'Renamed']);

        $this->dialog($order)->call('editItem', $line->id)->set('editQty', '2')->call('saveItem');

        $line->refresh();
        $this->assertSame('8000.00', (string) $line->unit_price, 'a catalogue change reached a charge already raised');
        $this->assertSame('Consultation', $line->name);
        $this->assertSame('16000.00', (string) $line->line_total);
    }

    /** And it records the hand that made it. */
    public function test_a_correction_records_who_made_it(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $this->assertFalse($line->wasCorrected());

        $someoneElse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $someoneElse->syncSpatieRole();
        $this->actingAs($someoneElse);

        $this->dialog($order)->call('editItem', $line->id)->set('editQty', '2')->call('saveItem');

        $line->refresh();
        $this->assertTrue($line->wasCorrected());
        $this->assertSame($someoneElse->id, $line->updated_by);
        $this->assertSame($this->doctor->id, $line->ordered_by, 'the first hand was overwritten');
    }

    // ── On a product, the quantity is stock ──────────────────────────────

    public function test_raising_a_product_quantity_takes_more_off_the_shelf(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $dialog = $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem');

        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity);

        $line = $order->items()->firstOrFail();
        $dialog->call('editItem', $line->id)->set('editQty', '9')->call('saveItem')->assertHasNoErrors();

        $this->assertSame('11.00', (string) $drug->fresh()->current_quantity, 'the extra never left the shelf');
        $this->assertSame('4500.00', (string) $line->fresh()->line_total);
    }

    public function test_lowering_a_product_quantity_puts_the_difference_back(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $dialog = $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem');

        $line = $order->items()->firstOrFail();
        $dialog->call('editItem', $line->id)->set('editQty', '2')->call('saveItem')->assertHasNoErrors();

        $this->assertSame('18.00', (string) $drug->fresh()->current_quantity, 'the difference never came back');
    }

    /**
     * And a correction never strands the remainder.
     *
     * The return used to be all-or-nothing: once anything had been put back
     * for a line, a later cancellation skipped the rest and the remainder
     * stayed off the shelf for good.
     */
    public function test_cancelling_after_a_correction_returns_what_is_still_out(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '20', price: '500');

        $dialog = $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem');

        $line = $order->items()->firstOrFail();
        $dialog->call('editItem', $line->id)->set('editQty', '4')->call('saveItem');
        $this->assertSame('16.00', (string) $drug->fresh()->current_quantity);

        $dialog->call('askCancel')->set('cancelReason', 'Wrong drug')->call('cancelOrder');

        $this->assertSame('20.00', (string) $drug->fresh()->current_quantity,
            'the four still out were stranded off the shelf');
    }

    public function test_more_than_the_shelf_holds_is_refused_on_a_correction_too(): void
    {
        $order = $this->order();
        $drug = $this->drug(onHand: '8', price: '500');

        $dialog = $this->dialog($order)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem');

        $line = $order->items()->firstOrFail();

        $dialog->call('editItem', $line->id)->set('editQty', '20')->call('saveItem')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame('6.00', (string) $line->fresh()->quantity, 'the correction went through anyway');
        $this->assertSame('2.00', (string) $drug->fresh()->current_quantity);
    }

    // ── Guards ───────────────────────────────────────────────────────────

    public function test_a_removed_line_cannot_be_corrected(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $this->dialog($order)->call('removeItem', $line->id);

        $this->dialog($order)->call('editItem', $line->id)->assertSet('editingId', null);
    }

    public function test_an_invoiced_visit_refuses_a_correction(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service('8000'), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->doctor->id);

        $this->dialog($order)
            ->call('editItem', $line->id)
            ->assertSet('editingId', null)
            ->assertDispatched('toast', type: 'error');
    }

    public function test_a_role_without_visit_writes_cannot_correct_a_line(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'lab_technician']);
        $reader->syncSpatieRole();
        $this->actingAs($reader);

        $this->dialog($order)->call('editItem', $line->id)->assertForbidden();
    }

    /** A zero or negative quantity is not a correction. */
    public function test_a_correction_needs_a_real_quantity(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $this->dialog($order)
            ->call('editItem', $line->id)
            ->set('editQty', '0')
            ->call('saveItem')
            ->assertHasErrors('editQty');
    }

    // ── Finished has to mean something happened ──────────────────────────

    /**
     * An order completed with nothing on it tells the next reader that work
     * happened and leaves no trace of what — worse than one still open.
     */
    public function test_an_empty_order_cannot_be_marked_completed(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->assertSee('Add what it used, write a report, or attach a result first.')
            ->assertDontSee('Mark completed')
            ->call('move', OrderStatus::Completed->value)
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    /** But it can always be called off: that is what a mistaken order needs. */
    public function test_an_empty_order_can_still_be_cancelled(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->assertSee('Cancel order')
            ->call('askCancel')
            ->set('cancelReason', 'Raised in error')
            ->call('cancelOrder')
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    /** A line on the bill is evidence. */
    public function test_one_item_is_enough_to_finish_it(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);

        $this->dialog($order)
            ->assertSee('Mark completed')
            ->call('move', OrderStatus::Completed->value)
            ->assertDispatched('visit-updated');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    /** So is a written report, with nothing charged at all. */
    public function test_a_report_alone_is_enough_to_finish_it(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->set('report', 'Reviewed and discharged. No charge.')
            ->call('move', OrderStatus::Completed->value);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(0, $order->items()->count());
    }

    /** And so is a result that arrived as a file nobody summarised. */
    public function test_an_attachment_alone_is_enough_to_finish_it(): void
    {
        Storage::fake('local');
        $order = $this->order();

        $this->dialog($order)
            ->set('files', [UploadedFile::fake()->create('result.pdf', 20, 'application/pdf')])
            ->call('move', OrderStatus::Completed->value);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    /** A line that was removed is not evidence — it is the absence of it. */
    public function test_a_removed_line_does_not_count_as_evidence(): void
    {
        $order = $this->order();
        app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);
        $line = $order->items()->firstOrFail();

        $dialog = $this->dialog($order)->call('removeItem', $line->id);

        $dialog->call('move', OrderStatus::Completed->value)->assertDispatched('toast', type: 'error');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    /** Nor does whitespace pretending to be a report. */
    public function test_a_blank_report_does_not_count_as_evidence(): void
    {
        $order = $this->order();

        $this->dialog($order)
            ->set('report', '    ')
            ->call('move', OrderStatus::Completed->value)
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }
}
