<?php

namespace Tests\Feature;

use App\Livewire\StaffProfiles\Index;
use App\Models\Hospital;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff profiles through their only surface: the Livewire index + slide-over
 * (the classic controller/create/edit path was removed).
 */
class StaffProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function admin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_create_a_profile_for_a_staff_user(): void
    {
        $h = Hospital::factory()->create();
        $this->admin($h);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);

        Livewire::test(Index::class)->call('create')
            ->set('user_id', $doctor->id)->set('specialty', 'Cardiology')
            ->set('license_no', 'LIC-99')->set('is_active', true)
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $this->assertDatabaseHas('staff_profiles', ['hospital_id' => $h->id, 'user_id' => $doctor->id, 'specialty' => 'Cardiology']);
    }

    public function test_one_profile_per_user(): void
    {
        $h = Hospital::factory()->create();
        $this->admin($h);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        StaffProfile::factory()->create(['hospital_id' => $h->id, 'user_id' => $doctor->id]);

        Livewire::test(Index::class)->call('create')->set('user_id', $doctor->id)
            ->call('save')->assertHasErrors('user_id');
    }

    public function test_cannot_link_a_user_from_another_hospital(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $doctorB = User::factory()->create(['hospital_id' => $b->id, 'role' => 'doctor']);
        $this->admin($a);

        $c = Livewire::test(Index::class)->call('create')->assertDontSee($doctorB->name);

        // Defence in depth: even a forged pick of a foreign user is rejected.
        $c->set('user_id', $doctorB->id)->call('save')->assertHasErrors('user_id');
        $this->assertDatabaseCount('staff_profiles', 0);
    }

    public function test_profiles_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $userB = User::factory()->create(['hospital_id' => $b->id, 'role' => 'nurse', 'name' => 'NurseB']);
        $profileB = StaffProfile::factory()->create(['hospital_id' => $b->id, 'user_id' => $userB->id]);
        $this->admin($a);

        $this->get(route('admin.staff.index'))->assertOk()->assertDontSee('NurseB');

        // The global scope hides B's row from A entirely: edit resolves to "not found".
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(Index::class)->call('edit', $profileB->id);
        } finally {
            $this->assertSame($userB->id, StaffProfile::withoutGlobalScopes()->find($profileB->id)->user_id);
        }
    }
}
