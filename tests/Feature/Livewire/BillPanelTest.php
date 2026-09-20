<?php

namespace Tests\Feature\Livewire;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Livewire\Visits\Panels\Charges;
use App\Models\Hospital;
use App\Models\Order;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bill.
 *
 * Every charge is an item on an order, so every charge is owned by a piece of
 * work. The bill shows, adds and invoices — it does not edit a line, because
 * cancelling one from here used to leave the order still saying it was
 * completed, its evidence gone from under it, and any drugs still off the
 * shelf.
 */
class BillPanelTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Visit $visit;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
        $this->actingAs($this->admin);

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = app(VisitService::class)->open(['patient_id' => $patient->id]);
    }

    private function panel(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Charges::class, ['visitId' => $this->visit->id, 'lazy' => false]);
    }

    private function service(string $name = 'Consultation', string $price = '20000'): Service
    {
        return Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => $name,
            'price' => $price, 'is_active' => true,
        ]);
    }

    private function chargedOrder(string $title = 'Lab tests'): Order
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Lab, $title, [], $this->admin->id);
        app(BillingService::class)->addServiceLine($order, $this->service('Malaria RDT', '8000'), 1, $this->admin->id);

        return $order->fresh();
    }

    /** A charged visit with a live invoice on it. Returns the order behind it. */
    private function invoiced(): Order
    {
        $order = $this->chargedOrder();
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        return $order;
    }

    // ── The bill does not edit its own lines ─────────────────────────────

    public function test_the_bill_offers_no_way_to_cancel_a_line(): void
    {
        $this->chargedOrder();

        $html = $this->panel()->html();

        $this->assertStringNotContainsString('cancelLine', $html, 'the bill can still cancel a line');
        $this->assertStringContainsString('A charge is changed on the order that raised it', $html);
    }

    /** The method is gone, not merely hidden. */
    public function test_the_cancel_action_no_longer_exists(): void
    {
        $this->assertFalse(
            method_exists(Charges::class, 'cancelLine'),
            'the bill still has a way to cancel a line behind the screen',
        );
    }

    /** Every row offers the way into the work that raised it. */
    public function test_each_row_opens_the_order_that_raised_it(): void
    {
        $order = $this->chargedOrder('Bloods');
        $line = $order->items()->firstOrFail();

        $this->panel()
            ->assertSee('Bloods')
            ->call('openOrder', $line->id)
            ->assertDispatched('order-open', visitId: $this->visit->id, orderId: $order->id);
    }

    /**
     * A line of another visit is not this bill's to open, and saying so out
     * loud is the same answer the order dialog gives to the same question
     * (OrderDetailTest: another visit's order cannot be opened). One rule, one
     * behaviour — a control that quietly does nothing looks, to whoever is
     * pressing it, exactly like one that is broken.
     */
    public function test_a_line_from_another_visit_cannot_be_opened(): void
    {
        $other = app(VisitService::class)->open([
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);
        $theirs = app(OrderService::class)->place($other, OrderType::Lab, 'Not mine', [], $this->admin->id);
        app(BillingService::class)->addServiceLine($theirs, $this->service(), 1, $this->admin->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->panel()->call('openOrder', $theirs->items()->firstOrFail()->id);
    }

    // ── The invoice, read from inside the visit ──────────────────────────

    /**
     * The invoice's own page is not offered from here.
     *
     * Everything it holds — number, totals, balance, payments and the PDF — is
     * already on this screen, so the link only ever threw somebody out of the
     * visit they were working in to look at a second copy of what they were
     * already reading.
     */
    public function test_the_bill_does_not_send_anyone_to_a_separate_invoice_page(): void
    {
        $this->invoiced();

        $html = $this->panel()->html();

        // Quoted: the PDF route is `/admin/invoices/{uuid}/pdf`, which has the
        // detail URL as a prefix — an unquoted search matches its own PDF link.
        $this->assertStringNotContainsString(
            '"'.route('admin.invoices.show', \App\Models\Invoice::firstOrFail()).'"',
            $html,
            'the bill still redirects out of the visit',
        );
        $this->assertStringContainsString('Invoice PDF', $html);
    }

    /** …and the PDF opens beside the visit rather than replacing it. */
    public function test_the_invoice_pdf_opens_in_its_own_tab(): void
    {
        $this->invoiced();

        $html = $this->panel()->html();

        $this->assertStringContainsString(route('admin.invoices.pdf', \App\Models\Invoice::firstOrFail()), $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    // ── Work charged after the invoice was raised ────────────────────────

    /**
     * A bed charge is billed when a patient is discharged, which routinely
     * happens after the counter has invoiced and been paid. The bill above the
     * invoice keeps adding up the LIVE lines, so the two figures then sit one
     * above the other disagreeing — and the screen used to say nothing at all
     * about which one anybody owed.
     */
    public function test_work_charged_after_the_invoice_is_called_out(): void
    {
        $order = $this->invoiced();

        $panel = $this->panel();
        $this->assertSame('0.00', $panel->instance()->unbilled(), 'a fresh invoice already reads as short');

        // Billed straight onto the visit, the way AdmissionService does it at
        // discharge — around the panel, not through it.
        app(BillingService::class)->addServiceLine($order, $this->service('Bed charge', '45000'), 1, $this->admin->id);

        $panel = $this->panel();

        $this->assertSame('45000.00', $panel->instance()->unbilled());
        $panel->assertSee('is on no invoice');
    }

    /** Two figures that mean different things are never both called "Total". */
    public function test_the_live_total_is_renamed_once_an_invoice_exists(): void
    {
        $this->chargedOrder();

        $this->panel()->assertSee('Total')->assertDontSee('Work charged');

        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        $this->panel()->assertSee('Work charged');
    }

    // ── The bill follows the orders ──────────────────────────────────────

    /** Cancelling the work takes its charge off the bill, in this panel. */
    public function test_cancelling_an_order_updates_the_bill(): void
    {
        $order = $this->chargedOrder();

        $panel = $this->panel();
        $this->assertSame('8000.00', $panel->get('totals')['subtotal']);

        app(OrderService::class)->transition($order, OrderStatus::Cancelled, $this->admin->id, 'Wrong test');

        $panel->call('refreshCharges');
        $this->assertSame('0.00', $panel->get('totals')['subtotal'], 'the bill kept a cancelled order\'s charge');
    }

    /** And a correction on the order moves the bill with it. */
    public function test_correcting_a_line_on_its_order_updates_the_bill(): void
    {
        $order = $this->chargedOrder();
        $line = $order->items()->firstOrFail();

        app(OrderService::class)->updateItem($line, '3', null, $this->admin->id);

        $this->assertSame('24000.00', $this->panel()->get('totals')['subtotal']);
    }

    // ── Adding one ───────────────────────────────────────────────────────

    public function test_a_charge_added_here_gets_its_own_order(): void
    {
        $service = $this->service('Ambulance service', '120000');

        $this->panel()
            ->call('openAdd')
            ->call('picked', 'service_id', $service->id)
            ->set('quantity', '2')
            ->set('note', 'Transfer to the referral hospital')
            ->call('addLine')
            ->assertHasNoErrors()
            ->assertSet('showAdd', false);

        $order = Order::where('visit_id', $this->visit->id)->firstOrFail();
        $this->assertSame('Ambulance service', $order->title);

        $line = $order->items()->firstOrFail();
        $this->assertSame('240000.00', (string) $line->line_total);
        $this->assertSame('Transfer to the referral hospital', $line->notes);
    }

    public function test_a_charge_needs_a_service_and_a_real_quantity(): void
    {
        $this->panel()->call('openAdd')->set('quantity', '1')->call('addLine')->assertHasErrors('service_id');

        $this->panel()
            ->call('openAdd')
            ->call('picked', 'service_id', $this->service()->id)
            ->set('quantity', '0')
            ->call('addLine')
            ->assertHasErrors('quantity');
    }

    // ── Putting the bill back in step ────────────────────────────────────

    /**
     * Line totals are stored so a reprice cannot move a charge already raised.
     * The cost of that is a line CAN drift, with nothing to notice it.
     */
    public function test_rebilling_puts_a_drifted_line_right(): void
    {
        $order = $this->chargedOrder();
        $line = $order->items()->firstOrFail();

        // As a bad import or a hand-edited row would leave it.
        $line->updateQuietly(['line_total' => '1.00']);

        $this->panel()
            ->call('rebill')
            ->assertDispatched('toast', type: 'warning');

        $this->assertSame('8000.00', (string) $line->fresh()->line_total);
    }

    public function test_rebilling_a_bill_that_is_in_step_changes_nothing(): void
    {
        $order = $this->chargedOrder();
        $before = (string) $order->items()->firstOrFail()->line_total;

        $this->panel()->call('rebill')->assertDispatched('toast', type: 'success');

        $this->assertSame($before, (string) $order->items()->firstOrFail()->line_total);
    }

    public function test_rebilling_an_invoiced_visit_is_refused(): void
    {
        $this->chargedOrder();
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        $this->panel()->call('rebill')->assertDispatched('toast', type: 'error');
    }

    // ── The invoice ──────────────────────────────────────────────────────

    /** It appears here rather than throwing the reader onto another page. */
    public function test_generating_an_invoice_shows_it_in_the_panel(): void
    {
        $this->chargedOrder();

        $panel = $this->panel()
            ->call('openInvoice')
            ->set('discount', '0')
            ->call('generateInvoice')
            ->assertHasNoErrors()
            ->assertSet('showInvoice', false)
            ->assertNoRedirect();

        $invoice = $this->visit->fresh()->invoices()->firstOrFail();

        $panel->call('refreshCharges')
            ->assertSee($invoice->invoice_no)
            ->assertSee('Invoice PDF');
    }

    /** And once it exists, the bill is fixed. */
    public function test_an_invoiced_bill_offers_no_more_writes(): void
    {
        $this->chargedOrder();
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        // Asserted on the controls, not the words: the dialogs' own titles
        // render on every pass whether or not they are open.
        $html = $this->panel()->assertSee('The lines were snapshotted onto this invoice')->html();

        foreach (['openAdd', 'openInvoice', 'rebill'] as $write) {
            $this->assertStringNotContainsString('wire:click="'.$write.'"', $html,
                "an invoiced bill still offers: {$write}");
        }
    }

    public function test_the_invoice_pdf_downloads(): void
    {
        $this->chargedOrder();
        $invoice = app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        $this->get(route('admin.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ── Who may ──────────────────────────────────────────────────────────

    /**
     * Someone who may read the bill but not change it.
     *
     * Built by hand rather than by role: `authorizeRead` and `authorizeWrite`
     * are separate for exactly this case, and no seeded role currently holds
     * billing.view without billing.manage — so a test that picked one would
     * skip itself and the distinction would go unguarded.
     */
    public function test_a_billing_reader_may_look_but_not_write(): void
    {
        $this->chargedOrder();

        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $reader->syncSpatieRole();
        $reader->givePermissionTo('billing.view');
        $this->actingAs($reader);

        $this->assertTrue($reader->can('billing.view'));
        $this->assertFalse($reader->can('billing.manage'));

        $html = $this->panel()->assertSee('Malaria RDT')->html();
        foreach (['openAdd', 'openInvoice', 'rebill'] as $write) {
            $this->assertStringNotContainsString('wire:click="'.$write.'"', $html);
        }

        $this->panel()->call('rebill')->assertForbidden();
        $this->panel()->call('openAdd')->assertForbidden();
    }

    public function test_a_role_without_billing_cannot_open_the_bill(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse);

        $this->assertFalse($nurse->can('billing.view'));

        $this->panel()->assertForbidden();
    }

    // ── Selling a product straight onto the bill ─────────────────────────

    private function drug(string $onHand = '20', string $price = '500'): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Amoxicillin 250mg',
            'unit' => 'tablets', 'sale_price' => $price, 'cost_price' => '200',
            'current_quantity' => $onHand, 'original_quantity' => $onHand, 'is_active' => true,
        ]);
    }

    /** A walk-in buying paracetamol is a real thing, and it still moves stock. */
    public function test_a_product_sold_here_comes_off_the_shelf(): void
    {
        $drug = $this->drug(onHand: '20', price: '500');

        $this->panel()
            ->call('openAdd')
            ->set('kind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('quantity', '6')
            ->call('addLine')
            ->assertHasNoErrors();

        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity, 'the drugs never left the shelf');

        $order = Order::where('visit_id', $this->visit->id)->firstOrFail();
        $this->assertSame(OrderType::Pharmacy, $order->type);
        $this->assertSame('3000.00', (string) $order->items()->firstOrFail()->line_total);
    }

    /** More than the shelf holds is refused, and nothing is billed. */
    public function test_selling_more_than_is_on_the_shelf_is_refused(): void
    {
        $drug = $this->drug(onHand: '3');

        $this->panel()
            ->call('openAdd')
            ->set('kind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('quantity', '10')
            ->call('addLine')
            ->assertHasErrors('stock_item_id');

        $this->assertSame('3.00', (string) $drug->fresh()->current_quantity);
        $this->assertSame(0, Order::where('visit_id', $this->visit->id)->count(), 'a charge survived a failed sale');
    }

    /** The total is computed from the pick, never typed. */
    public function test_the_total_follows_the_pick_and_the_quantity(): void
    {
        $service = $this->service('Ambulance service', '120000');

        $panel = $this->panel()
            ->call('openAdd')
            ->call('picked', 'service_id', $service->id)
            ->set('quantity', '2');

        $this->assertSame('120000.00', $panel->get('pickedPrice'));
        $this->assertSame('240000.00', $panel->get('lineTotal'));
    }

    public function test_switching_kind_clears_the_other_pick(): void
    {
        $service = $this->service();

        $this->panel()
            ->call('openAdd')
            ->call('picked', 'service_id', $service->id)
            ->assertSet('service_id', $service->id)
            ->set('kind', 'product')
            ->assertSet('service_id', null)
            ->assertSet('stock_item_id', null);
    }

    // ── The discount ─────────────────────────────────────────────────────

    /**
     * Agreed at the counter, not typed into the invoice dialog.
     *
     * A discount is decided while the bill is being read, and whoever agreed
     * it is not always whoever raises the invoice.
     */
    public function test_a_discount_is_stored_on_the_visit_with_its_reason(): void
    {
        $this->chargedOrder();

        $this->panel()
            ->call('openDiscount')
            ->set('discountType', 'amount')
            ->set('discountValue', '3000')
            ->set('discountReason', 'Staff rate')
            ->call('saveDiscount')
            ->assertHasNoErrors()
            ->assertSet('showDiscount', false);

        $visit = $this->visit->fresh();
        $this->assertSame('3000.00', (string) $visit->discount_value);
        $this->assertSame(DiscountType::Amount, $visit->discount_type);
        $this->assertSame('Staff rate', $visit->discount_reason);
        $this->assertSame($this->admin->id, $visit->discounted_by, 'nobody was recorded against it');
    }

    public function test_the_discount_is_shown_in_the_totals(): void
    {
        $this->chargedOrder();
        app(BillingService::class)->applyDiscount($this->visit, DiscountType::Amount, '3000', 'Staff rate', $this->admin->id);

        $totals = $this->panel()->get('totals');

        $this->assertSame('8000.00', $totals['subtotal'], 'the discount moved what the work came to');
        $this->assertSame('3000.00', $totals['discount']);
        $this->assertSame('5000.00', $totals['due']);

        $this->panel()->assertSee('Staff rate')->assertSee('Due');
    }

    /** More than the bill would be the hospital paying the patient. */
    public function test_a_discount_larger_than_the_bill_is_refused(): void
    {
        $this->chargedOrder();

        $this->panel()
            ->call('openDiscount')
            ->set('discountValue', '99000')
            ->call('saveDiscount')
            ->assertHasErrors('discountValue');

        $this->assertSame('0.00', (string) $this->visit->fresh()->discount_value);
    }

    public function test_a_negative_discount_is_refused(): void
    {
        $this->chargedOrder();

        $this->panel()
            ->call('openDiscount')
            ->set('discountValue', '-100')
            ->call('saveDiscount')
            ->assertHasErrors('discountValue');
    }

    /**
     * Lines come off orders after a discount is agreed, so the cap is applied
     * on every read — not only when it was set. A discount left larger than
     * what is owed would drive the total negative.
     */
    public function test_a_discount_is_capped_at_what_is_still_owed(): void
    {
        $order = $this->chargedOrder();
        app(BillingService::class)->applyDiscount($this->visit, DiscountType::Amount, '8000', 'All of it', $this->admin->id);

        app(OrderService::class)->transition($order, OrderStatus::Cancelled, $this->admin->id, 'Wrong test');

        $totals = $this->panel()->get('totals');

        $this->assertSame('0.00', $totals['subtotal']);
        $this->assertSame('0.00', $totals['discount'], 'the discount outlived the charge it was taken off');
        $this->assertSame('0.00', $totals['due']);
    }

    /** Clearing it forgets the reason and the hand with it. */
    public function test_clearing_the_discount_clears_its_trail(): void
    {
        $this->chargedOrder();
        app(BillingService::class)->applyDiscount($this->visit, DiscountType::Amount, '3000', 'Staff rate', $this->admin->id);

        $this->panel()->call('openDiscount')->set('discountValue', '0')->call('saveDiscount');

        $visit = $this->visit->fresh();
        $this->assertSame('0.00', (string) $visit->discount_value);
        $this->assertNull($visit->discount_reason);
        $this->assertNull($visit->discounted_by);
    }

    /** It is carried onto the invoice without being retyped. */
    public function test_the_invoice_takes_the_discount_the_visit_carries(): void
    {
        $this->chargedOrder();
        app(BillingService::class)->applyDiscount($this->visit, DiscountType::Amount, '3000', 'Staff rate', $this->admin->id);

        $this->panel()->call('openInvoice')->call('generateInvoice')->assertHasNoErrors();

        $invoice = $this->visit->fresh()->invoices()->firstOrFail();
        $this->assertSame('3000.00', (string) $invoice->discount);
        $this->assertSame('5000.00', (string) $invoice->total);
        $this->assertSame('5000.00', (string) $invoice->balance);
    }

    public function test_the_discount_cannot_be_changed_once_invoiced(): void
    {
        $this->chargedOrder();
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        $this->panel()
            ->call('openDiscount')
            ->set('discountValue', '1000')
            ->call('saveDiscount')
            ->assertHasErrors('discountValue');

        $this->assertSame('0.00', (string) $this->visit->fresh()->discount_value);
    }

    public function test_a_billing_reader_cannot_discount(): void
    {
        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $reader->syncSpatieRole();
        $reader->givePermissionTo('billing.view');
        $this->actingAs($reader);

        $this->panel()->call('openDiscount')->assertForbidden();
    }

    // ── A percentage is a rule, not a figure ─────────────────────────────

    /** Ten per cent of a bill that grows is not what it was when agreed. */
    public function test_a_percentage_follows_the_bill(): void
    {
        $this->chargedOrder();   // 8,000

        $this->panel()
            ->call('openDiscount')
            ->set('discountType', DiscountType::Percent->value)
            ->set('discountValue', '10')
            ->set('discountReason', 'Staff rate')
            ->call('saveDiscount')
            ->assertHasNoErrors();

        $this->assertSame('800.00', $this->panel()->get('totals')['discount']);

        // The bill grows; the rule keeps up.
        $order = Order::where('visit_id', $this->visit->id)->firstOrFail();
        app(BillingService::class)->addServiceLine($order, $this->service('Extra', '2000'), 1, $this->admin->id);

        $this->assertSame('1000.00', $this->panel()->get('totals')['discount']);
    }

    public function test_a_percentage_over_a_hundred_is_refused(): void
    {
        $this->chargedOrder();

        $this->panel()
            ->call('openDiscount')
            ->set('discountType', DiscountType::Percent->value)
            ->set('discountValue', '150')
            ->call('saveDiscount')
            ->assertHasErrors('discountValue');
    }

    /** All of it is allowed, and leaves nothing to pay. */
    public function test_a_hundred_per_cent_leaves_nothing_due(): void
    {
        $this->chargedOrder();

        app(BillingService::class)->applyDiscount(
            $this->visit, DiscountType::Percent, '100', 'Written off', $this->admin->id,
        );

        $totals = $this->panel()->get('totals');
        $this->assertSame('8000.00', $totals['discount']);
        $this->assertSame('0.00', $totals['due']);
    }

    // ── Tax is charged on what is actually paid ──────────────────────────

    /**
     * A discount reduces the taxable base.
     *
     * It used to be computed on the full base with the discount taken off
     * afterwards, which charged the patient VAT on money they were never
     * asked for.
     */
    public function test_a_discount_reduces_the_tax(): void
    {
        $this->withTax('18');
        $this->chargedOrder();   // 8,000, taxable

        $before = $this->panel()->get('totals');
        $this->assertSame('1440.00', $before['tax'], 'the tax is not 18% of the charges');
        $this->assertSame('9440.00', $before['due']);

        app(BillingService::class)->applyDiscount(
            $this->visit, DiscountType::Amount, '2000', 'Goodwill', $this->admin->id,
        );

        $after = $this->panel()->get('totals');
        $this->assertSame('6000.00', $after['taxable'], 'the discount did not come off the taxable base');
        $this->assertSame('1080.00', $after['tax'], 'tax was charged on money nobody pays');
        $this->assertSame('7080.00', $after['due']);
    }

    /** An exempt line is never taxed, discount or no discount. */
    public function test_an_exempt_line_is_not_taxed(): void
    {
        $this->withTax('18');

        $order = app(OrderService::class)->place($this->visit, OrderType::Lab, 'Bloods', [], $this->admin->id);
        $exempt = Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Exempt care',
            'price' => '5000', 'tax_exempt' => true, 'is_active' => true,
        ]);
        app(BillingService::class)->addServiceLine($order, $exempt, 1, $this->admin->id);
        app(BillingService::class)->addServiceLine($order, $this->service('Taxed care', '5000'), 1, $this->admin->id);

        $totals = $this->panel()->get('totals');

        $this->assertSame('10000.00', $totals['subtotal']);
        $this->assertSame('5000.00', $totals['taxable'], 'the exempt line was taxed');
        $this->assertSame('900.00', $totals['tax']);
    }

    /** A discount on a half-exempt bill takes a proportional bite. */
    public function test_a_discount_on_a_half_exempt_bill_is_proportional(): void
    {
        $this->withTax('18');

        $order = app(OrderService::class)->place($this->visit, OrderType::Lab, 'Bloods', [], $this->admin->id);
        $exempt = Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Exempt care',
            'price' => '5000', 'tax_exempt' => true, 'is_active' => true,
        ]);
        app(BillingService::class)->addServiceLine($order, $exempt, 1, $this->admin->id);
        app(BillingService::class)->addServiceLine($order, $this->service('Taxed care', '5000'), 1, $this->admin->id);

        // A tenth off the bill takes a tenth off the taxable part, not all of
        // it and not none.
        app(BillingService::class)->applyDiscount(
            $this->visit, DiscountType::Percent, '10', 'Staff rate', $this->admin->id,
        );

        $totals = $this->panel()->get('totals');
        $this->assertSame('1000.00', $totals['discount']);
        $this->assertSame('4500.00', $totals['taxable']);
        $this->assertSame('810.00', $totals['tax']);
        $this->assertSame('9810.00', $totals['due']);
    }

    /** And the invoice is raised with exactly the figures the screen showed. */
    public function test_the_invoice_matches_what_the_bill_showed(): void
    {
        $this->withTax('18');
        $this->chargedOrder();
        app(BillingService::class)->applyDiscount(
            $this->visit, DiscountType::Percent, '25', 'Staff rate', $this->admin->id,
        );

        $shown = $this->panel()->get('totals');

        $this->panel()->call('openInvoice')->call('generateInvoice')->assertHasNoErrors();

        $invoice = $this->visit->fresh()->invoices()->firstOrFail();
        $this->assertSame($shown['due'], (string) $invoice->total, 'the invoice does not say what the screen said');
        $this->assertSame($shown['discount'], (string) $invoice->discount);
        $this->assertSame($shown['tax'], (string) $invoice->tax_total);
    }

    /** Settings live in the hospital's own JSON, as everywhere else. */
    private function withTax(string $rate): void
    {
        $this->hospital->update(['settings' => ['billing' => [
            'tax_enabled' => true, 'tax_rate' => $rate, 'tax_label' => 'VAT',
        ]]]);

        // The settings object memoises the hospital it resolved.
        app(CurrentHospital::class)->set($this->hospital->id);
        app()->forgetInstance(\App\Support\HospitalSettings::class);
    }

    // ── The next step, under the section that earns it ───────────────────

    /** The gate out of Billing belongs under the bill that opens it. */
    public function test_the_bill_shows_the_next_step_once_it_is_invoiced(): void
    {
        $this->chargedOrder();

        // Still at Ongoing: this section does not own that gate.
        $this->panel()->assertDontSee('Ready for payment');

        app(\App\Services\VisitService::class)->overrideState(
            $this->visit, \App\Enums\VisitStatus::Ongoing, \App\Enums\VisitStage::Billing,
            null, $this->admin->id, 'Set up',
        );

        // At Billing with no invoice — the gate is shut, and says why.
        $this->panel()->assertSee('No invoice has been generated yet.');

        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        $this->panel()->assertSee('Ready for payment');
    }

    public function test_pressing_it_moves_the_visit(): void
    {
        $this->chargedOrder();
        app(\App\Services\VisitService::class)->overrideState(
            $this->visit, \App\Enums\VisitStatus::Ongoing, \App\Enums\VisitStage::Billing,
            null, $this->admin->id, 'Set up',
        );
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);

        $this->panel()->call('advanceVisit')->assertDispatched('visit-updated');

        $this->assertSame(\App\Enums\VisitStage::Payment, $this->visit->fresh()->stage);
    }
}
