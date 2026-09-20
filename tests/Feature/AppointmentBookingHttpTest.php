<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Livewire\Appointments\Index as AppointmentsIndex;
use App\Livewire\Appointments\Show as AppointmentShow;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Booking happens in the Livewire slide-over (Phase 2) and the lifecycle
 * transition is a Livewire action on App\Livewire\Appointments\Show (Phase 3).
 * Same assertions as the classic create/store/transition flow they replaced.
 */
class AppointmentBookingHttpTest extends TestCase
{
    use RefreshDatabase;

    /** Next Monday 09:00 — computed so the fixture never falls behind `after_or_equal:today`. */
    private string $mon0900;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->mon0900 = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0)->format('Y-m-d H:i');
        $this->assertSame(Weekday::Monday->value, Carbon::parse($this->mon0900)->dayOfWeek);
    }

    private function receptionist(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncSpatieRole();

        return $u;
    }

    private function seedDoctorWindow(Hospital $h): User
    {
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        DoctorSchedule::factory()->create([
            'hospital_id' => $h->id, 'user_id' => $doctor->id,
            'weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 30,
        ]);

        return $doctor;
    }

    /** Drive the booking slide-over the way a receptionist would. */
    private function book(Hospital $h, User $user, Patient $patient, User $doctor, string $source = 'walk_in'): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($user);
        app(CurrentHospital::class)->set($h->id);

        return Livewire::test(AppointmentsIndex::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->set('doctor_user_id', $doctor->id)
            ->set('scheduled_at', $this->mon0900)
            ->set('duration_minutes', 30)
            ->set('source', $source)
            ->call('save');
    }

    public function test_receptionist_books_and_then_advances_status(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->receptionist($h);
        $doctor = $this->seedDoctorWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->book($h, $user, $patient, $doctor, 'phone')
            // The dialog closes onto the list rather than navigating away.
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('showForm', false);

        $appt = Appointment::firstOrFail();
        $this->assertSame(AppointmentStatus::Scheduled, $appt->status);

        // The transition is a Livewire action on the detail page (Phase 3).
        Livewire::actingAs($user)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->call('transition', 'checked_in')
            ->assertDispatched('toast', type: 'success');
        $this->assertSame(AppointmentStatus::CheckedIn, $appt->fresh()->status);
        $this->assertNotNull($appt->fresh()->checked_in_at);
    }

    public function test_double_booking_is_rejected_with_an_error_toast(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->receptionist($h);
        $doctor = $this->seedDoctorWindow($h);
        $p1 = Patient::factory()->create(['hospital_id' => $h->id]);
        $p2 = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->book($h, $user, $p1, $doctor)->assertHasNoErrors();

        $this->book($h, $user, $p2, $doctor)
            ->assertNoRedirect()
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(1, Appointment::count());
    }

    public function test_illegal_transition_is_rejected_with_an_error_toast(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->receptionist($h);
        $doctor = $this->seedDoctorWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $this->book($h, $user, $patient, $doctor);
        $appt = Appointment::firstOrFail();

        Livewire::actingAs($user)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->call('transition', 'completed')
            ->assertDispatched('toast', type: 'error');
        $this->assertSame(AppointmentStatus::Scheduled, $appt->fresh()->status);
    }

    /** Cancelling carries a reason onto the appointment and its history entry. */
    public function test_cancelling_records_the_reason(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->receptionist($h);
        $doctor = $this->seedDoctorWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $this->book($h, $user, $patient, $doctor);
        $appt = Appointment::firstOrFail();

        Livewire::actingAs($user)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->set('note', 'Patient called to cancel')
            ->call('transition', 'cancelled')
            ->assertDispatched('toast', type: 'success');

        $appt->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appt->status);
        $this->assertSame('Patient called to cancel', $appt->cancel_reason);
        $this->assertSame('Patient called to cancel', $appt->history()->first()?->note);
    }

    /** A nurse may read the detail page but cannot move the appointment. */
    public function test_nurse_cannot_transition_an_appointment(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->receptionist($h);
        $doctor = $this->seedDoctorWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $this->book($h, $user, $patient, $doctor);
        $appt = Appointment::firstOrFail();

        $nurse = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        Livewire::actingAs($nurse)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->assertOk()
            ->call('transition', 'checked_in')
            ->assertForbidden();
        $this->assertSame(AppointmentStatus::Scheduled, $appt->fresh()->status);
    }

    public function test_nurse_cannot_book_but_can_view(): void
    {
        $h = Hospital::factory()->create();
        $nurse = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        $this->actingAs($nurse)->get('/admin/appointments')->assertOk();

        app(CurrentHospital::class)->set($h->id);
        Livewire::test(AppointmentsIndex::class)->call('create')->assertForbidden();
    }
}
