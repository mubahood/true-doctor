<?php

namespace Tests\Feature\Livewire;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Livewire\Visits\Index as VisitsIndex;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two money columns on the visits list.
 *
 * They mean different things at different points in a visit's life, and the
 * whole value of the columns is that the difference is visible:
 *
 *   TOTAL    live while the visit is being worked — the bill as it stands,
 *            with its standing discount and the hospital's tax — and the
 *            invoice's own frozen figure once one is raised.
 *   BALANCE  nothing at all until there is an invoice, then what is left on it.
 *
 * The arithmetic is BillingService::compute() in both cases, so a total read
 * off this list can never disagree with the same visit's bill panel.
 */
class VisitsMoneyColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

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
    }

    private function visit(): Visit
    {
        return Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);
    }

    private function charge(Visit $visit, string $price, int $qty = 1): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $price]);
        app(BillingService::class)->orderService($visit, $service->id, $qty);
    }

    /** The row as the listing renders it. */
    private function row(Visit $visit): Visit
    {
        $rows = Livewire::test(VisitsIndex::class)->viewData('rows');

        foreach ($rows->items() as $row) {
            if ($row->id === $visit->id) {
                return $row;
            }
        }

        $this->fail('the visit was not in the listing');
    }

    // ── Before anything is charged ───────────────────────────────────────

    public function test_a_visit_with_nothing_on_it_shows_no_total_and_no_balance(): void
    {
        $visit = $this->visit();

        $row = $this->row($visit);

        $this->assertSame('0.00', $row->bill_total);
        $this->assertNull($row->bill_balance, 'an uninvoiced visit was given a balance');

        Livewire::test(VisitsIndex::class)->assertSee('Total')->assertSee('Balance');
    }

    // ── While the work is going on ───────────────────────────────────────

    public function test_the_total_follows_the_bill_while_the_visit_is_open(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '30000.00');
        $this->charge($visit, '8000.00');

        $row = $this->row($visit);

        $this->assertSame('38000.00', $row->bill_total);
        $this->assertNull($row->bill_balance);
    }

    /** A cancelled line is off the bill, so it is off this column too. */
    public function test_a_cancelled_line_does_not_count_towards_the_total(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '30000.00');
        $this->charge($visit, '8000.00');

        // reorder(): the relation itself orders newest-first, so a plain
        // orderBy would be a second clause behind it rather than in front.
        $line = $visit->orderItems()->reorder('order_items.id')->firstOrFail();
        $this->assertSame('30000.00', (string) $line->line_total, 'the wrong line was picked');

        app(OrderService::class)->removeItem($line, $this->admin->id);

        $this->assertSame('8000.00', $this->row($visit->fresh())->bill_total);
    }

    /** The same discount the bill panel is showing, worked the same way. */
    public function test_the_total_carries_the_visits_standing_discount(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '50000.00');

        app(BillingService::class)->applyDiscount($visit->fresh(), DiscountType::Percent, '10', 'Staff relative', $this->admin->id);

        $this->assertSame('45000.00', $this->row($visit->fresh())->bill_total);
    }

    public function test_the_total_agrees_with_the_bill_panel_to_the_cent(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '12345.67', 3);
        app(BillingService::class)->applyDiscount($visit->fresh(), DiscountType::Amount, '1111.11', null, $this->admin->id);

        $billing = app(BillingService::class);
        $panel = $billing->totalsFor($visit->fresh()->load('orderItems'));

        $this->assertSame($panel['due'], $this->row($visit->fresh())->bill_total);
    }

    // ── Once an invoice carries it ───────────────────────────────────────

    public function test_an_invoice_gives_the_row_a_balance(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '20000.00');

        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);

        $row = $this->row($visit->fresh());

        $this->assertSame('20000.00', $row->bill_total);
        $this->assertSame((string) $invoice->balance, $row->bill_balance);
    }

    public function test_a_part_payment_shows_what_is_left(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '20000.00');
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);

        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '7500.00', [], $this->admin->id);

        $this->assertSame('12500.00', $this->row($visit->fresh())->bill_balance);
    }

    public function test_a_settled_visit_reads_as_paid(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '20000.00');
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '20000.00', [], $this->admin->id);

        $row = $this->row($visit->fresh());

        $this->assertSame('0.00', $row->bill_balance);
        Livewire::test(VisitsIndex::class)->assertSee('Paid');
    }

    /**
     * The invoice's figure, not a recalculation. A catalogue reprice after the
     * invoice was raised must never move what somebody was billed.
     */
    public function test_an_invoiced_total_is_the_invoices_own_frozen_figure(): void
    {
        $visit = $this->visit();
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '10000.00']);
        app(BillingService::class)->orderService($visit, $service->id, 1);
        app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);

        $service->update(['price' => '99000.00']);

        $this->assertSame('10000.00', $this->row($visit->fresh())->bill_total);
    }

    // ── It stays one query ───────────────────────────────────────────────

    /**
     * The sums are subqueries on the listing statement. Working them out per
     * row would load every line of every bill on the page, which is what the
     * `withSum` calls exist to avoid.
     */
    public function test_a_page_of_visits_does_not_cost_a_query_per_row(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $visit = $this->visit();
            $this->charge($visit, '1000.00');
            $this->charge($visit, '2000.00');
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Livewire::test(VisitsIndex::class);
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Generous: the point is that it does not grow with the row count.
        $this->assertLessThan(
            30,
            $queries,
            "rendering six visits took {$queries} queries — the money is being worked out per row",
        );
    }

    public function test_another_hospitals_money_is_never_summed_into_a_row(): void
    {
        $visit = $this->visit();
        $this->charge($visit, '5000.00');

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirVisit = Visit::factory()->create([
            'hospital_id' => $other->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $other->id])->id,
        ]);
        $theirService = Service::factory()->create(['hospital_id' => $other->id, 'price' => '999999.00']);
        app(BillingService::class)->orderService($theirVisit, $theirService->id, 1);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertSame('5000.00', $this->row($visit->fresh())->bill_total);
    }
}
