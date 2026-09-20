<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard "staff accounts" stat must be tenant-scoped. User has no global
 * scope, so an unscoped count would show every hospital's staff to every admin.
 */
class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading(); // render the lazy sections inline
    }

    private function admin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_staff_count_shows_only_the_current_hospitals_users(): void
    {
        $mine = Hospital::factory()->create();
        $other = Hospital::factory()->create();

        // 1 more in my hospital (2 total incl. the admin), 5 in another hospital.
        User::factory()->create(['hospital_id' => $mine->id]);
        User::factory()->count(5)->create(['hospital_id' => $other->id]);

        $this->actingAs($this->admin($mine))
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['>2<', 'Staff']) // my 2, never the other hospital's 5
            ->assertDontSee('>7<'); // 2 + 5 would be 7 if the count leaked
    }
}
