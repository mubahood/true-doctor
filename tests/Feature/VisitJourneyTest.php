<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Livewire\Visits\Actions;
use App\Livewire\Visits\Index;
use App\Models\Bed;
use App\Models\Department;
use App\Models\Dispensation;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\RadiologyOrder;
use App\Models\RadiologyStudy;
use App\Models\Service;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One patient, start to finish, through the screens staff actually use.
 *
 * This is the A-to-Z: a hospital opens, a walk-in is registered, and every
 * piece of their care is recorded FROM THE VISITS LIST — through the row
 * actions menu, which mounts the detail page's own panels in a modal. Each
 * step is taken by the role that really takes it, so the walk proves the
 * permissions as well as the plumbing.
 *
 * Where a unit test asserts one thing in isolation, this asserts the joins:
 * that the bill picks up what the clinicians ordered, that dispensing moves
 * stock, that the pipeline only advances in legal steps, and that every single
 * record created along the way carries the visit it belongs to.
 */
class VisitJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Department $department;

    private Service $consultFee;

    private LabTest $labTest;

    private RadiologyStudy $study;

    private StockItem $drug;

    private Bed $bed;

    /** @var array<string,User> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        // ── Act 1: the hospital opens its doors ──────────────────────────
        $this->hospital = Hospital::factory()->create(['name' => 'St Monica Medical Centre']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->department = Department::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'General Outpatient', 'is_active' => true,
        ]);

        $this->consultFee = Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'General consultation',
            'price' => '30000', 'is_active' => true,
        ]);
        $this->labTest = LabTest::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT', 'price' => '10000', 'is_active' => true,
        ]);
        $this->study = RadiologyStudy::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Chest X-ray', 'price' => '45000', 'is_active' => true,
        ]);

        $category = StockCategory::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Antimalarials']);
        $this->drug = StockItem::factory()->create([
            'hospital_id' => $this->hospital->id, 'stock_category_id' => $category->id,
            'name' => 'Artemether/Lumefantrine 20/120', 'current_quantity' => '100.00',
            'sale_price' => '1500', 'is_active' => true,
        ]);

        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Male Ward']);
        $this->bed = Bed::factory()->create([
            'hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'status' => 'available',
        ]);

        foreach (['receptionist', 'nurse', 'doctor', 'pharmacist', 'accountant', 'hospital_admin'] as $role) {
            $user = User::factory()->create([
                'hospital_id' => $this->hospital->id, 'role' => $role, 'is_active' => true,
            ]);
            $user->syncSpatieRole();
            $this->staff[$role] = $user;
        }
    }

    private function as(string $role): User
    {
        $this->actingAs($this->staff[$role]);

        return $this->staff[$role];
    }

    /** The pipeline control, driven exactly as the row menu drives it. */
    private function action(string $role, Visit $visit, string $action): \Livewire\Features\SupportTesting\Testable
    {
        $this->as($role);

        return Livewire::test(Actions::class)->call('openAction', $visit->id, $action);
    }

    /**
     * One of the panels the row menu mounts.
     *
     * Driven directly, because Livewire's test harness reaches only the
     * component under test and cannot set properties on a nested child. That
     * the menu opens each of these, for the right roles, is proved in
     * VisitActionsTest; this walk is about what they DO once open.
     */
    private function panel(string $role, string $component, Visit $visit): \Livewire\Features\SupportTesting\Testable
    {
        $this->as($role);

        return Livewire::test($component, ['visitId' => $visit->id]);
    }

    public function test_a_walk_in_patient_is_carried_from_the_front_desk_to_a_paid_closed_visit(): void
    {
        // ── Act 2: a walk-in arrives; reception registers them and opens a
        //          visit in ONE transaction, from the visits list. ─────────
        $this->as('receptionist');

        Livewire::test(Index::class)
            ->call('create')
            ->call('setTab', 'intake')
            ->set('first_name', 'Alika')
            ->set('last_name', 'Vinson')
            ->set('sex', 'female')
            ->set('dob', '1991-04-12')
            ->set('phone_1', '+256700123456')
            ->set('consent_given', true)
            // No doctor and no department: reception does not know either yet.
            // The doctor is recorded in Act 4, where the notes are written.
            ->set('reason', 'Fever and headache for three days')
            ->call('save')
            ->assertHasNoErrors();

        $patient = Patient::firstWhere('first_name', 'Alika');
        $this->assertNotNull($patient, 'the walk-in was registered');

        $visit = Visit::firstWhere('patient_id', $patient->id);
        $this->assertNotNull($visit, 'registering a walk-in opened their visit');
        $this->assertStringStartsWith('V-', $visit->visit_no);
        // Opened, not started: a visit becomes Ongoing when work lands on it.
        $this->assertSame(VisitStatus::Pending, $visit->status);
        $this->assertSame('Pending', $visit->stateLabel());

        // The list shows it, with its menu.
        $this->as('receptionist');
        Livewire::test(Index::class)
            ->assertSee($visit->visit_no)
            ->assertSee('Alika Vinson')
            ->assertSee('Actions for visit '.$visit->visit_no);

        // ── Act 3: the nurse takes the vitals. Nobody moves the visit: it
        //          is one state — Ongoing — from the first piece of work
        //          until the bill (docs/visits.md). A nurse holds
        //          visits.vitals but NOT visits.manage, and does not need it.
        $this->panel('nurse', \App\Livewire\Visits\Panels\Vitals::class, $visit)
            ->set('temperature', '38.4')
            ->set('blood_pressure', '118/76')
            ->set('weight', '68.5')
            ->set('height', '172')
            ->set('pulse', '92')
            ->set('spo2', '97')
            ->set('respiratory_rate', '18')
            ->call('save')
            ->assertHasNoErrors();

        $visit->refresh();
        $this->assertSame('38.4', (string) $visit->temperature);
        $this->assertNotNull($visit->vitals_recorded_at);
        $this->assertNotNull($visit->bmi, 'BMI is computed from height and weight by the service');

        // ── Act 4: the doctor sees the patient. ─────────────────────────
        $this->panel('doctor', \App\Livewire\Visits\Panels\Clinical::class, $visit)
            ->set('doctor_user_id', $this->staff['doctor']->id)
            ->set('complaints', 'Fever for three days, headache, joint pain.')
            ->set('diagnosis', 'Uncomplicated malaria, confirmed by RDT.')
            ->set('doctor_remarks', 'Start antimalarials; review in three days.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString('malaria', (string) $visit->fresh()->diagnosis);

        // ── Act 5: orders. Each one bills the visit as it is raised, and
        //          the first one starts the visit by itself. ───────────────
        $this->panel('doctor', \App\Livewire\Visits\Panels\LabOrders::class, $visit)
            ->set('test_ids', [$this->labTest->id])
            ->set('clinical_notes', 'Rule out malaria.')
            ->call('order')
            ->assertHasNoErrors();

        $this->panel('doctor', \App\Livewire\Visits\Panels\RadiologyOrders::class, $visit)
            ->set('study_ids', [$this->study->id])
            ->set('clinical_notes', 'Persistent cough.')
            ->call('order')
            ->assertHasNoErrors();

        $this->panel('doctor', \App\Livewire\Visits\Panels\Prescriptions::class, $visit)
            ->call('openForm')
            ->set('items', [[
                'uid' => 'rx-1',
                'drug_name' => 'Artemether/Lumefantrine 20/120',
                'dosage' => '4 tablets',
                'slots' => ['morning', 'evening'],
                'days' => 3,
                'start_date' => now()->toDateString(),
                'instructions' => 'After food.',
            ]])
            ->set('notes', 'Complete the full course.')
            ->call('prescribe')
            ->assertHasNoErrors();

        $this->assertSame(1, LabOrder::where('visit_id', $visit->id)->count());
        $this->assertSame(1, RadiologyOrder::where('visit_id', $visit->id)->count());
        $this->assertSame(1, Prescription::where('visit_id', $visit->id)->count());

        // ── Act 6: the pharmacy dispenses, and stock moves. ──────────────
        $before = $this->drug->fresh()->current_quantity;

        $this->panel('pharmacist', \App\Livewire\Visits\Panels\Dispense::class, $visit)
            ->set('items', [['uid' => 'dsp-1', 'stock_item_id' => $this->drug->id, 'quantity' => '6']])
            ->set('note', 'Take after meals.')
            ->call('dispense')
            ->assertHasNoErrors();

        $this->assertSame(1, Dispensation::where('visit_id', $visit->id)->count());
        $this->assertSame(
            0,
            bccomp((string) $this->drug->fresh()->current_quantity, bcsub((string) $before, '6', 2), 2),
            'dispensing six tablets took six off the shelf',
        );

        // ── Act 7: the bill. Charges accumulated from every order above.
        //          Done by the admin: the accountant role holds billing.manage
        //          but no visits.view, so a visit's own bill is out of reach
        //          for them (see the note in docs/visits.md). ──────────────
        $this->panel('hospital_admin', \App\Livewire\Visits\Panels\Charges::class, $visit)
            ->set('service_id', $this->consultFee->id)
            ->set('quantity', 1)
            ->call('addLine')
            ->assertHasNoErrors();

        // Billing lines hang off the orders that raised them now, so they are
        // counted through the work rather than off the visit directly.
        $lines = $visit->fresh()->orderItems;
        $this->assertGreaterThanOrEqual(
            4,
            $lines->count(),
            'the bill carries the consultation, the lab test, the imaging study and the drugs',
        );

        $totals = app(BillingService::class)->totalsFor($visit->fresh());
        $this->assertGreaterThan(0, (float) $totals['total'], 'the visit owes money');

        // The gate to Billing is shut while any order is open — billing a
        // visit whose lab work has not come back invoices for work nobody has
        // finished. So every order is finished first, and only then does the
        // button appear (docs/visits.md).
        $this->assertGreaterThan(0, $visit->fresh()->orders()->open()->count());
        $this->action('hospital_admin', $visit, 'summary')
            ->call('advance')
            ->assertDispatched('toast', type: 'error');
        $this->assertSame(VisitStage::Ongoing, $visit->fresh()->stage);

        foreach ($visit->fresh()->orders()->open()->get() as $open) {
            app(OrderService::class)->transition($open, \App\Enums\OrderStatus::Completed);
        }

        $this->action('hospital_admin', $visit, 'summary')->call('advance')->assertHasNoErrors();
        $this->assertSame(VisitStage::Billing, $visit->fresh()->stage);

        $this->panel('hospital_admin', \App\Livewire\Visits\Panels\Charges::class, $visit)
            ->set('discount', '0')
            ->call('generateInvoice')
            ->assertHasNoErrors();

        $invoice = Invoice::where('visit_id', $visit->id)->first();
        $this->assertNotNull($invoice, 'the invoice is raised against the visit');
        $this->assertSame(0, bccomp((string) $invoice->total, (string) $totals['total'], 2));

        // ── Act 8: payment, then the visit closes ITSELF. ───────────────
        $this->action('hospital_admin', $visit, 'summary')->call('advance')->assertHasNoErrors();
        $this->assertSame(VisitStage::Payment, $visit->fresh()->stage);

        // Half of it settles nothing.
        app(BillingService::class)->recordPayment(
            $invoice, PaymentMethod::Cash, '1000', [], $this->staff['accountant']->id
        );
        $this->assertSame(VisitStage::Payment, $visit->fresh()->stage, 'a part payment closed the visit');

        // The last shilling does. Nobody presses anything: a visit cannot
        // finish owing money, and once it owes nothing there is no decision
        // left to make.
        app(BillingService::class)->recordPayment(
            $invoice->fresh(), PaymentMethod::Cash, (string) $invoice->fresh()->balance, [],
            $this->staff['accountant']->id,
        );
        $this->assertSame(0, bccomp((string) $invoice->fresh()->balance, '0.00', 2), 'the bill is settled');

        $visit->refresh();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertSame(VisitStage::Completed, $visit->stage);
        $this->assertSame(VisitOutcome::Closed, $visit->outcome);
        $this->assertNotNull($visit->completed_at);

        // ── Act 9: everything that happened belongs to this one visit. ───
        foreach ([Order::class, LabOrder::class, RadiologyOrder::class,
            Prescription::class, Dispensation::class, Invoice::class] as $model) {
            $this->assertSame(
                0,
                $model::where('visit_id', '!=', $visit->id)->orWhereNull('visit_id')->count(),
                $model.' escaped the visit it belongs to',
            );
        }

        // And every charge reaches the visit through the work that raised it.
        $this->assertSame(0, OrderItem::whereNull('order_id')->count());
        $this->assertSame(
            [$visit->id],
            OrderItem::with('order')->get()->pluck('order.visit_id')->unique()->values()->all(),
            'a charge escaped onto another visit',
        );

        // The whole walk is on the record, in order. reorder() because the
        // relation is newest-first for the UI's history table.
        $trail = $visit->history()->reorder('id')->pluck('to_stage')->all();
        $this->assertSame(
            ['ongoing', 'ongoing', 'billing', 'payment', 'completed'],
            $trail,
            'the audit trail is the walk, in the order it was walked',
        );

        // The relation the UI reads is newest-first AND deterministic. Every
        // row above was written inside the same second, so created_at alone is
        // not a total order — without the id tiebreak the audit trail comes
        // back shuffled, which is how the preview first rendered it.
        $shown = $visit->history->pluck('to_stage')->all();
        $this->assertSame(
            ['completed', 'payment', 'billing', 'ongoing', 'ongoing'],
            $shown,
            'the history the screens show is the walk, newest first',
        );

        // ── Act 10: a closed visit takes no new work, but can be put back.
        $this->as('hospital_admin');
        Livewire::test(Index::class)
            ->assertSee($visit->visit_no)
            ->assertDontSee('Add an order')
            ->assertDontSee('Cancel visit')
            ->assertSee('Set state');

        $this->action('hospital_admin', $visit, 'summary')
            ->assertSee('This visit is finished');
    }

    /** The other ending: a visit that never happened. */
    public function test_a_visit_can_be_cancelled_with_a_reason_from_the_list(): void
    {
        $this->as('receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit = app(\App\Services\VisitService::class)->open(['patient_id' => $patient->id]);

        $this->action('receptionist', $visit, 'summary')
            ->call('openCancel')
            ->set('cancelNote', 'Patient left before being seen')
            ->call('cancelVisit')
            ->assertHasNoErrors();

        $visit->refresh();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertSame(VisitOutcome::Cancelled, $visit->outcome);
        $this->assertSame('Cancelled', $visit->stateLabel());
        $this->assertSame(
            'Patient left before being seen',
            $visit->history()->latest('id')->first()->note,
            'the reason was not recorded against the cancellation',
        );
    }
}
