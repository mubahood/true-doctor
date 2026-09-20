<?php

namespace Tests\Feature\Livewire;

use App\Enums\AdmissionStatus;
use App\Enums\BedStatus;
use App\Livewire\Admissions\Board as OccupancyBoard;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The occupancy board: every bed in the hospital and what is in it.
 *
 * This is the screen a ward round runs from and the one the admissions desk is
 * looking at when somebody asks "have you got a bed?". A tile used to navigate
 * to the admission workspace, which is the wrong answer to every question the
 * board is actually asked — so it opens the bed OVER the board, and the things
 * a ward clerk does to a bed are offered from there.
 */
class OccupancyBoardTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    private Ward $ward;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
        $this->actingAs($this->nurse);

        $this->ward = Ward::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Maternity', 'is_active' => true,
        ]);
    }

    private function bed(string $name, BedStatus $status = BedStatus::Available, ?Ward $ward = null): Bed
    {
        return Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => ($ward ?? $this->ward)->id,
            'name' => $name,
            'status' => $status,
            'daily_charge' => '50000.00',
        ]);
    }

    private function patient(string $first = 'Grace', string $last = 'Auma'): Patient
    {
        return Patient::factory()->create([
            'hospital_id' => $this->hospital->id, 'first_name' => $first, 'last_name' => $last,
        ]);
    }

    // ── What the board says about the hospital ───────────────────────────

    public function test_the_figures_count_the_whole_hospital_not_the_filtered_view(): void
    {
        $this->bed('M-01');
        $this->bed('M-02');
        $this->bed('M-03', BedStatus::Maintenance);
        app(AdmissionService::class)->admit($this->patient(), $this->bed('M-04'), [], $this->nurse->id);

        $component = Livewire::test(OccupancyBoard::class);
        $this->assertSame(
            ['total' => 4, 'occupied' => 1, 'free' => 2, 'maintenance' => 1, 'percent' => 25],
            $component->instance()->figures(),
        );

        // Narrowing the board must not re-number the hospital: "two beds free"
        // has to mean two beds free.
        $component->set('search', 'M-01');
        $this->assertSame(4, $component->instance()->figures()['total']);
    }

    public function test_the_board_narrows_by_ward_status_and_who_is_in_the_bed(): void
    {
        $other = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Surgical', 'is_active' => true]);
        $this->bed('M-01');
        $this->bed('S-01', BedStatus::Available, $other);
        $this->bed('M-09', BedStatus::Maintenance);
        app(AdmissionService::class)->admit($this->patient('Joseph', 'Okello'), $this->bed('M-05'), [], $this->nurse->id);

        Livewire::test(OccupancyBoard::class)
            ->set('ward', (string) $other->id)
            ->assertSee('S-01')
            ->assertDontSee('M-01')
            ->set('ward', '')
            ->set('status', BedStatus::Maintenance->value)
            ->assertSee('M-09')
            ->assertDontSee('M-01')
            ->set('status', '')
            // A ward round looks for the patient, not the bed number.
            ->set('search', 'Okello')
            ->assertSee('M-05')
            ->assertDontSee('M-01');
    }

    // ── One bed, over the board ──────────────────────────────────────────

    public function test_an_occupied_bed_opens_with_the_stay_and_what_it_has_cost(): void
    {
        $bed = $this->bed('M-04');
        app(AdmissionService::class)->admit(
            $this->patient(), $bed, ['reason' => 'Obstructed labour'], $this->nurse->id,
        );

        // Two nights in, so the accrued figure is not the trivial one.
        Admission::query()->update(['admitted_at' => now()->subDays(2)]);

        $component = Livewire::test(OccupancyBoard::class)->call('peek', $bed->id)
            ->assertSet('showPeek', true)
            ->assertSee('Grace Auma')
            ->assertSee('Obstructed labour')
            ->assertSee('Bed charge so far');

        $stay = Admission::firstOrFail();
        // Nights × the rate of the bed they are in — the same arithmetic
        // discharge will do, so the dialog cannot disagree with the bill.
        $this->assertSame('100000.00', $component->instance()->accruedSoFar($bed, $stay));
    }

    public function test_a_free_bed_says_who_was_last_in_it(): void
    {
        $bed = $this->bed('M-07');
        $stay = app(AdmissionService::class)->admit($this->patient('Sarah', 'Nabwire'), $bed, [], $this->nurse->id);
        app(AdmissionService::class)->discharge($stay, AdmissionStatus::Discharged, 'Well', $this->nurse->id);

        Livewire::test(OccupancyBoard::class)
            ->call('peek', $bed->fresh()->id)
            ->assertSee('Last patient in it')
            ->assertSee('Sarah Nabwire')
            ->assertSee('Well');
    }

    // ── Acting on a bed from the board ───────────────────────────────────

    public function test_a_bed_is_taken_out_of_service_and_put_back(): void
    {
        $bed = $this->bed('M-02');
        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();

        Livewire::actingAs($admin)->test(OccupancyBoard::class)
            ->call('peek', $bed->id)
            ->call('setBedStatus', 'maintenance');

        $this->assertSame(BedStatus::Maintenance, $bed->fresh()->status);

        Livewire::actingAs($admin)->test(OccupancyBoard::class)
            ->call('peek', $bed->id)
            ->call('setBedStatus', 'available');

        $this->assertSame(BedStatus::Available, $bed->fresh()->status);
    }

    /** Occupied is what admitting somebody DOES, not something set by hand. */
    public function test_a_bed_cannot_be_marked_occupied_by_hand(): void
    {
        $bed = $this->bed('M-02');
        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();

        Livewire::actingAs($admin)->test(OccupancyBoard::class)
            ->call('peek', $bed->id)
            ->call('setBedStatus', 'occupied')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(BedStatus::Available, $bed->fresh()->status);
    }

    public function test_a_bed_with_a_patient_in_it_cannot_be_taken_out_of_service(): void
    {
        $bed = $this->bed('M-02');
        app(AdmissionService::class)->admit($this->patient(), $bed, [], $this->nurse->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();

        Livewire::actingAs($admin)->test(OccupancyBoard::class)
            ->call('peek', $bed->fresh()->id)
            ->call('setBedStatus', 'maintenance')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(BedStatus::Occupied, $bed->fresh()->status);
    }

    public function test_discharging_from_the_board_frees_the_bed_and_closes_the_dialog(): void
    {
        $bed = $this->bed('M-06');
        app(AdmissionService::class)->admit($this->patient(), $bed, [], $this->nurse->id);

        Livewire::test(OccupancyBoard::class)
            ->call('peek', $bed->fresh()->id)
            ->call('openDischarge')
            // The reading dialog steps aside for the acting one, but the bed it
            // was showing is remembered — that is what the discharge acts on.
            ->assertSet('showPeek', false)
            ->assertSet('showDischarge', true)
            ->set('outcome', AdmissionStatus::Discharged->value)
            ->set('discharge_notes', 'Home with baby')
            ->call('discharge')
            ->assertHasNoErrors()
            ->assertSet('showDischarge', false);

        $this->assertSame(BedStatus::Available, $bed->fresh()->status);
        $this->assertSame(AdmissionStatus::Discharged, Admission::firstOrFail()->status);
    }

    public function test_transferring_from_the_board_moves_the_patient(): void
    {
        $from = $this->bed('M-01');
        $to = $this->bed('M-02');
        app(AdmissionService::class)->admit($this->patient(), $from, [], $this->nurse->id);

        Livewire::test(OccupancyBoard::class)
            ->call('peek', $from->fresh()->id)
            ->call('openTransfer')
            ->assertSet('showPeek', false)
            ->set('to_bed_id', $to->id)
            ->set('transfer_reason', 'Closer to the nurses’ station')
            ->call('transfer')
            ->assertHasNoErrors();

        $this->assertSame(BedStatus::Available, $from->fresh()->status);
        $this->assertSame(BedStatus::Occupied, $to->fresh()->status);
        $this->assertSame($to->id, Admission::firstOrFail()->bed_id);
    }

    // ── Gates ────────────────────────────────────────────────────────────

    public function test_another_hospitals_bed_cannot_be_opened(): void
    {
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);
        $theirWard = Ward::factory()->create(['hospital_id' => $theirs->id]);
        $theirBed = Bed::factory()->create(['hospital_id' => $theirs->id, 'ward_id' => $theirWard->id]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(OccupancyBoard::class)->call('peek', $theirBed->id);
    }

    public function test_a_role_without_inpatient_view_cannot_open_the_board(): void
    {
        $recep = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $recep->syncSpatieRole();

        Livewire::actingAs($recep)->test(OccupancyBoard::class)->assertForbidden();
    }
}
