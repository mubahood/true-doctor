<?php

namespace Tests\Feature\Livewire;

use App\Enums\Weekday;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrgModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingAdmin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_staff_profile_modal_creates(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor', 'name' => 'Dr Who']);

        Livewire::test(\App\Livewire\StaffProfiles\Index::class)
            ->call('create')
            ->set('user_id', $doctor->id)
            ->set('specialty', 'Cardiology')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('staff_profiles', ['user_id' => $doctor->id, 'specialty' => 'Cardiology']);
    }

    public function test_staff_profile_modal_rejects_duplicate_user(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        StaffProfile::factory()->create(['hospital_id' => $h->id, 'user_id' => $doctor->id]);

        Livewire::test(\App\Livewire\StaffProfiles\Index::class)
            ->call('create')
            ->set('user_id', $doctor->id)
            ->call('save')
            ->assertHasErrors('user_id');
    }

    public function test_schedule_modal_creates_with_int_weekday_and_times(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);

        Livewire::test(\App\Livewire\Schedules\Index::class)
            ->call('create')
            ->set('user_id', $doctor->id)
            ->set('weekday', Weekday::Monday->value)
            ->set('start_time', '09:00')
            ->set('end_time', '13:00')
            ->set('slot_minutes', 20)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('doctor_schedules', [
            'user_id' => $doctor->id,
            'weekday' => Weekday::Monday->value,
            'slot_minutes' => 20,
        ]);
    }

    public function test_schedule_modal_rejects_end_before_start(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);

        Livewire::test(\App\Livewire\Schedules\Index::class)
            ->call('create')
            ->set('user_id', $doctor->id)
            ->set('weekday', Weekday::Tuesday->value)
            ->set('start_time', '14:00')
            ->set('end_time', '09:00')
            ->set('slot_minutes', 30)
            ->call('save')
            ->assertHasErrors('end_time');
    }

    public function test_financial_year_modal_creates_via_service(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(\App\Livewire\FinancialYears\Index::class)
            ->call('create')
            ->set('name', 'FY 2027')
            ->set('starts_on', '2027-01-01')
            ->set('ends_on', '2027-12-31')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertNotNull(FinancialYear::where('name', 'FY 2027')->first());
    }
}
