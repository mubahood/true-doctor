<?php

namespace Tests\Concerns;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;

/**
 * Shared tenant/role setup for feature and Livewire tests. Replaces the
 * per-file "create user, sync role, actingAs, set CurrentHospital" ritual.
 *
 *   use InteractsWithTenant;
 *   $h = $this->hospital();
 *   $u = $this->actingAsRole('doctor', $h);
 */
trait InteractsWithTenant
{
    protected bool $rbacSeeded = false;

    protected function seedRbac(): void
    {
        if (! $this->rbacSeeded) {
            $this->seed(RbacSeeder::class);
            $this->rbacSeeded = true;
        }
    }

    protected function hospital(array $attributes = []): Hospital
    {
        return Hospital::factory()->create($attributes);
    }

    /** Create a staff member with the given role, sign in, and resolve the tenant context. */
    protected function actingAsRole(string $role, ?Hospital $hospital = null, array $attributes = []): User
    {
        $this->seedRbac();
        $hospital ??= $this->hospital();

        $user = User::factory()->create(array_merge(['hospital_id' => $hospital->id, 'role' => $role], $attributes));
        $user->syncSpatieRole();

        $this->actingAs($user);
        $this->withHospital($hospital);

        return $user;
    }

    protected function actingAsSuperAdmin(array $attributes = []): User
    {
        $this->seedRbac();
        $user = User::factory()->create(array_merge(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true], $attributes));
        $user->syncSpatieRole();
        $this->actingAs($user);
        app(CurrentHospital::class)->set(null);

        return $user;
    }

    /** Resolve the tenant context the way ResolveHospital middleware does for a request. */
    protected function withHospital(?Hospital $hospital): void
    {
        app(CurrentHospital::class)->set($hospital?->id);
    }
}
