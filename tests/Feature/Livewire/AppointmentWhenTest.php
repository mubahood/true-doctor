<?php

namespace Tests\Feature\Livewire;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Livewire\Appointments\Index as Appointments;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Booking an appointment: which day, then which of that day's free times.
 *
 * The dialog used to ask for "18/09/2026, 20:46" in a single datetime box —
 * three chances to get it wrong, with no indication that the doctor does not
 * work that day or that the hour was already taken. You found out on save,
 * from an error naming a slot length that appears nowhere on screen.
 *
 * The times offered now are generated exactly the way
 * AppointmentService::assertBookable checks them, so the contract this file
 * exists to protect is: A TIME THAT IS OFFERED IS A TIME THAT BOOKS.
 */
class AppointmentWhenTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        // A Wednesday, so "today" is never a weekend edge case, and mid-morning
        // so there is always a past and a future part to the working day.
        Carbon::setTestNow(Carbon::parse('2026-09-16 09:10:00'));

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Kasujja']);
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sits(Weekday $day, string $from = '08:00', string $to = '12:00', int $slot = 30): DoctorSchedule
    {
        return DoctorSchedule::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->doctor->id,
            'weekday' => $day->value,
            'start_time' => $from,
            'end_time' => $to,
            'slot_minutes' => $slot,
            'is_active' => true,
        ]);
    }

    private function dialog(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Appointments::class)
            ->call('create')
            ->call('picked', 'doctor_user_id', $this->doctor->id);
    }

    /** Thursday: tomorrow, so nothing is in the past. */
    private const TOMORROW = '2026-09-17';

    // ── Which day ────────────────────────────────────────────────────────

    public function test_the_first_two_days_are_named_rather_than_dated(): void
    {
        $days = $this->dialog()->instance()->dayChoices();

        $this->assertSame('Today', $days[0]['label']);
        $this->assertSame('Tomorrow', $days[1]['label']);
        $this->assertSame('2026-09-16', $days[0]['date']);
        $this->assertCount(14, $days, 'a fortnight is what a follow-up is booked inside');
    }

    /** "Come back Thursday" should not offer a day the doctor never sits. */
    public function test_a_day_the_doctor_does_not_sit_is_marked(): void
    {
        $this->sits(Weekday::Thursday);

        $days = collect($this->dialog()->instance()->dayChoices())->keyBy('date');

        $this->assertTrue($days[self::TOMORROW]['sits'], 'Thursday');
        $this->assertFalse($days['2026-09-18']['sits'], 'Friday');
    }

    /** With no doctor chosen there is nothing to be away from. */
    public function test_with_no_doctor_every_day_is_offered_plainly(): void
    {
        $days = Livewire::test(Appointments::class)->call('create')->instance()->dayChoices();

        $this->assertSame([true], array_values(array_unique(array_column($days, 'sits'))));
    }

    // ── Which time ───────────────────────────────────────────────────────

    public function test_the_times_offered_are_the_windows_own_grid(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '10:00', 30);

        $page = $this->dialog()->call('chooseDay', self::TOMORROW);

        $this->assertSame(['08:00', '08:30', '09:00', '09:30'], $page->instance()->openSlots());
    }

    /** A 30-minute grid with a 60-minute appointment: the last start drops off. */
    public function test_the_appointment_has_to_finish_inside_the_window(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '10:00', 30);

        $page = $this->dialog()->set('duration_minutes', 60)->call('chooseDay', self::TOMORROW);

        $this->assertSame(['08:00', '08:30', '09:00'], $page->instance()->openSlots());
    }

    public function test_two_windows_in_a_day_are_both_offered_and_the_gap_is_not(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '09:00', 30);
        $this->sits(Weekday::Thursday, '14:00', '15:00', 30);

        $page = $this->dialog()->call('chooseDay', self::TOMORROW);

        $this->assertSame(['08:00', '08:30', '14:00', '14:30'], $page->instance()->openSlots());
    }

    public function test_a_window_that_is_switched_off_offers_nothing(): void
    {
        $window = $this->sits(Weekday::Thursday);
        $window->update(['is_active' => false]);

        $this->assertSame([], $this->dialog()->call('chooseDay', self::TOMORROW)->instance()->openSlots());
    }

    // ── What is already booked ───────────────────────────────────────────

    public function test_a_time_somebody_else_has_is_not_offered(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '10:00', 30);
        $this->book('2026-09-17 08:30', 30);

        $this->assertSame(
            ['08:00', '09:00', '09:30'],
            $this->dialog()->call('chooseDay', self::TOMORROW)->instance()->openSlots(),
        );
    }

    /** An hour-long booking blocks both half-hours it covers, not just its start. */
    public function test_a_longer_booking_blocks_every_slot_it_covers(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '11:00', 30);
        $this->book('2026-09-17 09:00', 60);

        $this->assertSame(
            ['08:00', '08:30', '10:00', '10:30'],
            $this->dialog()->call('chooseDay', self::TOMORROW)->instance()->openSlots(),
        );
    }

    /** A cancelled appointment frees its slot, exactly as booking treats it. */
    public function test_a_cancelled_booking_gives_its_time_back(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '09:00', 30);
        $appointment = $this->book('2026-09-17 08:00', 30);
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $this->assertSame(
            ['08:00', '08:30'],
            $this->dialog()->call('chooseDay', self::TOMORROW)->instance()->openSlots(),
        );
    }

    public function test_another_doctors_bookings_do_not_block_this_one(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '09:00', 30);

        $other = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        DoctorSchedule::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $other->id,
            'weekday' => Weekday::Thursday->value, 'start_time' => '08:00', 'end_time' => '09:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        app(AppointmentService::class)->book([
            'patient_id' => $this->patient->id, 'doctor_user_id' => $other->id,
            'scheduled_at' => '2026-09-17 08:00', 'duration_minutes' => 30, 'source' => 'walk_in',
        ], auth()->id());

        $this->assertSame(
            ['08:00', '08:30'],
            $this->dialog()->call('chooseDay', self::TOMORROW)->instance()->openSlots(),
        );
    }

    // ── Today ────────────────────────────────────────────────────────────

    /** It is 09:10; 08:00 and 09:00 have been and gone. */
    public function test_times_that_have_already_passed_today_are_not_offered(): void
    {
        $this->sits(Weekday::Wednesday, '08:00', '11:00', 30);

        $page = $this->dialog()->call('chooseDay', '2026-09-16');

        $this->assertSame(['09:30', '10:00', '10:30'], $page->instance()->openSlots());
    }

    // ── The two questions narrow each other ──────────────────────────────

    /** Choosing a day should not then require choosing a time as well. */
    public function test_choosing_a_day_lands_on_the_first_free_time(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '12:00', 30);

        $this->dialog()->call('chooseDay', self::TOMORROW)
            ->assertSet('scheduled_at', self::TOMORROW.'T08:00');
    }

    public function test_a_time_that_does_not_survive_the_new_day_is_replaced(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '12:00', 30);
        $this->sits(Weekday::Friday, '14:00', '17:00', 30);

        $page = $this->dialog()->call('chooseDay', self::TOMORROW)->call('chooseTime', '09:00');
        $this->assertSame(self::TOMORROW.'T09:00', $page->get('scheduled_at'));

        $page->call('chooseDay', '2026-09-18');
        $this->assertSame('2026-09-18T14:00', $page->get('scheduled_at'), '09:00 is not a Friday time here');
    }

    public function test_a_time_that_survives_the_new_day_is_kept(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '12:00', 30);
        $this->sits(Weekday::Friday, '08:00', '12:00', 30);

        $page = $this->dialog()->call('chooseDay', self::TOMORROW)->call('chooseTime', '10:30');
        $page->call('chooseDay', '2026-09-18');

        $this->assertSame('2026-09-18T10:30', $page->get('scheduled_at'));
    }

    /** Making the appointment longer can take the chosen time away. */
    public function test_the_length_decides_which_times_fit(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '09:00', 30);

        $page = $this->dialog()->call('chooseDay', self::TOMORROW);
        $this->assertSame(['08:00', '08:30'], $page->instance()->openSlots());

        $page->set('duration_minutes', 60);
        $this->assertSame(['08:00'], $page->instance()->openSlots());
    }

    // ── Nothing to offer, and why ────────────────────────────────────────

    public function test_with_no_doctor_the_note_asks_for_one(): void
    {
        $page = Livewire::test(Appointments::class)->call('create');

        $this->assertStringContainsString('Choose a doctor', (string) $page->instance()->slotsNote());
    }

    public function test_a_day_the_doctor_never_sits_says_so_by_name(): void
    {
        $this->sits(Weekday::Thursday);

        $note = (string) $this->dialog()->call('chooseDay', '2026-09-18')->instance()->slotsNote();

        $this->assertStringContainsString('Dr Kasujja', $note);
        $this->assertStringContainsString('Friday', $note);
    }

    public function test_a_day_that_is_full_says_that_instead(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '09:00', 30);
        $this->book('2026-09-17 08:00', 60);

        $note = (string) $this->dialog()->call('chooseDay', self::TOMORROW)->instance()->slotsNote();

        $this->assertStringContainsString('fully booked', $note);
    }

    public function test_nothing_is_offered_when_the_note_is_showing(): void
    {
        $this->assertSame([], $this->dialog()->instance()->openSlots());
        $this->assertNotNull($this->dialog()->instance()->slotsNote());
    }

    // ── Rubbish in ───────────────────────────────────────────────────────

    public function test_a_day_that_is_not_a_date_is_ignored(): void
    {
        $page = $this->dialog()->call('chooseDay', 'next thursday');

        $this->assertNull($page->get('scheduled_at'));
    }

    public function test_a_time_that_is_not_a_time_is_ignored(): void
    {
        $this->sits(Weekday::Thursday);

        $page = $this->dialog()->call('chooseDay', self::TOMORROW)->call('chooseTime', '25:99');

        $this->assertSame(self::TOMORROW.'T08:00', $page->get('scheduled_at'));
    }

    public function test_a_time_with_no_day_behind_it_is_ignored(): void
    {
        $page = $this->dialog()->call('chooseTime', '09:00');

        $this->assertNull($page->get('scheduled_at'));
    }

    // ── The contract ─────────────────────────────────────────────────────

    /**
     * Every time the dialog offers must book. If this ever fails, the dialog
     * and AppointmentService have drifted apart and the page is promising
     * something the service will refuse.
     */
    public function test_every_offered_time_actually_books(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '11:00', 20);
        $this->book('2026-09-17 09:00', 20);

        $offered = $this->dialog()->call('chooseDay', self::TOMORROW)->instance()->openSlots();

        $this->assertNotEmpty($offered);

        // One at a time, each against the state the dialog was looking at:
        // two offered times can overlap EACH OTHER on a grid finer than the
        // appointment, and taking one is what removes the rest.
        foreach ($offered as $time) {
            $booked = app(AppointmentService::class)->book([
                'patient_id' => $this->patient->id,
                'doctor_user_id' => $this->doctor->id,
                'scheduled_at' => self::TOMORROW.' '.$time,
                'duration_minutes' => 30,
                'source' => 'walk_in',
            ], auth()->id());

            $this->assertNotNull($booked->id, $time.' was offered but would not book');
            $booked->forceDelete();
        }
    }

    /** …and booking through the dialog itself still works end to end. */
    public function test_booking_from_the_offered_times_saves(): void
    {
        $this->sits(Weekday::Thursday, '08:00', '12:00', 30);

        $this->dialog()
            ->call('picked', 'patient_id', $this->patient->id)
            ->set('source', 'walk_in')
            ->call('chooseDay', self::TOMORROW)
            ->call('chooseTime', '10:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertSame(
            '2026-09-17 10:00:00',
            Appointment::firstOrFail()->scheduled_at->format('Y-m-d H:i:s'),
        );
    }

    // ── Layout ───────────────────────────────────────────────────────────

    /**
     * The visit and the patient share a row.
     *
     * They are one question — which attendance this follows, and therefore who
     * it is for — and picking the visit fills the patient beside it. Stacked,
     * they pushed everything that matters below the fold.
     */
    public function test_the_visit_and_the_patient_sit_on_one_line(): void
    {
        $html = Livewire::test(Appointments::class)->call('create')->html();

        $first = strpos($html, 'tb-form-grid');
        $this->assertIsInt($first);

        $second = strpos($html, 'tb-form-grid', $first + 1);
        $row = substr($html, $first, ($second === false ? strlen($html) : $second) - $first);

        $this->assertStringContainsString('Following up on a visit', $row);
        $this->assertStringContainsString('Patient', $row);
    }

    /** …and still when the visit has been chosen and the patient is read-only. */
    public function test_they_stay_on_one_line_once_a_visit_is_chosen(): void
    {
        $visit = app(\App\Services\VisitService::class)->open(['patient_id' => $this->patient->id]);

        $html = Livewire::test(Appointments::class)
            ->call('create')
            ->call('picked', 'origin_visit_id', $visit->id)
            ->html();

        $first = strpos($html, 'tb-form-grid');
        $second = strpos($html, 'tb-form-grid', (int) $first + 1);
        $row = substr($html, (int) $first, ($second === false ? strlen($html) : $second) - (int) $first);

        $this->assertStringContainsString('Following up on a visit', $row);
        $this->assertStringContainsString('Patient', $row);
        $this->assertStringContainsString($visit->visit_no, $row, 'the patient is shown as taken from the visit');
    }

    private function book(string $at, int $minutes): Appointment
    {
        return app(AppointmentService::class)->book([
            'patient_id' => $this->patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => $at,
            'duration_minutes' => $minutes,
            'source' => 'walk_in',
        ], auth()->id());
    }
}
