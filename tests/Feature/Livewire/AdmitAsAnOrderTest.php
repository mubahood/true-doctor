<?php

namespace Tests\Feature\Livewire;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Livewire\Admissions\Board as OccupancyBoard;
use App\Livewire\Admissions\Index as AdmissionsIndex;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admitting a patient is raising an order on a visit.
 *
 * AdmissionService has always done it: a stay is placed as an Admission order,
 * in progress from the moment the patient is in the bed, and it stays open
 * until discharge — which is what holds the visit's gate shut while somebody
 * is still in a bed.
 *
 * What these tests hold is that the SCREENS agree with that. The admit dialog
 * has to say which visit the stay will hang off, the occupancy board has to
 * raise it the same way the admissions list does, and neither may attach a
 * stay to a visit belonging to somebody else.
 */
class AdmitAsAnOrderTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
        $this->actingAs($this->nurse);
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function bed(string $name = 'B-01'): Bed
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'is_active' => true]);

        return Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'name' => $name,
            'daily_charge' => '50000.00',
        ]);
    }

    // ── The stay is an order ─────────────────────────────────────────────

    public function test_admitting_from_the_list_raises_an_open_order_on_the_visit(): void
    {
        $patient = $this->patient();
        $bed = $this->bed();

        Livewire::test(AdmissionsIndex::class)
            ->call('create')
            ->set('visit_id', app(VisitService::class)->currentOrOpenFor($patient, $this->nurse->id)->id)
            ->set('bed_id', $bed->id)
            ->set('stay_title', 'Post-operative stay')
            ->set('reason', 'Recovering from theatre')
            ->call('save')
            ->assertHasNoErrors();

        $admission = Admission::firstOrFail();
        $this->assertNotNull($admission->visit_id);

        $order = Order::where('subject_type', $admission->getMorphClass())
            ->where('subject_id', $admission->id)
            ->firstOrFail();

        $this->assertSame(OrderType::Admission, $order->type);
        // In progress, not pending: the patient is in the bed as of now.
        $this->assertSame(OrderStatus::InProgress, $order->status);
        $this->assertSame($admission->visit_id, $order->visit_id);
        $this->assertSame('Post-operative stay', $order->title);
    }

    /** The board and the list must raise a stay identically. */
    public function test_admitting_from_the_occupancy_board_raises_the_same_order(): void
    {
        $patient = $this->patient();
        $bed = $this->bed('M-04');

        Livewire::test(OccupancyBoard::class)
            ->call('peek', $bed->id)
            ->call('openAdmitHere')
            ->assertSet('showAdmit', true)
            // The bed is carried in, so nobody has to find it again in a picker.
            ->assertSet('bed_id', $bed->id)
            // …and the quick view closed rather than stacking under it.
            ->assertSet('showPeek', false)
            // Visit-first, the same as the admissions list: one dialog, one
            // contract, whichever screen opened it.
            ->set('visit_id', app(\App\Services\VisitService::class)->currentOrOpenFor($patient)->id)
            ->call('admit')
            ->assertHasNoErrors();

        $admission = Admission::firstOrFail();

        $this->assertSame($bed->id, $admission->bed_id);
        $this->assertNotNull($admission->visit_id);
        $this->assertDatabaseHas('orders', [
            'visit_id' => $admission->visit_id,
            'type' => OrderType::Admission->value,
            'status' => OrderStatus::InProgress->value,
            'title' => 'Inpatient stay',
        ]);
    }

    public function test_the_dialog_names_the_patient_the_chosen_visit_belongs_to(): void
    {
        // The form asks for a visit; it has to say WHO that admits, or
        // somebody is choosing a visit number and hoping.
        $patient = $this->patient();
        $open = app(VisitService::class)->currentOrOpenFor($patient, $this->nurse->id);

        $component = Livewire::test(AdmissionsIndex::class)
            ->call('create')
            ->set('visit_id', $open->id);

        $component->assertSee($patient->full_name)->assertSee($open->visit_no);

        $component->set('bed_id', $this->bed()->id)->call('save')->assertHasNoErrors();

        $this->assertSame($open->id, Admission::firstOrFail()->visit_id);
        $this->assertSame($patient->id, Admission::firstOrFail()->patient_id);
        $this->assertSame(1, Visit::where('patient_id', $patient->id)->count());
    }

    public function test_with_no_visit_chosen_the_dialog_says_how_to_get_one(): void
    {
        // A stay cannot be raised without the visit it is an order on — the
        // same rule a lab order follows. Somebody who has just arrived has no
        // visit yet, so the way out is on the screen rather than implied.
        Livewire::test(AdmissionsIndex::class)
            ->call('create')
            ->assertSee('A stay is raised on a visit')
            ->assertSee('open a visit for them first');
    }

    public function test_a_named_visit_is_used_instead_of_the_open_one(): void
    {
        $patient = $this->patient();
        $chosen = app(VisitService::class)->currentOrOpenFor($patient, $this->nurse->id);

        Livewire::test(AdmissionsIndex::class)
            ->call('create')
            ->set('visit_id', $chosen->id)
            ->set('bed_id', $this->bed()->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($chosen->id, Admission::firstOrFail()->visit_id);
    }

    // ── The gap this closed ──────────────────────────────────────────────

    /**
     * One patient's stay used to be attachable to another patient's visit,
     * which put the bed charge on the wrong bill.
     */
    public function test_a_stay_can_no_longer_be_raised_on_somebody_elses_visit(): void
    {
        // It is not refused any more — it is unreachable. The form asks for
        // one thing, the visit, and the patient is whoever it belongs to, so
        // there is no second field left to disagree with it. The service
        // still refuses the combination (see the test below), because the
        // API and the sync handlers can still be handed both.
        $stranger = $this->patient();
        $theirVisit = app(VisitService::class)->currentOrOpenFor($stranger, $this->nurse->id);

        Livewire::test(AdmissionsIndex::class)
            ->call('create')
            ->set('visit_id', $theirVisit->id)
            ->set('bed_id', $this->bed()->id)
            ->call('save')
            ->assertHasNoErrors();

        // It admitted the stranger — the person whose visit it is — rather
        // than putting their bed charge on anybody else's bill.
        $this->assertSame($stranger->id, Admission::firstOrFail()->patient_id);
    }

    public function test_the_service_refuses_the_same_thing_directly(): void
    {
        $patient = $this->patient();
        $stranger = $this->patient();
        $theirVisit = app(VisitService::class)->currentOrOpenFor($stranger, $this->nurse->id);

        $this->expectException(\App\Exceptions\VisitMismatchException::class);

        app(AdmissionService::class)->admit($patient, $this->bed(), [
            'visit_id' => $theirVisit->id,
        ], $this->nurse->id);
    }

    /**
     * A patient already in a bed cannot be admitted to a second one, and the
     * refusal has to land somewhere it can be seen.
     *
     * It used to be attached to `patient_id`. With the form asking for a
     * visit instead, that field no longer exists on the screen — the message
     * would have been rendered against nothing and the dialog would have
     * looked like it simply did not respond.
     */
    public function test_a_patient_already_in_a_bed_is_refused_beside_the_visit(): void
    {
        $patient = $this->patient();
        app(AdmissionService::class)->admit($patient, $this->bed('A-1'), [], $this->nurse->id);

        $visit = app(VisitService::class)->currentOrOpenFor($patient, $this->nurse->id);

        Livewire::test(AdmissionsIndex::class)
            ->call('create')
            ->set('visit_id', $visit->id)
            ->set('bed_id', $this->bed('A-2')->id)
            ->call('save')
            ->assertHasErrors(['visit_id'])
            ->assertSee('already admitted');

        $this->assertSame(1, Admission::count());
    }
}
