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
 * Adding a doctor's availability.
 *
 * Most availability is not one day. A doctor who sits every weekday morning had
 * to add five identical windows one at a time, and one who sits every day,
 * seven — the dialog asked a question nobody's answer actually fitted.
 */
class AvailabilityWindowTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $this->doctor->syncSpatieRole();

        $this->actingAs($this->admin);
    }

    private function dialog(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Schedules::class)->call('create');
    }

    private function fill(\Livewire\Features\SupportTesting\Testable $page, string $weekday): \Livewire\Features\SupportTesting\Testable
    {
        return $page
            ->call('pickedForForm', 'user_id', $this->doctor->id)
            ->set('weekday', $weekday)
            ->set('start_time', '08:00')
            ->set('end_time', '12:00')
            ->set('slot_minutes', 30);
    }

    // ── One answer, all the days it means ────────────────────────────────

    public function test_every_day_adds_a_window_for_all_seven(): void
    {
        $this->fill($this->dialog(), 'all')->call('save')->assertHasNoErrors();

        $this->assertSame(7, DoctorSchedule::where('user_id', $this->doctor->id)->count());
        $this->assertSame(
            [0, 1, 2, 3, 4, 5, 6],
            DoctorSchedule::orderBy('weekday')->pluck('weekday')->map(fn (Weekday $d) => $d->value)->all(),
        );
    }

    public function test_weekdays_adds_monday_to_friday_and_nothing_else(): void
    {
        $this->fill($this->dialog(), 'weekdays')->call('save')->assertHasNoErrors();

        $days = DoctorSchedule::orderBy('weekday')->pluck('weekday')->map(fn (Weekday $d) => $d->value)->all();

        $this->assertSame([
            Weekday::Monday->value, Weekday::Tuesday->value, Weekday::Wednesday->value,
            Weekday::Thursday->value, Weekday::Friday->value,
        ], $days);
    }

    public function test_weekend_adds_saturday_and_sunday(): void
    {
        $this->fill($this->dialog(), 'weekend')->call('save')->assertHasNoErrors();

        $this->assertSame(2, DoctorSchedule::count());
        $this->assertTrue(DoctorSchedule::where('weekday', Weekday::Saturday->value)->exists());
        $this->assertTrue(DoctorSchedule::where('weekday', Weekday::Sunday->value)->exists());
    }

    public function test_one_day_still_adds_exactly_one(): void
    {
        $this->fill($this->dialog(), (string) Weekday::Wednesday->value)->call('save')->assertHasNoErrors();

        $this->assertSame(1, DoctorSchedule::count());
        $this->assertSame(Weekday::Wednesday, DoctorSchedule::firstOrFail()->weekday);
    }

    /**
     * "Every day" on a doctor who already sits on Monday should add the other
     * six, not fail because of the one.
     */
    public function test_a_day_that_already_has_the_window_is_skipped_not_refused(): void
    {
        DoctorSchedule::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->doctor->id,
            'weekday' => Weekday::Monday->value,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        $this->fill($this->dialog(), 'all')->call('save')->assertHasNoErrors();

        $this->assertSame(7, DoctorSchedule::count(), 'the existing Monday was duplicated or the run was refused');
        $this->assertSame(1, DoctorSchedule::where('weekday', Weekday::Monday->value)->count());
    }

    public function test_a_repeating_answer_still_has_to_be_a_valid_window(): void
    {
        $this->dialog()
            ->call('pickedForForm', 'user_id', $this->doctor->id)
            ->set('weekday', 'all')
            ->set('start_time', '12:00')
            ->set('end_time', '08:00')      // before it starts
            ->set('slot_minutes', 30)
            ->call('save')
            ->assertHasErrors('end_time');

        $this->assertSame(0, DoctorSchedule::count());
    }

    /** A window IS one day, so editing one offers only the seven. */
    public function test_editing_a_window_offers_one_day_not_a_repeat(): void
    {
        $window = DoctorSchedule::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->doctor->id,
            'weekday' => Weekday::Monday->value,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        Livewire::test(Schedules::class)
            ->call('edit', $window->id)
            ->assertDontSee('Every day')
            ->assertSee('Monday');
    }

    // ── Suggestions ──────────────────────────────────────────────────────

    /** A shift is a start AND an end; one click answers both. */
    public function test_a_shift_suggestion_fills_both_ends_of_the_window(): void
    {
        $page = $this->dialog();

        $this->assertArrayHasKey('Morning · 8–12', $page->instance()->shifts());
        $this->assertSame(
            ['start_time' => '08:00', 'end_time' => '12:00'],
            $page->instance()->shifts()['Morning · 8–12'],
        );

        $page->assertSee('Usual shifts')->assertSee('Morning');
    }

    public function test_the_times_and_the_slot_length_all_offer_suggestions(): void
    {
        $html = $this->dialog()->html();

        // The pills set the field they sit under, in one round trip each.
        $this->assertStringContainsString('08:00', $html);
        $this->assertStringContainsString('17:00', $html);
        $this->assertStringContainsString('30 min', $html);
        $this->assertStringContainsString('1 hour', $html);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_a_repeating_answer_never_writes_into_another_hospital(): void
    {
        $this->fill($this->dialog(), 'all')->call('save');

        $this->assertSame(
            7,
            DoctorSchedule::withoutGlobalScopes()->where('hospital_id', $this->hospital->id)->count(),
        );
        $this->assertSame(
            0,
            DoctorSchedule::withoutGlobalScopes()->where('hospital_id', '!=', $this->hospital->id)->count(),
        );
    }
}
