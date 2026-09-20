<?php

namespace Tests\Feature\Livewire;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Livewire\Visits\Actions;
use App\Livewire\Visits\Index;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The visit dialog, opened from the list.
 *
 * Two rules it rests on. First, the dialog does NOT re-implement the detail
 * page's panels — it mounts them, so the two surfaces cannot drift. Second, it
 * renders EVERY section in one pass: the rail jumps by scrolling, never by
 * fetching, so there is no state in which a section is absent because nobody
 * has asked for it yet.
 *
 * Each panel's own behaviour stays in VisitShowTest, which already covers it.
 */
class VisitActionsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = app(VisitService::class)->open(['patient_id' => $patient->id]);
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role]);
        $user->syncSpatieRole();
        $this->actingAs($user);

        return $user;
    }

    // ── The menu offers what this person may actually do ─────────────────

    /**
     * A row menu is for the two or three things anyone does from a list.
     *
     * It used to run to nine entries under four headings, which is a wall —
     * and six of them pointed at sections that had stopped existing.
     */
    public function test_the_row_menu_offers_every_action_an_admin_may_take(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Index::class)
            ->assertSee('Actions for visit '.$this->visit->visit_no)
            ->assertSee('Open visit')
            ->assertSee('Add an order')
            ->assertSee('Cancel visit')
            ->assertSee('Bill &amp; payments', escape: false)
            ->assertSee('Full page')
            ->assertSee('Visit report')
            ->assertSee('Set state');
    }

    /**
     * The patient's record is reached from their NAME in the row, not from a
     * menu of things done to the visit. The menu holds the two or three things
     * anyone does from a list, and it has a hard ceiling; the record was the
     * entry that was not about this visit at all.
     */
    public function test_the_patients_name_is_the_way_to_their_record(): void
    {
        $this->actAs('hospital_admin');

        $html = Livewire::test(Index::class)->html();

        $this->assertStringContainsString(
            'href="'.route('admin.patients.show', $this->visit->patient).'"',
            $html,
            'the row does not link the patient anywhere',
        );
        $this->assertStringNotContainsString('Patient record', $html, 'the menu grew the record back');
    }

    /** No headings, and nothing the click would refuse. */
    public function test_the_menu_stays_short(): void
    {
        $this->actAs('hospital_admin');

        $html = Livewire::test(Index::class)->html();

        $this->assertSame(0, substr_count($html, 'tb-menu-sec'),
            'the row menu grew headings again');
        $this->assertLessThanOrEqual(8, substr_count($html, 'role="menuitem"'),
            'the row menu grew back into a wall');
    }

    /**
     * The menu may only name sections the dialog actually has.
     *
     * This is the test that was missing when six entries pointed at sections
     * that had stopped existing and silently fell back to Summary. Asserting
     * the labels could never catch that; asserting the destinations does.
     */
    public function test_every_section_the_menu_names_is_a_real_section(): void
    {
        $this->actAs('hospital_admin');

        $html = Livewire::test(Index::class)->html();

        preg_match_all("/section:\s*'([a-z-]+)'/", $html, $matches);

        $this->assertNotEmpty($matches[1], 'the menu names no sections at all');

        $unknown = array_values(array_unique(array_diff($matches[1], array_keys(Actions::SECTIONS))));

        $this->assertSame([], $unknown,
            'the menu points at sections the dialog does not have: '.implode(', ', $unknown));
    }

    /** A menu that offers what the click would refuse teaches people to distrust it. */
    public function test_the_menu_hides_actions_the_role_may_not_take(): void
    {
        $this->actAs('lab_technician');   // visits.view only

        Livewire::test(Index::class)
            ->assertSee('Open visit')
            ->assertDontSee('Cancel visit')
            ->assertDontSee('Add an order')
            ->assertDontSee('Bill &amp; payments', escape: false)
            ->assertDontSee('Set state');
    }

    /** A move is offered only once its gate is open. */
    public function test_the_menu_offers_the_next_step_only_when_it_is_reachable(): void
    {
        $this->actAs('hospital_admin');
        app(VisitService::class)->start($this->visit);

        // An open order holds the gate shut.
        $order = app(OrderService::class)->place($this->visit->fresh(), OrderType::Procedure, 'Dressing');
        Livewire::test(Index::class)->assertDontSee('Ready for billing');

        $order->update(['status' => OrderStatus::Completed]);
        Livewire::test(Index::class)->assertSee('Ready for billing');
    }

    /** A finished visit is not offered more work. */
    public function test_a_finished_visit_offers_no_new_work(): void
    {
        app(VisitService::class)->cancel($this->visit, null, 'Called off');
        $this->actAs('hospital_admin');

        Livewire::test(Index::class)
            ->assertDontSee('Add an order')
            ->assertDontSee('Cancel visit')
            ->assertDontSee('Ready for billing')
            ->assertSee('Set state');   // putting it back IS offered
    }

    // ── Moving a visit on, from the list ─────────────────────────────────

    public function test_a_row_can_move_the_visit_on_when_the_gate_is_open(): void
    {
        $this->actAs('hospital_admin');
        app(VisitService::class)->start($this->visit);

        Livewire::test(Index::class)
            ->call('advanceVisit', $this->visit->id)
            ->assertDispatched('visit-updated');

        $this->assertSame(VisitStage::Billing, $this->visit->fresh()->stage);
    }

    /** And says what is holding it when the gate is shut. */
    public function test_a_shut_gate_says_what_is_holding_it(): void
    {
        $this->actAs('hospital_admin');
        app(VisitService::class)->start($this->visit);
        app(OrderService::class)->place($this->visit->fresh(), OrderType::Procedure, 'Dressing');

        Livewire::test(Index::class)
            ->call('advanceVisit', $this->visit->id)
            ->assertDispatched('toast', message: '1 order is still open.', type: 'error');

        $this->assertSame(VisitStage::Ongoing, $this->visit->fresh()->stage);
    }

    public function test_a_role_without_visits_manage_cannot_move_from_a_row(): void
    {
        $this->actAs('lab_technician');

        Livewire::test(Index::class)
            ->call('advanceVisit', $this->visit->id)
            ->assertForbidden();

        $this->assertSame(VisitStage::Ongoing, $this->visit->fresh()->stage);
    }

    /** Add an order opens the visit at its orders with the form already up. */
    public function test_add_an_order_opens_the_dialog_with_the_form(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'orders', true)
            ->assertSet('show', true)
            ->assertSet('section', 'orders')
            ->assertDispatched('orders-add');
    }

    // ── One page, every section, in one pass ─────────────────────────────

    /**
     * The whole visit arrives at once. If a section were only rendered on
     * demand, the rail would be a set of fetches rather than a map, which is
     * exactly what this dialog is not.
     */
    public function test_the_dialog_renders_every_section_at_once(): void
    {
        $this->actAs('hospital_admin');

        $html = Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->assertSet('show', true)
            ->html();

        foreach (['vs-summary', 'vs-orders', 'vs-bill', 'vs-payments', 'vs-history'] as $section) {
            $this->assertStringContainsString('id="'.$section.'"', $html, "{$section} is missing from the dialog");
        }
    }

    /**
     * The panels are the real components, mounted whole — not the deferred
     * placeholders they carry #[Lazy] for on the detail page. A placeholder
     * would mean a round trip the moment the reader scrolled, which is the
     * reloading this dialog exists to avoid.
     */
    public function test_every_panel_is_mounted_rather_than_deferred(): void
    {
        $this->actAs('hospital_admin');

        $html = Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->html();

        $this->assertStringNotContainsString(
            '__lazyLoad',
            $html,
            'a panel is still deferred — scrolling to it would cost a fetch',
        );

        // The host plus the five panels the dialog now mounts: vitals and
        // clinical inside the summary, then orders, bill and payments. Lab,
        // imaging, prescriptions and dispensing are no longer panels of their
        // own — they are kinds of order, reached by a lens on one list. The
        // seventh is the order dialog, which hangs off the orders list because
        // managing one order is its own surface (docs/orders.md).
        $this->assertSame(
            7,
            substr_count($html, 'wire:id'),
            'the dialog does not mount exactly the host, its five panels and the order dialog',
        );
    }

    /**
     * The dialog is a record with actions on it, not a form to fill in. Every
     * section shows what is there and offers a button; the fields themselves
     * live in each panel's own dialog, opened on demand.
     */
    public function test_the_dialog_shows_information_with_actions_not_open_forms(): void
    {
        $this->actAs('hospital_admin');

        $html = Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->html();

        foreach (['Record vitals', 'Write notes', 'Add order', 'Add charge', 'Generate invoice'] as $action) {
            $this->assertStringContainsString($action, $html, "the {$action} button is missing");
        }

        // None of the write fields are sitting open on the page.
        foreach (['id="ch-service"', 'id="v-temperature"', 'id="cl-diagnosis"',
            'wire:model="test_ids"', 'wire:model="study_ids"'] as $field) {
            $this->assertStringNotContainsString(
                $field,
                $html,
                "{$field} is open on the page — the dialog should read as a record, not a form",
            );
        }
    }

    /**
     * The quick action sits in the dialog HEADER, not only inside the Orders
     * panel — so it is one click away however far down the visit a reader has
     * scrolled. Asserting the header slot specifically, because "Add order"
     * also appears on the panel below and would pass either way.
     */
    public function test_add_order_is_a_quick_action_in_the_dialog_header(): void
    {
        $this->actAs('hospital_admin');

        $html = Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->html();

        $this->assertStringContainsString('tb-modal-head-acts', $html, 'the header has no actions slot');

        $head = substr($html, 0, strpos($html, 'tb-modal-sub') ?: 0);
        $this->assertStringContainsString('orders-add', $head, 'the quick action is not in the header');
    }

    /** `section` is a scroll target handed to the browser, never a view. */
    public function test_the_requested_section_is_handed_to_the_pane(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'orders')
            ->assertSet('section', 'orders')
            ->assertSee("tdVisitPane('orders')", escape: false);
    }

    public function test_an_unknown_section_falls_back_to_the_summary(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'drop-tables')
            ->assertSet('show', true)
            ->assertSet('section', 'summary');
    }

    /**
     * The dialog loads the visit once, not once per thing on it.
     *
     * Opening costs a FIXED number of queries — the eager-loaded visit plus a
     * bounded set per panel — so a visit with forty charge lines costs exactly
     * what an empty one costs. This is the guard against someone later adding
     * a lookup inside a loop: the number may drift a little as panels change,
     * but it must never grow with the SIZE of the visit.
     */
    public function test_opening_costs_the_same_whatever_is_on_the_visit(): void
    {
        $this->actAs('hospital_admin');

        // One listener for the whole test; the counter is what gets reset, so
        // the work of seeding the rows below is never counted as a render.
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $open = function () use (&$queries): int {
            $queries = 0;
            Livewire::test(Actions::class)->call('openAction', $this->visit->id, 'summary')->html();

            return $queries;
        };

        $empty = $open();

        foreach (range(1, 40) as $i) {
            $service = Service::factory()->create([
                'hospital_id' => $this->hospital->id, 'price' => '1000', 'is_active' => true,
            ]);
            app(BillingService::class)->orderService($this->visit->fresh(), $service->id, 1);
        }

        $loaded = $open();

        // Not equality: an empty visit actually costs a little MORE, because
        // empty panels run their "is there anything here?" lookups. The
        // invariant is that cost does not GROW with the visit — forty rows must
        // not cost forty reads.
        $this->assertLessThanOrEqual(
            $empty,
            $loaded,
            "opening a visit with 40 charge lines cost {$loaded} queries against {$empty} for an empty one — something reads per row",
        );
    }

    // ── The rail is a map: only what this person gets, with what is on it ──

    public function test_the_rail_counts_what_is_on_the_visit(): void
    {
        $this->actAs('hospital_admin');

        $sections = Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->get('sections');

        $this->assertSame(0, $sections['orders']['count']);
        $this->assertSame(0, $sections['payments']['count']);
        $this->assertNull($sections['summary']['count'], 'the summary is not a count of anything');

        // Orders is one entry like any other — the kinds of order are chips
        // inside the section, not a nested list in the rail.
        $this->assertSame(
            ['summary', 'orders', 'bill', 'payments', 'history'],
            array_keys($sections),
        );
        foreach ($sections as $section) {
            $this->assertArrayNotHasKey('lenses', $section, 'the rail has nested entries again');
        }
    }

    public function test_the_rail_omits_sections_the_role_may_not_open(): void
    {
        $this->actAs('receptionist');   // no lab.order, radiology.order or pharmacy.dispense

        $sections = Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->get('sections');

        // A receptionist holds billing.view, so they keep the money sections;
        // what they do not get is a section of their own per kind of work,
        // because there are none — every kind is a lens on one list.
        $this->assertArrayNotHasKey('lab', $sections);
        $this->assertArrayNotHasKey('imaging', $sections);
        $this->assertArrayNotHasKey('vitals', $sections, 'vitals belongs in the summary');
        $this->assertArrayHasKey('summary', $sections);
        $this->assertArrayHasKey('orders', $sections);
    }

    /** Tenancy: another hospital's visit is a 404 here as everywhere else. */
    public function test_another_hospitals_visit_cannot_be_opened(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = app(VisitService::class)->open([
            'patient_id' => Patient::factory()->create(['hospital_id' => $other->id])->id,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->actAs('hospital_admin');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Actions::class)->call('openAction', $theirs->id, 'summary');
    }

    /**
     * The state band is the LAST thing in the dialog.
     *
     * It is the one control about the visit itself rather than about something
     * on it, and it used to sit between the clinical notes and the orders —
     * interrupting the record with a question that only makes sense once you
     * have read it.
     */
    public function test_where_it_goes_next_is_read_after_everything_it_has_done(): void
    {
        $this->actAs('hospital_admin');
        app(VisitService::class)->start($this->visit);

        $html = Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->html();

        $band = strpos($html, 'tb-vs-stage');
        $this->assertNotFalse($band, 'the state band is gone from the dialog');

        foreach (['vs-summary', 'vs-history'] as $section) {
            $this->assertLessThan(
                $band,
                strpos($html, $section),
                "the state band is printed above {$section} again",
            );
        }
    }

    // ── The gate, from the dialog ────────────────────────────────────────

    public function test_the_gate_moves_the_visit_and_the_dialog_stays_open(): void
    {
        $this->actAs('hospital_admin');
        app(VisitService::class)->start($this->visit);

        // The summary's own gate button, not the one the orders panel shows
        // at the foot of its list — every panel in the dialog is rendered.
        Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->assertSeeHtml('wire:click="advance"')
            ->assertSee('Ready for billing')
            ->call('advance')
            ->assertSet('show', true, 'moving the visit closed the dialog')
            ->assertDispatched('visit-updated');

        $this->assertSame(VisitStage::Billing, $this->visit->fresh()->stage);
    }

    /** A shut gate shows its blocker instead of a button. */
    public function test_a_shut_gate_is_explained_rather_than_greyed_out(): void
    {
        $this->actAs('hospital_admin');
        app(VisitService::class)->start($this->visit);
        app(OrderService::class)->place($this->visit->fresh(), OrderType::Procedure, 'Dressing');

        Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->assertSee('1 order is still open.')
            ->assertDontSeeHtml('wire:click="advance"')
            ->call('advance')
            ->assertDispatched('toast', message: '1 order is still open.', type: 'error');

        $this->assertSame(VisitStage::Ongoing, $this->visit->fresh()->stage);
    }

    /** The last move is nobody's decision, so the dialog says so. */
    public function test_payment_says_it_completes_itself(): void
    {
        $this->actAs('hospital_admin');
        app(VisitService::class)->start($this->visit);
        app(VisitService::class)->overrideState(
            $this->visit->fresh(), VisitStatus::Ongoing, VisitStage::Payment, null, null, 'Set up',
        );

        Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->assertSee('Completes itself once the bill is paid in full.');
    }

    public function test_a_role_without_visits_manage_cannot_move_the_visit(): void
    {
        $this->actAs('lab_technician');

        Livewire::test(Actions::class)
            ->call('openAction', $this->visit->id, 'summary')
            ->call('advance')
            ->assertForbidden();
    }

    // ── Calling it off ───────────────────────────────────────────────────

    public function test_cancelling_from_the_dialog_needs_a_reason(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openCancel', $this->visit->id)
            ->assertSet('showCancel', true)
            ->set('cancelNote', '')
            ->call('cancelVisit')
            ->assertHasErrors('cancelNote');

        $this->assertTrue($this->visit->fresh()->isOpen());
    }

    public function test_cancelling_finishes_the_visit_with_that_outcome(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openCancel', $this->visit->id)
            ->set('cancelNote', 'Patient left')
            ->call('cancelVisit')
            ->assertHasNoErrors()
            ->assertSet('showCancel', false)
            ->assertDispatched('visit-updated');

        $fresh = $this->visit->fresh();
        $this->assertSame(VisitOutcome::Cancelled, $fresh->outcome);
        $this->assertSame('Cancelled', $fresh->stateLabel());
        $this->assertSame('Patient left', $fresh->history()->latest('id')->first()->note);
    }

    // ── Putting it anywhere ──────────────────────────────────────────────

    /** The dialog opens on its own, without the visit dialog behind it. */
    public function test_the_state_dialog_opens_straight_from_a_row(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openStage', $this->visit->id)
            ->assertSet('showStage', true)
            ->assertSet('show', false, 'the whole visit was loaded to change one field')
            ->assertSee('Status')
            ->assertSee('Stage')
            ->assertSee('This skips every check');
    }

    public function test_an_out_of_order_change_demands_a_reason(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openStage', $this->visit->id)
            ->set('stageStage', VisitStage::Payment->value)
            ->set('stageNote', '')
            ->call('changeState')
            ->assertHasErrors('stageNote');

        $this->assertSame(VisitStage::Ongoing, $this->visit->fresh()->stage);
    }

    public function test_an_override_moves_the_visit_and_says_so_in_the_trail(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openStage', $this->visit->id)
            ->set('stageStatus', VisitStatus::Ongoing->value)
            ->set('stageStage', VisitStage::Payment->value)
            ->set('stageNote', 'Paid at the front desk first')
            ->call('changeState')
            ->assertHasNoErrors()
            ->assertDispatched('visit-updated');

        $fresh = $this->visit->fresh();
        $this->assertSame(VisitStage::Payment, $fresh->stage);

        $entry = $fresh->history()->latest('id')->first();
        $this->assertTrue($entry->is_override, 'the trail did not record it as an override');
        $this->assertSame('Paid at the front desk first', $entry->note);
    }

    /** The thing this control exists for: a visit finished by mistake. */
    public function test_a_finished_visit_can_be_put_back(): void
    {
        app(VisitService::class)->cancel($this->visit, null, 'Wrong patient');
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openStage', $this->visit->id)
            ->set('stageStatus', VisitStatus::Ongoing->value)
            ->set('stageStage', VisitStage::Ongoing->value)
            ->set('stageNote', 'Cancelled the wrong one')
            ->call('changeState')
            ->assertHasNoErrors();

        $fresh = $this->visit->fresh();
        $this->assertSame(VisitStatus::Ongoing, $fresh->status);
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->completed_at);
    }

    /** Choosing Completed offers an outcome; choosing anything else takes it away. */
    public function test_the_outcome_picker_follows_the_status(): void
    {
        $this->actAs('hospital_admin');

        Livewire::test(Actions::class)
            ->call('openStage', $this->visit->id)
            ->set('stageStatus', VisitStatus::Completed->value)
            ->assertSet('stageOutcome', VisitOutcome::Closed->value)
            ->assertSee('Outcome')
            ->set('stageStatus', VisitStatus::Ongoing->value)
            ->assertSet('stageOutcome', null);
    }

    /** Only the override permission unlocks it at all. */
    public function test_a_role_with_manage_but_not_override_cannot_open_it(): void
    {
        $this->actAs('receptionist');   // visits.manage, no visits.override

        Livewire::test(Actions::class)
            ->call('openStage', $this->visit->id)
            ->assertForbidden();
    }

    public function test_a_role_without_visits_manage_cannot_open_the_state_dialog(): void
    {
        $this->actAs('lab_technician');

        Livewire::test(Actions::class)
            ->call('openStage', $this->visit->id)
            ->assertForbidden();
    }

    /** Another hospital's visit is a 404 here as everywhere else. */
    public function test_another_hospitals_visit_cannot_have_its_state_changed(): void
    {
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);
        $patient = Patient::factory()->create(['hospital_id' => $theirs->id]);
        $visit = app(VisitService::class)->open(['patient_id' => $patient->id]);

        app(CurrentHospital::class)->set($this->hospital->id);
        $this->actAs('hospital_admin');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Actions::class)->call('openStage', $visit->id);
    }
}
