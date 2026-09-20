<?php

namespace Tests\Feature\Livewire;

use App\Enums\OrderType;
use App\Livewire\Visits\Panels\Orders;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Orders panel: one list of every kind of work, a row apiece.
 *
 * The list stays a list — type, who, when, status, cost — and everything else
 * about an order lives in a dialog over it. That is what keeps a visit with a
 * dozen orders readable.
 */
class VisitOrdersPanelTest extends TestCase
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

    /**
     * Mount the panel the way the visit dialog does — eagerly. It carries
     * #[Lazy] for the detail page, where a panel below the fold should not cost
     * a query; a lazy mount in a test renders only the placeholder.
     */
    private function panel(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Orders::class, ['visitId' => $this->visit->id, 'lazy' => false]);
    }

    private function place(OrderType $type, string $title): Order
    {
        return app(OrderService::class)->place($this->visit, $type, $title, [], $this->doctor->id);
    }

    // ── The list ─────────────────────────────────────────────────────────

    public function test_every_kind_of_work_appears_in_the_one_list(): void
    {
        $this->place(OrderType::Lab, 'Malaria RDT');
        $this->place(OrderType::Admission, 'Admit to Male Ward');

        $this->panel()
            ->assertOk()
            ->assertSee('Malaria RDT')
            ->assertSee('Admit to Male Ward');
    }

    /** The type chips narrow the same list; they never open another one. */
    public function test_a_type_filter_narrows_the_same_list(): void
    {
        $this->place(OrderType::Lab, 'Malaria RDT');
        $this->place(OrderType::Admission, 'Admit to Male Ward');

        $this->panel()
            ->set('filter', OrderType::Lab->value)
            ->assertSee('Malaria RDT')
            ->assertDontSee('Admit to Male Ward')
            ->set('filter', '')
            ->assertSee('Admit to Male Ward');
    }

    // ── One order, in a dialog over the list ─────────────────────────────

    /**
     * The list decides which order, and nothing else. Managing one is its own
     * component (Panels\OrderDetail), which the row opens by name.
     */
    public function test_a_row_opens_the_order_for_managing(): void
    {
        $order = $this->place(OrderType::Lab, 'Malaria RDT');

        $this->panel()
            ->call('view', $order->id)
            ->assertDispatched('order-open', visitId: $this->visit->id, orderId: $order->id);
    }

    /** An order of another visit is a 404, never someone else's data. */
    public function test_another_visits_order_cannot_be_opened(): void
    {
        $other = app(VisitService::class)->open([
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);
        $theirs = app(OrderService::class)->place($other, OrderType::Lab, 'Not mine', [], $this->doctor->id);

        $this->expectException(ModelNotFoundException::class);
        $this->panel()->call('view', $theirs->id);
    }

    // ── Placing one ──────────────────────────────────────────────────────

    public function test_the_header_quick_action_opens_the_same_form(): void
    {
        $this->panel()
            ->call('openAddFromHeader')
            ->assertSet('showAdd', true)
            ->assertSee('What kind of work?');
    }

    public function test_a_plain_order_is_placed_with_what_it_is(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->set('title', 'Wound dressing')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('placed', 'Order placed.');

        $this->assertSame('Wound dressing', Order::where('type', OrderType::Procedure->value)->first()->title);
    }

    // ── What happens after ───────────────────────────────────────────────

    /**
     * The wait covers the whole dialog, footer included, so the submit cannot
     * be pressed a second time while the first is still moving stock.
     */
    public function test_the_form_carries_its_own_waiting_state(): void
    {
        $html = $this->panel()->call('openAdd')->set('type', OrderType::Pharmacy->value)->html();

        $this->assertStringContainsString('tb-working', $html);
        $this->assertStringContainsString('wire:target="add"', $html);
        $this->assertStringContainsString('Placing the order', $html, 'the wait does not name what it is doing');
        // A sibling of the body, not a child of it: the veil has to reach over
        // the footer too, or the submit is still there to be pressed again.
        $this->assertLessThan(
            strpos($html, 'tb-modal-body'),
            strpos($html, 'tb-working'),
            'the veil was nested inside the scrolling body',
        );
    }

    /**
     * The dialog does not vanish on success. Ordering comes in runs — bloods
     * and a scan in the same breath — so it says what it did and asks.
     */
    public function test_placing_one_empties_the_form_and_asks_what_next(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->set('title', 'Wound dressing')
            ->set('notes', 'Left forearm')
            ->call('add')
            ->assertSet('showAdd', true, 'the dialog closed instead of asking')
            ->assertSet('placed', 'Order placed.')
            ->assertSet('title', '')
            ->assertSet('notes', '')
            ->assertSee('Anything else for this patient')
            ->assertSee('Add another')
            ->assertDontSee('What kind of work?');   // the form is gone, not merely hidden
    }

    /** "Add another" starts clean, on the same kind, still assigned to me. */
    public function test_add_another_reopens_a_clean_form_on_the_same_kind(): void
    {
        $someone = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);

        $panel = $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->set('title', 'Wound dressing')
            ->call('picked', 'assigned_to', $someone->id)
            ->call('add');

        $before = $panel->get('formNonce');

        $panel->call('addAnother')
            ->assertSet('placed', null)
            ->assertSet('showAdd', true)
            ->assertSet('type', OrderType::Procedure->value, 'the kind was thrown away')
            ->assertSet('title', '')
            ->assertSet('assigned_to', $this->doctor->id, 'the last pick survived')
            ->assertSee('What kind of work?');

        $this->assertGreaterThan($before, $panel->get('formNonce'), 'the pickers were not remounted');
    }

    /** "Done for now" shuts the whole thing. */
    public function test_done_for_now_closes_the_dialog(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->set('title', 'Wound dressing')
            ->call('add')
            ->call('closeAdd')
            ->assertSet('showAdd', false)
            ->assertSet('placed', null);
    }

    /** Opening the form afresh never lands on a stale success panel. */
    public function test_the_success_panel_does_not_survive_a_reopen(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->set('title', 'Wound dressing')
            ->call('add')
            ->assertSet('placed', 'Order placed.')
            ->call('openAdd')
            ->assertSet('placed', null)
            ->assertSee('What kind of work?');
    }

    /** A refused order keeps the form, and what was typed into it. */
    public function test_a_rejected_order_stays_on_the_form(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->set('title', '')
            ->call('add')
            ->assertHasErrors('title')
            ->assertSet('placed', null)
            ->assertSet('showAdd', true)
            ->assertSee('What kind of work?');
    }

    public function test_a_lab_order_asks_which_tests_and_bills_them(): void
    {
        $test = \App\Models\LabTest::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT', 'price' => '8000', 'is_active' => true,
        ]);

        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Lab->value)
            ->assertSee('Malaria RDT')          // the picker, not a free-text title
            ->set('test_ids', [$test->id])
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::where('type', OrderType::Lab->value)->count());
        $this->assertSame(
            0,
            bccomp(app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal'], '8000.00', 2),
        );
    }

    /**
     * A long catalogue is searched, not shipped. Four hundred lab tests must
     * not all arrive in the browser for JavaScript to hide most of them.
     */
    public function test_a_long_catalogue_is_capped_and_searched_in_the_query(): void
    {
        foreach (range(1, 60) as $i) {
            \App\Models\LabTest::factory()->create([
                'hospital_id' => $this->hospital->id,
                'name' => 'Test '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'is_active' => true,
            ]);
        }
        \App\Models\LabTest::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT', 'is_active' => true,
        ]);

        $panel = $this->panel()->call('openAdd')->set('type', OrderType::Lab->value);

        $this->assertSame(61, $panel->get('catalogueTotal'));
        $this->assertLessThanOrEqual(40, count($panel->get('catalogue')), 'the whole catalogue was shipped');

        // Searching reaches rows the cap left out.
        $panel->set('pick', 'Malaria');
        $this->assertSame(['Malaria RDT'], array_column($panel->get('catalogue'), 'name'));
    }

    /** Narrowing must never silently drop something already ticked. */
    public function test_a_ticked_test_survives_a_search_that_excludes_it(): void
    {
        $picked = \App\Models\LabTest::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT', 'is_active' => true,
        ]);
        \App\Models\LabTest::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Full blood count', 'is_active' => true,
        ]);

        $panel = $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Lab->value)
            ->set('test_ids', [$picked->id])
            ->set('pick', 'blood');

        $names = array_column($panel->get('catalogue'), 'name');
        $this->assertContains('Full blood count', $names);
        $this->assertContains('Malaria RDT', $names, 'a ticked test vanished when the list was narrowed');
    }

    // ── How the work is usually written down ─────────────────────────────

    /** The phrasings cascade with the kind of work, never a single flat list. */
    public function test_the_task_phrasings_follow_the_kind_of_work(): void
    {
        $panel = $this->panel()->call('openAdd')->set('type', OrderType::Procedure->value);
        $this->assertContains('Wound dressing', $panel->get('titleSuggestions'));
        $this->assertNotContains('Admit for observation', $panel->get('titleSuggestions'));

        $panel->set('type', OrderType::Admission->value);
        $this->assertContains('Admit for observation', $panel->get('titleSuggestions'));
        $this->assertNotContains('Wound dressing', $panel->get('titleSuggestions'));
    }

    /**
     * Lab, imaging and pharmacy name a catalogue row rather than a phrase, so
     * offering one there would compete with the picker.
     */
    /**
     * Lab and imaging name catalogue rows, so they get no phrasings.
     *
     * Pharmacy used to be in this list. It is not any more: what was dispensed
     * is an order ITEM, added in the order's own dialog where it moves stock
     * and bills in one transaction — so placing a pharmacy order asks what it
     * is, like every other kind.
     */
    public function test_the_catalogue_kinds_get_no_phrasings(): void
    {
        $panel = $this->panel()->call('openAdd');

        foreach ([OrderType::Lab, OrderType::Imaging] as $type) {
            $panel->set('type', $type->value);
            $this->assertSame([], $panel->get('titleSuggestions'),
                $type->label().' offered phrasings although it picks from a catalogue');
        }

        $panel->set('type', OrderType::Pharmacy->value);
        $this->assertNotEmpty($panel->get('titleSuggestions'),
            'pharmacy takes a free-text title now, so it should be offered words for it');
    }

    /** What this hospital actually writes is offered before the curated set. */
    public function test_what_the_hospital_actually_writes_comes_first(): void
    {
        foreach (range(1, 3) as $i) {
            app(OrderService::class)->place($this->visit, OrderType::Procedure, 'Review BP', [], $this->doctor->id);
        }

        $phrases = $this->panel()->call('openAdd')->set('type', OrderType::Procedure->value)->get('titleSuggestions');

        $this->assertSame('Review BP', $phrases[0], "the hospital's own wording was not offered first");
        $this->assertContains('Wound dressing', $phrases, 'the curated set no longer tops it up');
    }

    /**
     * The migration invented titles for bills that predate orders — a drug name
     * typed as a consultation, "Consultation — 2 items". Learning phrasing from
     * those would teach a hospital its own import artefacts.
     */
    public function test_carried_over_orders_do_not_teach_the_hospital_its_own_artefacts(): void
    {
        $carried = app(OrderService::class)->place(
            $this->visit, OrderType::Consultation, 'Amoxicillin 250mg × 6 tablets',
            ['notes' => Order::CARRIED_OVER], $this->doctor->id,
        );
        $this->assertSame(Order::CARRIED_OVER, $carried->notes);

        $phrases = $this->panel()->call('openAdd')
            ->set('type', OrderType::Consultation->value)->get('titleSuggestions');

        $this->assertNotContains('Amoxicillin 250mg × 6 tablets', $phrases);
        $this->assertContains('Doctor review', $phrases, 'the curated set should fill the gap');
    }

    /** The same phrase in a different case is one suggestion, not two. */
    public function test_a_phrasing_is_not_offered_twice_in_different_cases(): void
    {
        app(OrderService::class)->place($this->visit, OrderType::Procedure, 'wound dressing', [], $this->doctor->id);

        $phrases = array_map('mb_strtolower', $this->panel()->call('openAdd')
            ->set('type', OrderType::Procedure->value)->get('titleSuggestions'));

        $this->assertSame(count($phrases), count(array_unique($phrases)));
    }

    public function test_clicking_a_phrasing_fills_the_field(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->call('useTitle', 'Wound dressing')
            ->assertSet('title', 'Wound dressing');
    }

    /**
     * useTitle only accepts what is on offer. It is a client-callable method,
     * so it must not become a way to write anything at all into the field.
     */
    public function test_use_title_refuses_anything_not_on_offer(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Procedure->value)
            ->call('useTitle', 'Admit for observation')   // a real phrase, wrong kind
            ->assertSet('title', null)
            ->call('useTitle', '<script>alert(1)</script>')
            ->assertSet('title', null);
    }

    /** Changing the kind of work clears what only belonged to the last one. */
    public function test_changing_the_kind_clears_the_previous_choices(): void
    {
        $test = \App\Models\LabTest::factory()->create([
            'hospital_id' => $this->hospital->id, 'is_active' => true,
        ]);

        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Lab->value)
            ->set('test_ids', [$test->id])
            ->set('type', OrderType::Procedure->value)
            ->assertSet('test_ids', [])
            ->assertSet('pick', '');
    }

    /**
     * Whoever raises the order is the likeliest person to do it. Starting empty
     * made everyone pick themselves by hand, every time.
     */
    public function test_the_order_is_assigned_to_whoever_is_raising_it(): void
    {
        $this->panel()
            ->call('openAdd')
            ->assertSet('assigned_to', $this->doctor->id);
    }

    /**
     * Reopening the form must not show the last person picked as though it had
     * been chosen again. The pickers are keyed on a nonce so they remount with
     * the panel's cleared state rather than keeping their own.
     */
    public function test_reopening_the_form_does_not_keep_the_last_pick(): void
    {
        $someone = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);

        $panel = $this->panel()->call('openAdd');
        $first = $panel->get('formNonce');

        $panel->call('picked', 'assigned_to', $someone->id)
            ->assertSet('assigned_to', $someone->id)
            ->set('showAdd', false)
            ->call('openAdd')
            ->assertSet('assigned_to', $this->doctor->id, 'the previous pick survived the reopen');

        $this->assertGreaterThan($first, $panel->get('formNonce'), 'the pickers were not remounted');
    }

    /** The searchable pickers feed the panel by name, and only the known ones. */
    public function test_only_the_panels_own_pickers_can_set_its_fields(): void
    {
        $person = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);

        $this->panel()
            ->call('openAdd')
            ->call('picked', 'assigned_to', $person->id)
            ->assertSet('assigned_to', $person->id)
            ->call('picked', 'something_else', 999)
            ->assertSet('assigned_to', $person->id)
            ->call('cleared', 'assigned_to')
            ->assertSet('assigned_to', null);
    }

    public function test_a_lab_order_needs_at_least_one_test(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Lab->value)
            ->call('add')
            ->assertHasErrors('test_ids');
    }

    // ── A finished visit takes no new work ───────────────────────────────

    /**
     * An order raised on a cancelled visit bills a patient for an attendance
     * the hospital decided did not happen. Reopening it is a stage change
     * away, which is exactly what that control is for.
     */
    public function test_a_cancelled_visit_is_not_offered_new_orders(): void
    {
        app(\App\Services\VisitService::class)->cancel($this->visit, $this->doctor->id, 'Wrong patient');

        $panel = $this->panel()
            ->assertSet('writable', false)
            ->assertDontSee('Add order');

        $panel->call('openAdd');

        $this->assertFalse($panel->get('showAdd'), 'a cancelled visit opened the add form');
    }

    public function test_an_open_visit_is_still_offered_new_orders(): void
    {
        $this->panel()
            ->assertSet('writable', true)
            ->assertSee('Add order');
    }

    // ── Pharmacy is placed like everything else ──────────────────────────

    /**
     * Placing a pharmacy order does NOT ask which drug.
     *
     * It used to ask for a drug and a quantity and dispense them on the spot,
     * through a different service, producing a second order beside the one
     * being placed. What was dispensed is an order ITEM — added in the order's
     * own dialog, where it moves stock and bills in one transaction
     * (docs/orders.md) — so placing one asks what every other kind asks.
     */
    public function test_placing_a_pharmacy_order_does_not_ask_for_a_drug(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Pharmacy->value)
            ->assertDontSee('Search the pharmacy')
            ->assertDontSee('Quantity')
            ->assertSee('What is it?')
            ->assertSee('Assign to')
            ->assertSee('Dispense prescription')      // its own phrasings
            ->assertSee('Place order');
    }

    public function test_a_pharmacy_order_is_placed_with_a_title_and_nothing_else(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Pharmacy->value)
            ->set('title', 'Take-home medicines')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('placed', 'Order placed.');

        $order = Order::where('type', OrderType::Pharmacy->value)->firstOrFail();
        $this->assertSame('Take-home medicines', $order->title);
        $this->assertSame(0, $order->items()->count(), 'placing it billed something before anyone chose a drug');
        $this->assertSame(0, \App\Models\Dispensation::count(), 'placing it created a dispensation of its own');
    }

    /** And the drug is chosen where every other item is — in the order dialog. */
    public function test_the_drug_is_added_as_an_item_and_moves_stock_there(): void
    {
        $drug = \App\Models\StockItem::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Amoxicillin 250mg',
            'unit' => 'tablets', 'sale_price' => '500', 'cost_price' => '200',
            'current_quantity' => '20', 'original_quantity' => '20', 'is_active' => true,
        ]);

        $order = $this->place(OrderType::Pharmacy, 'Take-home medicines');

        Livewire::test(\App\Livewire\Visits\Panels\OrderDetail::class, ['visitId' => $this->visit->id])
            ->call('open', $this->visit->id, $order->id)
            ->set('itemKind', 'product')
            ->call('picked', 'stock_item_id', $drug->id)
            ->set('qty', '6')
            ->call('addItem')
            ->assertHasNoErrors();

        $this->assertSame('3000.00', (string) $order->items()->firstOrFail()->line_total);
        $this->assertSame('14.00', (string) $drug->fresh()->current_quantity, 'the drugs never left the shelf');
    }

    // ── Admission is placed WITH its bed ─────────────────────────────────

    private function ward(string $name = 'General ward'): \App\Models\Ward
    {
        return \App\Models\Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => $name]);
    }

    private function bed(\App\Models\Ward $ward, string $name, string $charge = '50000'): \App\Models\Bed
    {
        return \App\Models\Bed::factory()->create([
            'hospital_id' => $this->hospital->id, 'ward_id' => $ward->id,
            'name' => $name, 'daily_charge' => $charge,
        ]);
    }

    /**
     * A bed is scarce and exclusive, so it is claimed when the work is raised.
     *
     * Same reason a lab order names its tests at placement — and unlike
     * pharmacy, where the drug is an item added later.
     */
    public function test_placing_an_admission_asks_for_the_bed(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Admission->value)
            ->assertSee('Ward')
            ->assertSee('Bed')
            ->assertSee('Admitting doctor')
            ->assertSee('Admit patient')
            ->assertSee('Admit — general ward');   // its phrasings
    }

    public function test_an_admission_without_a_bed_is_refused(): void
    {
        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Admission->value)
            ->set('title', 'Admit — general ward')
            ->call('add')
            ->assertHasErrors('bed_id');

        $this->assertSame(0, \App\Models\Admission::count());
    }

    /** Placing it admits the patient, takes the bed and opens the stay. */
    public function test_placing_an_admission_admits_the_patient(): void
    {
        $ward = $this->ward();
        $bed = $this->bed($ward, 'Bed 1');

        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Admission->value)
            ->set('title', 'Admit — general ward')
            ->call('picked', 'ward_id', $ward->id)
            ->call('picked', 'bed_id', $bed->id)
            ->set('notes', 'Severe dehydration')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('placed', 'Patient admitted.');

        $admission = \App\Models\Admission::firstOrFail();
        $this->assertSame($this->visit->id, $admission->visit_id);
        $this->assertSame($bed->id, $admission->bed_id);
        $this->assertSame(\App\Enums\BedStatus::Occupied, $bed->fresh()->status);

        $order = Order::where('type', OrderType::Admission->value)->firstOrFail();
        $this->assertSame('Admit — general ward', $order->title);
        $this->assertSame($admission->id, $order->subject_id);
        $this->assertSame(\App\Enums\OrderStatus::InProgress, $order->status);
    }

    /** A bed someone else is in cannot be taken, and nothing is left half-done. */
    public function test_an_occupied_bed_is_refused(): void
    {
        $ward = $this->ward();
        $bed = $this->bed($ward, 'Bed 1');
        $bed->update(['status' => \App\Enums\BedStatus::Occupied]);

        $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Admission->value)
            ->set('title', 'Admit — general ward')
            ->call('picked', 'bed_id', $bed->id)
            ->call('add')
            ->assertHasErrors('bed_id');

        $this->assertSame(0, \App\Models\Admission::count());
        $this->assertSame(0, Order::where('type', OrderType::Admission->value)->count());
    }

    /** Choosing a ward drops a bed in another one; clearing it keeps the pick. */
    public function test_the_ward_narrows_the_bed_and_widening_does_not_strand_it(): void
    {
        $general = $this->ward('General ward');
        $maternity = $this->ward('Maternity');
        $bedInGeneral = $this->bed($general, 'G1');

        $panel = $this->panel()
            ->call('openAdd')
            ->set('type', OrderType::Admission->value)
            ->call('picked', 'bed_id', $bedInGeneral->id)
            ->assertSet('bed_id', $bedInGeneral->id)
            ->call('picked', 'ward_id', $maternity->id)
            ->assertSet('bed_id', null, 'a bed in another ward stayed selected');

        $panel->call('picked', 'bed_id', $bedInGeneral->id)
            ->call('picked', 'ward_id', $general->id)
            ->assertSet('bed_id', $bedInGeneral->id, 'narrowing to its own ward dropped the bed')
            ->call('cleared', 'ward_id')
            ->assertSet('bed_id', $bedInGeneral->id, 'widening the search dropped the bed');
    }
}
