<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Appointments\Index;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppointmentsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingReceptionist(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function makeAppointment(Hospital $h, string $date, array $overrides = []): Appointment
    {
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        return Appointment::factory()->create(array_merge([
            'hospital_id' => $h->id,
            'patient_id' => $patient->id,
            'scheduled_at' => $date.' 09:00:00',
            'ends_at' => $date.' 09:30:00',
        ], $overrides));
    }

    public function test_it_lists_appointments_for_the_selected_date(): void
    {
        $h = Hospital::factory()->create();
        $today = now()->format('Y-m-d');
        $this->makeAppointment($h, $today);
        $this->actingReceptionist($h);

        // The date starts EMPTY and the diary shows everything. Defaulting it
        // to today hid a booking made for next week the moment it was saved.
        Livewire::test(Index::class)
            ->assertOk()
            ->assertSet('date', '')
            ->set('date', $today)
            ->assertSet('date', $today);
    }

    public function test_date_filter_scopes_the_diary(): void
    {
        $h = Hospital::factory()->create();
        $p1 = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Todayone']);
        $p2 = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Tomorrowone']);
        Appointment::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p1->id, 'scheduled_at' => now()->format('Y-m-d').' 09:00', 'ends_at' => now()->format('Y-m-d').' 09:30']);
        Appointment::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p2->id, 'scheduled_at' => now()->addDay()->format('Y-m-d').' 09:00', 'ends_at' => now()->addDay()->format('Y-m-d').' 09:30']);
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->set('date', now()->format('Y-m-d'))
            ->assertSee('Todayone')
            ->assertDontSee('Tomorrowone');
    }

    public function test_a_role_without_appointment_view_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Index::class)->assertForbidden();
    }

    public function test_modal_books_an_appointment_via_service(): void
    {
        $h = Hospital::factory()->create();
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        // Booking runs through AppointmentService, which requires an active
        // availability window covering (and slot-aligned to) the requested time.
        $this->actingReceptionist($h);
        $slot = now()->addWeek()->startOfWeek()->setTime(9, 0); // a Monday 09:00
        \App\Models\DoctorSchedule::create([
            'user_id' => $doctor->id,
            'weekday' => $slot->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        Livewire::test(Index::class)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('patient_id', $patient->id)
            ->set('doctor_user_id', $doctor->id)
            ->set('source', \App\Enums\AppointmentSource::WalkIn->value)
            ->set('scheduled_at', $slot->format('Y-m-d\TH:i'))
            ->set('duration_minutes', 30)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
        ]);
    }

    public function test_the_select_search_child_sets_only_whitelisted_fields(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->call('create')
            ->dispatch('select-search:picked', name: 'patient_id', id: $patient->id)
            ->assertSet('patient_id', $patient->id)
            // An unknown field name is ignored — the child can only fill the picker slots.
            ->dispatch('select-search:picked', name: 'perPage', id: 999)
            ->assertSet('perPage', 20)
            ->dispatch('select-search:cleared', name: 'patient_id')
            ->assertSet('patient_id', null);
    }

    public function test_modal_validates_required_booking_fields(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->call('create')
            ->call('save')
            ->assertHasErrors(['patient_id', 'doctor_user_id', 'scheduled_at', 'source']);
    }
}
