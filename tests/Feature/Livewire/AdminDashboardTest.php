<?php

namespace Tests\Feature\Livewire;

use App\Enums\CardHolderRelationship;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\VisitStage;
use App\Livewire\Dashboard\Section;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\InsuranceLedgerService;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use App\Support\Dashboard\DashboardService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The hospital admin's dashboard, after visits grew stages, work became orders
 * and cards grew an insurer behind them.
 *
 * The page used to open on the size of the patient REGISTER and show two
 * clinical queues — a number nobody acts on and the only two kinds of work that
 * happened to have their own module. What it could not answer was the question
 * an administrator actually walks in with: how much is in the building, what is
 * holding it up, and is the money coming in keeping up with the work going out.
 */
class AdminDashboardTest extends TestCase
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

    private function svc(): DashboardService
    {
        return app(DashboardService::class);
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function visit(): Visit
    {
        return app(VisitService::class)->open(['patient_id' => $this->patient()->id]);
    }

    private function charge(Visit $visit, string $price = '30000.00'): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $price]);
        app(BillingService::class)->orderService($visit, $service->id, 1);
    }

    private function widget(string $name): \Livewire\Features\SupportTesting\Testable
    {
        Livewire::withoutLazyLoading();

        return Livewire::test(Section::class, ['widget' => $name]);
    }

    // ── Where everybody is ───────────────────────────────────────────────

    public function test_the_pipeline_counts_each_stage_of_the_journey(): void
    {
        $notStarted = $this->visit();

        $inCare = $this->visit();
        app(VisitService::class)->start($inCare, $this->admin->id);

        $billing = $this->visit();
        app(VisitService::class)->start($billing, $this->admin->id);
        $this->charge($billing->fresh());
        app(VisitService::class)->advance($billing->fresh(), $this->admin->id, null);

        $counts = $this->svc()->visitsOpen();

        $this->assertSame(3, $counts['open']);
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(1, $counts['inCare']);
        $this->assertSame(1, $counts['billing']);
        $this->assertSame(0, $counts['payment']);
        $this->assertSame(VisitStage::Billing, $billing->fresh()->stage);
        $this->assertNotNull($notStarted);
    }

    /** A finished visit is not "in the building". */
    public function test_a_completed_visit_leaves_the_pipeline(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        app(VisitService::class)->cancel($visit->fresh(), $this->admin->id, 'Left without being seen');

        $this->assertSame(0, $this->svc()->visitsOpen()['open']);
    }

    /**
     * The one figure on the page anybody can act on this morning: a visit whose
     * work is unfinished cannot reach billing, so it sits between the
     * consulting room and the cashier costing everybody time.
     */
    public function test_a_visit_held_up_by_open_work_is_counted_as_blocked(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        $order = app(OrderService::class)->place($visit->fresh(), OrderType::Lab, 'Bloods', [], $this->admin->id);

        $this->assertSame(1, $this->svc()->visitsOpen()['blocked']);

        app(OrderService::class)->saveReport($order, 'Normal.');
        app(OrderService::class)->transition($order->fresh(), OrderStatus::Completed, $this->admin->id);

        $this->assertSame(0, $this->svc()->visitsOpen()['blocked'], 'the visit is still reported as held up');
    }

    public function test_the_pipeline_widget_links_each_stage_to_its_own_list(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);

        $this->widget('admin.visit-flow')
            ->assertSee('Not started')
            ->assertSee('In care')
            ->assertSee('Billing')
            ->assertSee('Payment')
            ->assertSeeHtml(route('admin.visits.index', ['stage' => VisitStage::Payment->value]));
    }

    // ── What is still to be done ─────────────────────────────────────────

    /**
     * Every kind of work, not only the two with a module. A dispensing nobody
     * has picked up holds a visit out of billing exactly as a lab order does.
     */
    public function test_open_work_is_counted_by_the_kind_of_work_it_is(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);

        app(OrderService::class)->place($visit->fresh(), OrderType::Lab, 'Bloods', [], $this->admin->id);
        app(OrderService::class)->place($visit->fresh(), OrderType::Pharmacy, 'Dispense', [], $this->admin->id);
        app(OrderService::class)->place($visit->fresh(), OrderType::Pharmacy, 'Dispense again', [], $this->admin->id);

        $orders = $this->svc()->openOrders();

        $this->assertSame(3, $orders['total']);
        $this->assertSame(2, $orders['byType'][OrderType::Pharmacy->value]);
        $this->assertSame(1, $orders['byType'][OrderType::Lab->value]);

        // Busiest first — the queue to deal with is the one to see first.
        $this->assertSame(OrderType::Pharmacy->value, array_key_first($orders['byType']));
    }

    public function test_a_finished_order_is_no_longer_outstanding(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        $order = app(OrderService::class)->place($visit->fresh(), OrderType::Procedure, 'Dressing', [], $this->admin->id);

        $this->assertSame(1, $this->svc()->openOrders()['total']);

        app(OrderService::class)->saveReport($order, 'Dressed.');
        app(OrderService::class)->transition($order->fresh(), OrderStatus::Completed, $this->admin->id);

        $this->assertSame(0, $this->svc()->openOrders()['total']);
    }

    /** A queue of four that has been four for a week is a different problem. */
    public function test_it_says_how_long_the_oldest_has_been_waiting(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        $order = app(OrderService::class)->place($visit->fresh(), OrderType::Lab, 'Bloods', [], $this->admin->id);

        $order->forceFill(['created_at' => Carbon::now()->subDays(5)])->save();

        $this->assertSame(5, $this->svc()->openOrders()['oldestDays']);
    }

    public function test_the_work_widget_says_so_when_nothing_is_waiting(): void
    {
        $this->widget('admin.work-queue')->assertSee('Nothing is waiting');
    }

    // ── The card desk ────────────────────────────────────────────────────

    /**
     * Held and owed are opposite sides of one instrument. The dashboard used to
     * sum them into a single "float", which said neither.
     */
    public function test_money_held_and_money_owed_are_reported_apart(): void
    {
        $cards = app(CardService::class);

        $inFunds = $cards->issue($this->patient(), []);
        $cards->credit($inFunds, '500.00', null);

        $inDebt = $cards->issue($this->patient(), ['accepts_credit' => true, 'max_credit' => '1000.00']);
        $cards->debit($inDebt, '200.00', null);

        $snapshot = $this->svc()->cardsSnapshot();

        $this->assertSame(2, $snapshot['cards']);
        $this->assertSame('500.00', $snapshot['held']);
        $this->assertSame('200.00', $snapshot['owed'], 'a debt was netted off the money being held');
        $this->assertSame(1, $snapshot['inDebt']);
    }

    public function test_the_insurers_float_is_reported_beside_the_cards(): void
    {
        $insurer = InsuranceProvider::create(['name' => 'Jubilee', 'is_active' => true]);
        app(InsuranceLedgerService::class)->deposit($insurer, '750000.00', null, null, $this->admin->id);

        $snapshot = $this->svc()->cardsSnapshot();

        $this->assertSame('750000.00', $snapshot['float']);
        $this->assertSame(1, $snapshot['insurers']);
    }

    public function test_a_family_card_is_counted_once_not_once_per_holder(): void
    {
        $cards = app(CardService::class);
        $card = $cards->issue($this->patient(), []);
        $cards->credit($card, '100.00', null);
        $cards->addHolder($card, $this->patient(), CardHolderRelationship::Spouse, $this->admin->id);

        $this->assertSame(1, $this->svc()->cardsSnapshot()['cards']);
    }

    public function test_the_card_widget_needs_the_card_permission(): void
    {
        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $this->actingAs($doctor);

        Livewire::withoutLazyLoading();
        Livewire::test(Section::class, ['widget' => 'admin.cards'])->assertForbidden();
    }

    // ── Billed against collected ─────────────────────────────────────────

    /**
     * One line of payments answers "did money come in". Two lines answer the
     * question an administrator has, which is whether it keeps up with the work
     * going out — and the space between them is the outstanding balance.
     */
    public function test_the_money_chart_carries_both_what_was_billed_and_what_came_in(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        $this->charge($visit->fresh(), '80000.00');

        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '30000.00', [], $this->admin->id);

        $trend = $this->svc()->billedAndCollected(14);
        $today = Carbon::now()->toDateString();

        $this->assertSame('80000.00', $trend['billed'][$today]);
        $this->assertSame('30000.00', $trend['collected'][$today]);
        $this->assertSame('80000.00', $trend['billedTotal']);
        $this->assertSame('30000.00', $trend['collectedTotal']);
    }

    /** Both series cover the same days, or the two lines cannot be compared. */
    public function test_both_series_span_the_whole_window_even_where_nothing_happened(): void
    {
        $trend = $this->svc()->billedAndCollected(14);

        $this->assertCount(14, $trend['billed']);
        $this->assertCount(14, $trend['collected']);
        $this->assertSame(array_keys($trend['billed']), array_keys($trend['collected']));
        $this->assertSame('0.00', $trend['billedTotal']);
    }

    public function test_a_voided_invoice_is_not_reported_as_billed(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        $this->charge($visit->fresh(), '40000.00');

        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);
        $invoice->forceFill(['status' => \App\Enums\InvoiceStatus::Void])->save();

        $this->assertSame('0.00', $this->svc()->billedAndCollected(14)['billedTotal']);
    }

    public function test_the_money_widget_names_what_was_billed_and_never_paid(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        $this->charge($visit->fresh(), '80000.00');

        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '30000.00', [], $this->admin->id);

        $this->widget('admin.money-trend')
            ->assertSee('Collected')
            ->assertSee('Billed')
            ->assertSee('Unpaid');
    }

    /**
     * The chart has to be readable, not merely present.
     *
     * A line through fourteen mostly-quiet days hugged the floor and spiked at
     * the end, which reads as a broken chart rather than as a quiet fortnight.
     * Bars against a labelled scale say the same thing truthfully, and a day
     * nobody paid is simply a short bar.
     */
    public function test_the_money_chart_is_drawn_against_a_scale_a_reader_can_use(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        $this->charge($visit->fresh(), '80000.00');
        app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', $this->admin->id);

        $html = $this->widget('admin.money-trend')->html();

        $this->assertStringContainsString('dash-grid-line', $html, 'the chart has no gridlines to read against');
        $this->assertStringContainsString('dash-col-billed', $html);
        $this->assertStringContainsString('dash-col-collected', $html);

        // A short axis label, not the full currency string.
        $this->assertStringContainsString('80k', $html);

        // Every bar names its own day and its two figures.
        $this->assertStringContainsString('billed', $html);
    }

    /** An ampersand in a title is a character, not an entity to escape twice. */
    public function test_no_panel_title_prints_a_raw_html_entity(): void
    {
        foreach (['admin.money-trend', 'admin.cards'] as $widget) {
            $this->assertStringNotContainsString(
                '&amp;amp;',
                $this->widget($widget)->html(),
                "{$widget} double-escapes its title",
            );
        }
    }

    /**
     * A ring with one segment is not a chart.
     *
     * Occupied against available, with the figure anybody actually wants — how
     * full the hospital is — in the middle of it.
     */
    public function test_bed_occupancy_shows_both_halves_and_the_rate(): void
    {
        $ward = \App\Models\Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        \App\Models\Bed::factory()->count(3)->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'status' => \App\Enums\BedStatus::Available,
        ]);
        \App\Models\Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'status' => \App\Enums\BedStatus::Occupied,
        ]);

        $this->widget('admin.occupancy')
            ->assertSee('Occupied')
            ->assertSee('Available')
            ->assertSee('25%')
            ->assertSee('1 of 4 beds in use');
    }

    /**
     * The bar fill is a <span> inside a <span>. Its parent is not a grid
     * container, so it stayed `inline` and neither its height nor its width
     * ever applied — every one of these bars rendered as an empty track.
     */
    public function test_a_bar_actually_draws_its_fill(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);
        app(OrderService::class)->place($visit->fresh(), OrderType::Lab, 'Bloods', [], $this->admin->id);

        $this->widget('admin.work-queue')->assertSeeHtml('dash-bar-fill');

        $css = \Illuminate\Support\Facades\File::get(resource_path('css/admin.css'));
        $this->assertMatchesRegularExpression(
            '/\.dash-bar-fill\{[^}]*display:\s*block/',
            $css,
            'the bar fill is inline again, so it draws nothing',
        );
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_no_figure_on_the_page_reaches_another_hospital(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);

        $theirPatient = Patient::factory()->create(['hospital_id' => $other->id]);
        $theirVisit = app(VisitService::class)->open(['patient_id' => $theirPatient->id]);
        app(VisitService::class)->start($theirVisit, null);
        app(CardService::class)->credit(
            app(CardService::class)->issue($theirPatient, []), '900000.00', null
        );

        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertSame(0, $this->svc()->visitsOpen()['open']);
        $this->assertSame(0, $this->svc()->openOrders()['total']);
        $this->assertSame('0.00', $this->svc()->cardsSnapshot()['held']);
    }

    // ── The page itself ──────────────────────────────────────────────────

    /**
     * The register is a reference number, not today's work, so it moved out of
     * the headline row — but it must still be on the page.
     */
    public function test_the_patient_register_moved_to_the_side_without_disappearing(): void
    {
        $this->widget('admin.stats')->assertDontSee('Patients');
        $this->widget('admin.side-stats')->assertSee('Patients');
    }

    public function test_the_headline_row_leads_with_the_work_in_the_building(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->start($visit, $this->admin->id);

        $this->widget('admin.stats')
            ->assertSee('Open visits')
            ->assertSee('Collected today')
            ->assertSee('Outstanding');
    }

    /** The word was retired from the product; it must not survive in a label. */
    public function test_nothing_on_the_dashboard_says_triage(): void
    {
        foreach (['admin.stats', 'admin.visit-flow', 'admin.work-queue'] as $widget) {
            $this->widget($widget)->assertDontSee('triage', false)->assertDontSee('Triage', false);
        }

        $this->assertFalse(
            method_exists(DashboardService::class, 'awaitingTriage'),
            'the service still carries the old name',
        );
    }
}
