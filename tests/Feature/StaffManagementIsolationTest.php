<?php

namespace Tests\Feature;

use App\Livewire\Users\Index;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * HMS_PLAN.md §2.1 — "every new tenant-scoped feature ships with an isolation
 * test". User::scopeManageableBy() (via App\Services\StaffService) is the
 * explicit tenancy check here: User can't use the BelongsToHospital global
 * scope, because super-admin accounts with hospital_id = null must coexist
 * with hospital-scoped ones. The classic UserController was removed — staff
 * management is the Livewire index + slide-over only.
 */
class StaffManagementIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function hospitalAdmin(Hospital $hospital): User
    {
        $u = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($hospital->id);

        return $u;
    }

    public function test_hospital_a_cannot_list_hospital_bs_staff(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();
        $this->hospitalAdmin($hospitalA);
        User::factory()->create(['hospital_id' => $hospitalB->id, 'name' => 'Nurse B']);

        $this->get(route('admin.users.index'))->assertOk()->assertDontSee('Nurse B');
        Livewire::test(Index::class)->assertOk()->assertDontSee('Nurse B');
    }

    public function test_hospital_a_cannot_view_or_edit_hospital_bs_staff_by_id(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();
        $this->hospitalAdmin($hospitalA);
        $staffB = User::factory()->create(['hospital_id' => $hospitalB->id]);

        // B's row simply does not exist for A: edit/save/delete resolve to "not found".
        foreach (['edit', 'delete'] as $action) {
            try {
                Livewire::test(Index::class)->call($action, $staffB->id);
                $this->fail("{$action}() leaked a foreign tenant's user.");
            } catch (ModelNotFoundException) {
                // expected
            }
        }

        $this->assertNotSame('Tampered', $staffB->fresh()->name);
        $this->assertNotNull(User::find($staffB->id));
    }

    public function test_a_new_staff_member_is_auto_assigned_to_the_creating_admins_hospital(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();
        $this->hospitalAdmin($hospitalA);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'New Nurse')
            ->set('email', 'nurse@a.test')
            ->set('role', 'nurse')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $created = User::where('email', 'nurse@a.test')->firstOrFail();
        $this->assertSame($hospitalA->id, $created->hospital_id);
        $this->assertNotSame($hospitalB->id, $created->hospital_id);
    }

    public function test_super_admin_sees_staff_across_every_hospital(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();
        User::factory()->create(['hospital_id' => $hospitalA->id, 'name' => 'Staff A']);
        User::factory()->create(['hospital_id' => $hospitalB->id, 'name' => 'Staff B']);

        $superAdmin = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $superAdmin->syncSpatieRole();
        $this->actingAs($superAdmin);

        $this->get(route('admin.users.index'))->assertOk()->assertSee('Staff A')->assertSee('Staff B');
        Livewire::test(Index::class)->assertOk()->assertSee('Staff A')->assertSee('Staff B');
    }
}
