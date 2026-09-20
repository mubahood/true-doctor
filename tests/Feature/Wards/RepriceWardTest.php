<?php

namespace Tests\Feature\Wards;

use App\Enums\BedStatus;
use App\Livewire\Wards\Index;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\WardService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Setting one nightly charge across a whole ward.
 *
 * The feature is a convenience; the thing that needs testing is its
 * consequence. `AdmissionService::discharge` reads `beds.daily_charge` **at
 * discharge** and multiplies it by the whole stay, so repricing a bed that
 * somebody is lying in reprices every night already spent in it — at a rate
 * that was not in force when those nights were spent.
 *
 * That behaviour is not being changed here: an administrator putting the ward
 * rate up often does mean "bill everybody the new rate". What is being tested
 * is that it happens predictably, under a lock, in the activity log, and that
 * the screen says so before the button is pressed.
 */
class RepriceWardTest extends TestCase
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

    private function ward(array $attributes = []): Ward
    {
        return Ward::factory()->create(array_merge([
            'hospital_id' => $this->hospital->id,
            'default_daily_charge' => '50000.00',
        ], $attributes));
    }

    private function bed(Ward $ward, string $charge, BedStatus $status = BedStatus::Available): Bed
    {
        return Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'daily_charge' => $charge,
            'status' => $status,
        ]);
    }

    // ── The ward's own rate ──────────────────────────────────────────────

    public function test_a_ward_remembers_what_a_night_in_it_costs(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Maternity')
            ->set('default_daily_charge', '75000.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('75000.00', Ward::where('name', 'Maternity')->value('default_daily_charge'));
    }

    public function test_the_ward_rate_is_bounded_the_way_a_bed_rate_is(): void
    {
        // A ward rate a bed could not legally hold is a rate nobody can apply.
        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Maternity')
            ->set('default_daily_charge', '-1')
            ->call('save')
            ->assertHasErrors('default_daily_charge');
    }

    public function test_saving_a_ward_does_not_touch_its_beds_unless_asked(): void
    {
        // The whole point of the checkbox. Renaming a ward must never reprice
        // anybody's stay.
        $ward = $this->ward();
        $bed = $this->bed($ward, '30000.00');

        Livewire::test(Index::class)
            ->call('edit', $ward->id)
            ->set('name', 'Renamed ward')
            ->set('default_daily_charge', '90000.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('30000.00', $bed->fresh()->daily_charge);
    }

    // ── Applying it ──────────────────────────────────────────────────────

    public function test_ticking_the_box_sets_every_bed_in_the_ward(): void
    {
        $ward = $this->ward();
        $a = $this->bed($ward, '30000.00');
        $b = $this->bed($ward, '45000.00');

        Livewire::test(Index::class)
            ->call('edit', $ward->id)
            ->set('default_daily_charge', '60000.00')
            ->set('apply_to_beds', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('60000.00', $a->fresh()->daily_charge);
        $this->assertSame('60000.00', $b->fresh()->daily_charge);
    }

    public function test_it_leaves_another_wards_beds_alone(): void
    {
        $ward = $this->ward();
        $other = $this->ward(['name' => 'Another ward']);

        $mine = $this->bed($ward, '30000.00');
        $theirs = $this->bed($other, '30000.00');

        Livewire::test(Index::class)
            ->call('edit', $ward->id)
            ->set('default_daily_charge', '60000.00')
            ->set('apply_to_beds', true)
            ->call('save');

        $this->assertSame('60000.00', $mine->fresh()->daily_charge);
        $this->assertSame('30000.00', $theirs->fresh()->daily_charge);
    }

    public function test_it_leaves_another_hospitals_beds_alone(): void
    {
        $ward = $this->ward();
        $this->bed($ward, '30000.00');

        $otherHospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($otherHospital->id);
        $theirWard = Ward::factory()->create(['hospital_id' => $otherHospital->id]);
        $theirBed = Bed::factory()->create([
            'hospital_id' => $otherHospital->id,
            'ward_id' => $theirWard->id,
            'daily_charge' => '30000.00',
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        app(WardService::class)->applyNightlyChargeToBeds($ward, '60000.00');

        $this->assertSame('30000.00', $theirBed->fresh()->daily_charge);
    }

    public function test_the_checkbox_does_not_survive_the_save(): void
    {
        // Left on, the next innocent edit — a rename, a description — would
        // reprice the ward again without anybody choosing to.
        $ward = $this->ward();
        $this->bed($ward, '30000.00');

        Livewire::test(Index::class)
            ->call('edit', $ward->id)
            ->set('default_daily_charge', '60000.00')
            ->set('apply_to_beds', true)
            ->call('save')
            ->assertSet('apply_to_beds', false);
    }

    public function test_a_ward_with_no_beds_says_so_rather_than_claiming_success(): void
    {
        $ward = $this->ward();

        Livewire::test(Index::class)
            ->call('edit', $ward->id)
            ->set('default_daily_charge', '60000.00')
            ->set('apply_to_beds', true)
            ->call('save')
            ->assertDispatched('toast', fn ($event, $params) => str_contains($params['message'], 'no beds'));
    }

    // ── The consequence ──────────────────────────────────────────────────

    public function test_it_reports_how_many_beds_somebody_is_lying_in(): void
    {
        $ward = $this->ward();
        $this->bed($ward, '30000.00');
        $this->bed($ward, '30000.00', BedStatus::Occupied);
        $this->bed($ward, '30000.00', BedStatus::Occupied);

        $result = app(WardService::class)->applyNightlyChargeToBeds($ward, '60000.00');

        $this->assertSame(3, $result['beds']);
        $this->assertSame(2, $result['occupied']);
    }

    public function test_the_screen_warns_before_the_button_is_pressed(): void
    {
        // Afterwards is too late: the charge has already moved.
        $ward = $this->ward();
        $this->bed($ward, '30000.00', BedStatus::Occupied);

        Livewire::test(Index::class)
            ->call('edit', $ward->id)
            ->set('apply_to_beds', true)
            ->assertSee('occupied right now')
            ->assertSee('every night already spent in them will be charged at this new rate');
    }

    public function test_it_says_nothing_alarming_when_nothing_is_occupied(): void
    {
        $ward = $this->ward();
        $this->bed($ward, '30000.00');

        Livewire::test(Index::class)
            ->call('edit', $ward->id)
            ->set('apply_to_beds', true)
            ->assertDontSee('occupied right now')
            ->assertSee('Would change 1 bed');
    }

    /**
     * The behaviour the warning is about, proven rather than asserted.
     *
     * A patient admitted at the old rate and discharged after the ward was
     * repriced is billed at the NEW rate for the whole stay.
     */
    public function test_repricing_an_occupied_bed_reprices_the_whole_stay(): void
    {
        $ward = $this->ward();
        $bed = $this->bed($ward, '30000.00');
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $admission = app(AdmissionService::class)->admit($patient, $bed, [], $this->admin->id);
        $admission->forceFill(['admitted_at' => now()->subDays(4)])->saveQuietly();

        app(WardService::class)->applyNightlyChargeToBeds($ward, '60000.00');

        $discharged = app(AdmissionService::class)
            ->discharge($admission->fresh(), \App\Enums\AdmissionStatus::Discharged, null, $this->admin->id);

        // 4 nights at the NEW rate, not the old one. This is what the screen
        // warns about, and it is deliberate: "we put the rate up, bill
        // everybody the new one" is a real thing an administrator means.
        $this->assertSame('240000.00', $discharged->bed_charge_total);
    }

    public function test_a_bed_can_still_differ_from_its_ward(): void
    {
        // The ward rate is a standard, not a law: a side room costs more, and
        // setting one must not require fighting the model.
        $ward = $this->ward(['default_daily_charge' => '50000.00']);
        $side = $this->bed($ward, '120000.00');

        $this->assertSame('120000.00', $side->fresh()->daily_charge);
        $this->assertSame('50000.00', $ward->fresh()->default_daily_charge);
    }
    // ── The rate as a starting point ─────────────────────────────────────

    public function test_a_new_bed_starts_from_its_wards_standard_rate(): void
    {
        // Otherwise the ward rate is a number on a screen that means nothing.
        $ward = $this->ward(['default_daily_charge' => '80000.00']);

        Livewire::test(\App\Livewire\Beds\Index::class)
            ->call('create')
            ->set('ward_id', $ward->id)
            ->assertSet('daily_charge', '80000.00');
    }

    public function test_it_does_not_reprice_a_bed_somebody_already_priced(): void
    {
        // Choosing a ward must not overwrite a figure that was typed on
        // purpose.
        $ward = $this->ward(['default_daily_charge' => '80000.00']);

        Livewire::test(\App\Livewire\Beds\Index::class)
            ->call('create')
            ->set('daily_charge', '120000.00')
            ->set('ward_id', $ward->id)
            ->assertSet('daily_charge', '120000.00');
    }

    public function test_the_list_shows_what_a_night_in_each_ward_costs(): void
    {
        $this->ward(['name' => 'Maternity', 'default_daily_charge' => '75000.00']);

        Livewire::test(Index::class)->assertSee('75,000');
    }
    // ── Every path that makes a ward records its rate ────────────────────

    public function test_a_ward_imported_from_the_samples_carries_its_rate(): void
    {
        // A ward created with no rate reads as costing nothing, and ticking
        // the apply box on it would set every bed in it to zero.
        Livewire::test(Index::class)
            ->call('openSamples')
            ->call('importSamples');

        $general = Ward::where('name', 'General ward')->first();

        $this->assertNotNull($general, 'The sample import did not create the ward.');
        $this->assertNotSame('0.00', $general->default_daily_charge);
    }

    public function test_no_ward_anywhere_is_left_costing_nothing_by_accident(): void
    {
        // The guard behind the two paths above: if a new way of creating a
        // ward appears and forgets the rate, the wards screen will quietly
        // claim that ward is free.
        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        $this->assertSame(
            0,
            Ward::where('default_daily_charge', '0.00')->count(),
            'A ward was created without a nightly rate.',
        );
    }
}
