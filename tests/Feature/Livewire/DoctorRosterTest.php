<?php

namespace Tests\Feature\Livewire;

use App\Enums\Weekday;
use App\Livewire\Schedules\Index as Schedules;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Doctor availability, read as a week.
 *
 * The page used to be a flat list of windows: one doctor sitting every day was
 * seven near-identical rows, and three questions that decide whether anything
 * can be booked at all had no answer anywhere on it —
 *
 *   - which doctors have NO availability (so cannot be booked),
 *   - which days nobody covers,
 *   - which windows overlap, which makes booking fail against whichever the
 *     scheduler happens to find first.
 */
class DoctorRosterTest extends TestCase
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

        $this->admin = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'hospital_admin',
        ]);
        $this->admin->syncSpatieRole();
        $this->actingAs($this->admin);
    }

    private function doctor(string $name): User
    {
        return User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => $name,
        ]);
    }

    private function window(User $doctor, Weekday $day, string $from, string $to, int $slot = 30, bool $active = true): DoctorSchedule
    {
        return DoctorSchedule::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $doctor->id,
            'weekday' => $day->value,
            'start_time' => $from,
            'end_time' => $to,
            'slot_minutes' => $slot,
            'is_active' => $active,
        ]);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Schedules::class);
    }

    // ── The week adds up ─────────────────────────────────────────────────

    public function test_the_headline_counts_the_doctors_who_can_actually_be_booked(): void
    {
        $sits = $this->doctor('Dr Sits');
        $this->doctor('Dr Nowhere');
        $this->window($sits, Weekday::Monday, '08:00', '12:00');

        $facts = $this->page()->instance()->facts();

        $this->assertSame(2, $facts['doctors']);
        $this->assertSame(1, $facts['rostered']);
        $this->assertSame(1, $facts['unrostered'], 'a doctor with no availability cannot be booked and must be counted');
    }

    /** Four hours at half-hour slots is eight appointments, not four. */
    public function test_hours_and_slots_are_what_the_roster_can_hold(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00', 30);
        $this->window($doc, Weekday::Tuesday, '14:00', '17:00', 15);

        $facts = $this->page()->instance()->facts();

        $this->assertSame(7.0, $facts['hours']);
        $this->assertSame(8 + 12, $facts['slots']);
    }

    /** A window that is switched off holds nothing and is not clinic time. */
    public function test_a_window_that_is_off_counts_towards_nothing(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00', 30, active: false);

        $facts = $this->page()->instance()->facts();

        $this->assertSame(0.0, $facts['hours']);
        $this->assertSame(0, $facts['slots']);
        $this->assertSame(1, $facts['off']);
        $this->assertSame(1, $facts['rostered'], 'the doctor is still on the roster, just not bookable this week');
    }

    // ── Which days nobody covers ─────────────────────────────────────────

    public function test_coverage_counts_the_doctors_available_each_day(): void
    {
        $a = $this->doctor('Dr A');
        $b = $this->doctor('Dr B');
        $this->window($a, Weekday::Monday, '08:00', '12:00');
        $this->window($a, Weekday::Monday, '14:00', '17:00');
        $this->window($b, Weekday::Monday, '08:00', '12:00');

        $coverage = $this->page()->instance()->facts()['coverage'];

        $this->assertSame(2, $coverage[Weekday::Monday->value], 'two doctors, not three windows');
        $this->assertSame(0, $coverage[Weekday::Sunday->value]);
    }

    // ── Overlaps ─────────────────────────────────────────────────────────

    /**
     * Booking walks a doctor's windows and takes the first that contains the
     * requested time, so an overlapping pair makes the other one's slot length
     * silently irrelevant.
     */
    public function test_two_windows_that_overlap_on_one_day_are_both_flagged(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $early = $this->window($doc, Weekday::Monday, '08:00', '12:00', 30);
        $late = $this->window($doc, Weekday::Monday, '11:00', '15:00', 15);

        $conflicts = $this->page()->instance()->facts()['conflicts'];

        $this->assertArrayHasKey($early->id, $conflicts);
        $this->assertArrayHasKey($late->id, $conflicts);
    }

    public function test_windows_that_only_touch_are_not_an_overlap(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00');
        $this->window($doc, Weekday::Monday, '12:00', '17:00');

        $this->assertSame([], $this->page()->instance()->facts()['conflicts']);
    }

    public function test_the_same_hours_on_different_days_are_not_an_overlap(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00');
        $this->window($doc, Weekday::Tuesday, '08:00', '12:00');

        $this->assertSame([], $this->page()->instance()->facts()['conflicts']);
    }

    public function test_two_doctors_in_the_same_hours_are_not_an_overlap(): void
    {
        $this->window($this->doctor('Dr A'), Weekday::Monday, '08:00', '12:00');
        $this->window($this->doctor('Dr B'), Weekday::Monday, '08:00', '12:00');

        $this->assertSame([], $this->page()->instance()->facts()['conflicts']);
    }

    /** A window wholly inside another still overlaps it. */
    public function test_a_window_swallowed_by_a_longer_one_is_flagged(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $all = $this->window($doc, Weekday::Monday, '08:00', '17:00');
        $inner = $this->window($doc, Weekday::Monday, '10:00', '11:00');

        $conflicts = $this->page()->instance()->facts()['conflicts'];

        $this->assertArrayHasKey($all->id, $conflicts);
        $this->assertArrayHasKey($inner->id, $conflicts);
    }

    /**
     * A long window followed by two short ones: the long one must stay the
     * yardstick, or the third window is compared against the second and the
     * overlap with the first goes unreported.
     */
    public function test_a_third_window_is_measured_against_the_longest_so_far(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $all = $this->window($doc, Weekday::Monday, '08:00', '18:00');
        $this->window($doc, Weekday::Monday, '09:00', '10:00');
        $third = $this->window($doc, Weekday::Monday, '11:00', '12:00');

        $conflicts = $this->page()->instance()->facts()['conflicts'];

        $this->assertArrayHasKey($all->id, $conflicts);
        $this->assertArrayHasKey($third->id, $conflicts, 'measured against the wrong window');
    }

    // ── The roster reads as a roster ─────────────────────────────────────

    public function test_the_roster_shows_a_doctor_who_has_no_windows_at_all(): void
    {
        $this->doctor('Dr Nowhere');

        $this->page()->assertSee('Dr Nowhere')->assertSee('not bookable');
    }

    public function test_a_doctors_windows_are_filed_under_the_day_they_sit_on(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00');
        $this->window($doc, Weekday::Monday, '14:00', '17:00');
        $this->window($doc, Weekday::Friday, '09:00', '11:00');

        $week = $this->page()->instance()->weekOf($doc->fresh()->load('schedules'));

        $this->assertCount(2, $week[Weekday::Monday->value]);
        $this->assertCount(1, $week[Weekday::Friday->value]);
        $this->assertArrayNotHasKey(Weekday::Sunday->value, $week);
    }

    public function test_the_weekly_hours_shown_beside_a_doctor_ignore_switched_off_windows(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00');
        $this->window($doc, Weekday::Tuesday, '08:00', '12:00', 30, active: false);

        $this->assertSame(4.0, $this->page()->instance()->hoursOf($doc->fresh()->load('schedules')));
    }

    // ── Filters ──────────────────────────────────────────────────────────

    public function test_only_the_chosen_day_is_shown(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00');
        $this->window($doc, Weekday::Friday, '15:00', '18:00');

        $this->page()
            ->set('day', (string) Weekday::Monday->value)
            ->assertSee('08:00')
            ->assertDontSee('15:00');
    }

    public function test_switched_off_windows_can_be_singled_out(): void
    {
        $doc = $this->doctor('Dr Kasujja');
        $this->window($doc, Weekday::Monday, '08:00', '12:00');
        $this->window($doc, Weekday::Tuesday, '15:00', '18:00', 30, active: false);

        $this->page()->set('state', 'off')->assertSee('15:00')->assertDontSee('08:00');
    }

    public function test_the_unrostered_filter_shows_only_doctors_with_nothing_set(): void
    {
        $sits = $this->doctor('Dr Sits');
        $this->doctor('Dr Nowhere');
        $this->window($sits, Weekday::Monday, '08:00', '12:00');

        $this->page()->set('unrostered', true)
            ->assertSee('Dr Nowhere')
            ->assertDontSee('Dr Sits');
    }

    /** One window per row cannot answer "who has none", so the view follows. */
    public function test_asking_for_the_unrostered_moves_to_the_roster(): void
    {
        $this->page()->set('view', 'list')->set('unrostered', true)->assertSet('view', 'roster');
    }

    public function test_clearing_puts_every_filter_back(): void
    {
        $this->page()
            ->set('search', 'kasujja')->set('day', '1')->set('state', 'off')->set('unrostered', true)
            ->call('clearFilters')
            ->assertSet('search', '')->assertSet('day', '')->assertSet('state', '')->assertSet('unrostered', false);
    }

    /** A hand-typed URL is not a licence to order by any column. */
    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->page()->call('sortBy', 'hospital_id')->assertSet('sortField', '');
        $this->page()->call('sortBy', 'slot_minutes')->assertSet('sortField', 'slot_minutes');
    }

    public function test_a_nonsense_view_falls_back_to_the_roster(): void
    {
        $this->page()->set('view', 'wall-chart')->assertSet('view', 'roster');
    }

    // ── Editing from the roster ──────────────────────────────────────────

    /** The empty cell already says which doctor and which day. */
    public function test_adding_from_a_cell_arrives_with_the_doctor_and_day_filled_in(): void
    {
        $doc = $this->doctor('Dr Kasujja');

        $this->page()
            ->call('addFor', $doc->id, Weekday::Thursday->value)
            ->assertSet('showForm', true)
            ->assertSet('user_id', $doc->id)
            ->assertSet('weekday', Weekday::Thursday->value);
    }

    public function test_a_cell_cannot_smuggle_in_another_hospitals_doctor(): void
    {
        $other = Hospital::factory()->create();
        $theirs = User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor']);

        $this->page()
            ->call('addFor', $theirs->id, Weekday::Monday->value)
            ->assertSet('user_id', null);
    }

    public function test_the_status_badge_switches_a_window_on_and_off(): void
    {
        $window = $this->window($this->doctor('Dr Kasujja'), Weekday::Monday, '08:00', '12:00');

        $this->page()->call('toggleActive', $window->id);
        $this->assertFalse($window->fresh()->is_active);

        $this->page()->call('toggleActive', $window->id);
        $this->assertTrue($window->fresh()->is_active);
    }

    public function test_a_window_in_another_hospital_cannot_be_switched(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirDoctor = User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor']);
        $theirs = DoctorSchedule::create([
            'hospital_id' => $other->id, 'user_id' => $theirDoctor->id,
            'weekday' => Weekday::Monday->value, 'start_time' => '08:00', 'end_time' => '12:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->page()->call('toggleActive', $theirs->id);
    }

    /** Nothing about the roster may reach across a tenant boundary. */
    public function test_the_week_is_this_hospitals_week(): void
    {
        $ours = $this->doctor('Dr Ours');
        $this->window($ours, Weekday::Monday, '08:00', '12:00');

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor', 'name' => 'Dr Theirs']);
        DoctorSchedule::create([
            'hospital_id' => $other->id, 'user_id' => $theirs->id,
            'weekday' => Weekday::Friday->value, 'start_time' => '08:00', 'end_time' => '18:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $facts = $this->page()->instance()->facts();

        $this->assertSame(1, $facts['doctors']);
        $this->assertSame(4.0, $facts['hours']);
        $this->assertSame(0, $facts['coverage'][Weekday::Friday->value]);
        $this->page()->assertDontSee('Dr Theirs');
    }
}
