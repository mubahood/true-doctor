<?php

namespace Tests\Feature;

use App\Livewire\Users\Index as UsersIndex;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A hospital admin holding `manage-users` must never be able to mint (or
 * self-assign) the platform-wide `super_admin` role. Policies grant that role
 * a blanket bypass, so this was a full in-tenant privilege escalation.
 */
class RoleEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function hospitalAdmin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_assignable_roles_exclude_super_admin_for_tenant_admins(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->hospitalAdmin($h);

        $this->assertNotContains('super_admin', User::assignableRolesFor($admin));
        $this->assertContains('doctor', User::assignableRolesFor($admin));

        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $this->assertContains('super_admin', User::assignableRolesFor($super));
    }

    public function test_livewire_user_modal_rejects_super_admin_role_from_a_hospital_admin(): void
    {
        $h = Hospital::factory()->create();
        $this->hospitalAdmin($h);

        Livewire::test(UsersIndex::class)
            ->call('create')
            ->set('name', 'Mallory')
            ->set('email', 'mallory@example.test')
            ->set('role', 'super_admin')
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'mallory@example.test']);
    }

    public function test_user_modal_rejects_self_promotion_to_super_admin(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->hospitalAdmin($h);

        Livewire::test(UsersIndex::class)
            ->call('edit', $admin->id)
            ->set('role', 'super_admin')
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertSame('hospital_admin', $admin->fresh()->role);
        $this->assertFalse($admin->fresh()->isSuperAdmin());
    }

    public function test_policy_bypass_requires_structural_super_admin_not_just_the_spatie_role(): void
    {
        $h = Hospital::factory()->create();
        // A tenant user that somehow carries the Spatie role but still belongs to a hospital.
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $u->syncSpatieRole();
        $u->assignRole('super_admin');
        app(CurrentHospital::class)->set($h->id);

        $this->assertFalse($u->isSuperAdmin());
        // before() must NOT short-circuit to "allow everything" for this user.
        $this->assertNull((new \App\Policies\PatientPolicy)->before($u, 'delete'));

        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $this->assertTrue((new \App\Policies\PatientPolicy)->before($super, 'delete'));
    }
}
