<?php

namespace Tests\Feature\Livewire;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Livewire\Appointments\Queue;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The check-in queue as a board.
 *
 * It used to be a table of today's rows: a booked time, a status badge, and
 * every legal transition drawn as its own button — four to a row, including a
 * "Completed" that could no longer do anything. Somebody who arrived at 08:55
 * and had been sitting forty minutes looked exactly like somebody who had just
 * walked in, which is the one thing a queue has to be able to tell you.
 */
class CheckInQueueTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        // A Wednesday, mid-morning: a clinic with a past and a future in it.
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00'));

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);

        $this->doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Kasujja',
        ]);

        DoctorSchedule::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $this->doctor->id,
            'weekday' => Weekday::Wednesday->value, 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appointment(string $at, AppointmentStatus $status, ?string $checkedInAt = null, ?User $doctor = null): Appointment
    {
        $appointment = Appointment::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
            'doctor_user_id' => ($doctor ?? $this->doctor)->id,
            'scheduled_at' => Carbon::parse($at),
            'ends_at' => Carbon::parse($at)->addMinutes(30),
            'status' => $status,
            'checked_in_at' => $checkedInAt === null ? null : Carbon::parse($checkedInAt),
        ]);

        return $appointment;
    }

    private function board(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Queue::class);
    }

    // ── How long they have been sitting ──────────────────────────────────

    /**
     * The one thing a queue has to be able to tell you, and the one thing the
     * old board could not: a booked time is what somebody was PROMISED, not
     * what happened to them.
     */
    public function test_the_wait_is_measured_from_arrival_not_from_the_booking(): void
    {
        $early = $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 08:55');
        $justNow = $this->appointment('2026-09-16 09:30', AppointmentStatus::CheckedIn, '2026-09-16 09:58');

        $page = $this->board()->instance();

        $this->assertSame(65, $page->waitedMinutes($early));
        $this->assertSame(2, $page->waitedMinutes($justNow));
    }

    public function test_a_wait_reads_as_hours_once_it_is_one(): void
    {
        $page = $this->board()->instance();

        $this->assertSame('9m', $page->clock(9));
        $this->assertSame('59m', $page->clock(59));
        $this->assertSame('1h 00m', $page->clock(60));
        $this->assertSame('1h 05m', $page->clock(65));
    }

    public function test_a_long_wait_reads_differently_from_a_short_one(): void
    {
        $page = $this->board()->instance();

        $this->assertSame('', $page->waitTone(5));
        $this->assertSame('warn', $page->waitTone(Queue::WAIT_WARN));
        $this->assertSame('bad', $page->waitTone(Queue::WAIT_BAD));
        $this->assertSame('', $page->waitTone(null));
    }

    public function test_somebody_not_yet_arrived_is_shown_as_late(): void
    {
        $overdue = $this->appointment('2026-09-16 09:15', AppointmentStatus::Scheduled);
        $upcoming = $this->appointment('2026-09-16 11:00', AppointmentStatus::Scheduled);

        $page = $this->board()->instance();

        $this->assertSame(45, $page->lateMinutes($overdue));
        $this->assertSame(-60, $page->lateMinutes($upcoming), 'still to come reads as negative');
    }

    // ── The lanes ────────────────────────────────────────────────────────

    public function test_the_board_is_three_lanes_in_the_order_a_desk_thinks(): void
    {
        $this->appointment('2026-09-16 09:00', AppointmentStatus::InProgress, '2026-09-16 08:50');
        $this->appointment('2026-09-16 09:30', AppointmentStatus::CheckedIn, '2026-09-16 09:25');
        $this->appointment('2026-09-16 11:00', AppointmentStatus::Scheduled);

        $lanes = $this->board()->instance()->lanes();

        $this->assertSame(['seeing', 'waiting', 'expected'], array_keys($lanes));
        $this->assertCount(1, $lanes['seeing']['rows']);
        $this->assertCount(1, $lanes['waiting']['rows']);
        $this->assertCount(1, $lanes['expected']['rows']);
    }

    /** A queue is judged by whoever has been in it longest. */
    public function test_the_longest_wait_is_at_the_top(): void
    {
        $recent = $this->appointment('2026-09-16 09:30', AppointmentStatus::CheckedIn, '2026-09-16 09:50');
        $longest = $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 08:40');

        $waiting = $this->board()->instance()->lanes()['waiting']['rows'];

        $this->assertSame([$longest->id, $recent->id], $waiting->pluck('id')->all());
    }

    public function test_a_confirmed_appointment_is_still_only_expected(): void
    {
        $this->appointment('2026-09-16 11:00', AppointmentStatus::Confirmed);

        $this->assertCount(1, $this->board()->instance()->lanes()['expected']['rows']);
    }

    public function test_what_is_finished_or_not_today_is_off_the_board(): void
    {
        $this->appointment('2026-09-16 08:00', AppointmentStatus::Cancelled);
        $this->appointment('2026-09-16 08:30', AppointmentStatus::NoShow);
        $this->appointment('2026-09-17 09:00', AppointmentStatus::Scheduled);

        $this->assertCount(0, $this->board()->instance()->rows());
    }

    // ── The numbers across the counter ───────────────────────────────────

    public function test_the_tally_counts_each_lane_and_the_longest_wait(): void
    {
        $this->appointment('2026-09-16 09:00', AppointmentStatus::InProgress, '2026-09-16 08:50');
        $this->appointment('2026-09-16 09:30', AppointmentStatus::CheckedIn, '2026-09-16 08:40');
        $this->appointment('2026-09-16 09:45', AppointmentStatus::CheckedIn, '2026-09-16 09:50');
        $this->appointment('2026-09-16 11:00', AppointmentStatus::Scheduled);

        $tally = $this->board()->instance()->tally();

        $this->assertSame(1, $tally['seeing']);
        $this->assertSame(2, $tally['waiting']);
        $this->assertSame(1, $tally['expected']);
        $this->assertSame(80, $tally['longest']);
    }

    public function test_nobody_waiting_has_no_longest_wait(): void
    {
        $this->appointment('2026-09-16 11:00', AppointmentStatus::Scheduled);

        $this->assertNull($this->board()->instance()->tally()['longest']);
    }

    // ── One clinic at a time ─────────────────────────────────────────────

    public function test_the_board_can_be_pinned_to_one_doctor(): void
    {
        $other = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Namara']);

        $mine = $this->appointment('2026-09-16 09:30', AppointmentStatus::CheckedIn, '2026-09-16 09:25');
        $this->appointment('2026-09-16 09:45', AppointmentStatus::CheckedIn, '2026-09-16 09:40', $other);

        $page = $this->board()->call('picked', 'doctor', $this->doctor->id);

        $this->assertSame([$mine->id], $page->instance()->rows()->pluck('id')->all());

        $page->call('clearFilters');
        $this->assertCount(2, $page->instance()->rows());
    }

    // ── What a row offers ────────────────────────────────────────────────

    /** @return list<string> the labels on the row's own action buttons */
    private function rowButtonLabels(): array
    {
        preg_match_all(
            '/<button\b[^>]*class="[^"]*tb-nextbtn[^"]*"[^>]*>(.*?)<\/button>/s',
            $this->board()->html(),
            $matches,
        );

        return array_map(
            fn (string $label) => trim(strip_tags(preg_replace('/<!--.*?-->/s', '', $label) ?? '')),
            $matches[1],
        );
    }

    public function test_somebody_not_yet_arrived_is_offered_check_in(): void
    {
        $this->appointment('2026-09-16 09:15', AppointmentStatus::Scheduled);

        $this->assertSame(['Check in'], $this->rowButtonLabels());
    }

    /**
     * NOT four status buttons, and never a bare "Completed": finishing an
     * appointment means recording what was done at it.
     */
    public function test_somebody_who_has_arrived_is_offered_the_report(): void
    {
        $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 08:55');

        $this->assertSame(['Record outcome'], $this->rowButtonLabels());
    }

    public function test_no_row_button_is_empty(): void
    {
        $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 08:55');
        $this->appointment('2026-09-16 11:00', AppointmentStatus::Scheduled);

        $labels = $this->rowButtonLabels();

        $this->assertCount(2, $labels);
        foreach ($labels as $label) {
            $this->assertNotSame('', $label);
        }
    }

    // ── The same dialogs as the diary ────────────────────────────────────

    public function test_checking_somebody_in_from_the_board_works(): void
    {
        $appointment = $this->appointment('2026-09-16 09:15', AppointmentStatus::Scheduled);

        $this->board()->call('advance', $appointment->id, 'checked_in');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    /** Ending one asks why here too, because it is the same trait. */
    public function test_a_no_show_from_the_board_asks_why(): void
    {
        $appointment = $this->appointment('2026-09-16 09:15', AppointmentStatus::Scheduled);

        $this->board()->call('advance', $appointment->id, 'no_show')
            ->assertSet('showEnding', true)
            ->assertSet('endingTo', 'no_show');

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status, 'not yet');
    }

    public function test_the_reason_is_recorded_from_the_board(): void
    {
        $appointment = $this->appointment('2026-09-16 09:15', AppointmentStatus::Scheduled);

        $this->board()->call('advance', $appointment->id, 'no_show')
            ->set('note', 'Did not attend')
            ->call('endAppointment');

        $this->assertSame(AppointmentStatus::NoShow, $appointment->fresh()->status);
    }

    /** And the whole outcome dialog is here, not a link to somewhere else. */
    public function test_the_outcome_can_be_recorded_from_the_board(): void
    {
        $service = Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Consultation', 'price' => '30000.00',
        ]);
        $appointment = $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 08:55');

        $this->board()
            ->call('openOutcome', $appointment->id)
            ->assertSet('showOutcome', true)
            ->call('picked', 'provided_service_id', $service->id)
            ->set('report', 'Seen and advised.')
            ->call('saveOutcome')
            ->assertHasNoErrors()
            ->assertSet('showOutcome', false);

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->assertSame('Seen and advised.', Order::firstOrFail()->report);
    }

    /** Once written up, they leave the board. */
    public function test_recording_the_outcome_takes_them_off_the_queue(): void
    {
        $appointment = $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 08:55');

        $page = $this->board()
            ->call('openOutcome', $appointment->id)
            ->set('report', 'Seen and advised.')
            ->call('saveOutcome');

        $this->assertCount(0, $page->instance()->rows());
        $this->assertSame(1, $page->instance()->tally()['seen']);
    }

    /** One vocabulary, so the diary and the board cannot come to disagree. */
    public function test_the_board_and_the_diary_share_one_set_of_actions(): void
    {
        foreach ([Queue::class, \App\Livewire\Appointments\Index::class] as $component) {
            $this->assertContains(
                \App\Livewire\Appointments\Concerns\ActsOnAppointments::class,
                class_uses_recursive($component),
                $component.' has its own idea of what can be done to an appointment',
            );
        }
    }
}
