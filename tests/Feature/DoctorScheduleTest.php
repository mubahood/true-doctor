<?php

namespace Tests\Feature;

use App\Enums\Weekday;
use App\Livewire\Schedules\Index;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Doctor availability through its only surface: the Livewire index +
 * slide-over (the classic controller/create/edit path was removed).
 */
class DoctorScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actAs(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_admin_adds_an_availability_window(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'hospital_admin');
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);

        Livewire::test(Index::class)->call('create')
            ->set('user_id', $doctor->id)
            ->set('weekday', Weekday::Wednesday->value)
            ->set('start_time', '08:00')->set('end_time', '12:00')
            ->set('slot_minutes', 15)
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $this->assertDatabaseHas('doctor_schedules', [
            'hospital_id' => $h->id, 'user_id' => $doctor->id,
            'weekday' => Weekday::Wednesday->value, 'slot_minutes' => 15,
        ]);
    }

    public function test_editing_a_window_reloads_times_as_hh_mm(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'hospital_admin');
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $window = DoctorSchedule::factory()->create([
            'hospital_id' => $h->id, 'user_id' => $doctor->id,
            'weekday' => Weekday::Friday, 'start_time' => '09:00:00', 'end_time' => '17:00:00',
        ]);

        Livewire::test(Index::class)->call('edit', $window->id)
            ->assertSet('start_time', '09:00')
            ->assertSet('end_time', '17:00')
            ->assertSet('weekday', Weekday::Friday->value)
            ->set('end_time', '15:30')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('15:30', substr((string) $window->fresh()->end_time, 0, 5));
    }

    public function test_doctor_dropdown_only_lists_this_hospitals_doctors(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        User::factory()->create(['hospital_id' => $a->id, 'name' => 'MyOwnDoctor', 'role' => 'doctor']);
        $foreign = User::factory()->create(['hospital_id' => $b->id, 'name' => 'ForeignDoctor', 'role' => 'doctor']);
        $this->actAs($a, 'hospital_admin');

        $c = Livewire::test(Index::class)->call('create')->assertSee('MyOwnDoctor')->assertDontSee('ForeignDoctor');

        // Defence in depth: even a forged pick of a foreign doctor is rejected.
        $c->set('user_id', $foreign->id)
            ->set('weekday', Weekday::Monday->value)
            ->set('start_time', '09:00')->set('end_time', '10:00')
            ->call('save')->assertHasErrors('user_id');
    }

    public function test_a_role_without_schedules_manage_cannot_create(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'nurse');

        Livewire::test(Index::class)->call('create')->assertForbidden();
        $this->assertDatabaseCount('doctor_schedules', 0);
    }

    public function test_windows_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $doctorB = User::factory()->create(['hospital_id' => $b->id, 'role' => 'doctor', 'name' => 'DrSecret']);
        $windowB = DoctorSchedule::factory()->create(['hospital_id' => $b->id, 'user_id' => $doctorB->id]);
        $this->actAs($a, 'hospital_admin');

        $this->get(route('admin.schedules.index'))->assertOk()->assertDontSee('DrSecret');

        // The global scope hides B's row from A entirely: edit resolves to "not found".
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(Index::class)->call('edit', $windowB->id);
        } finally {
            $this->assertNotNull(DoctorSchedule::withoutGlobalScopes()->find($windowB->id));
        }
    }
}
