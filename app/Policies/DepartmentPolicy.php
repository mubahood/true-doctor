<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Any signed-in staff member may see the org structure (access-admin); only
 * roles with `departments.manage` (hospital admin) may change it. Tenancy is
 * enforced separately by the global scope — these are capability checks (C13).
 */
class DepartmentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->can('access-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('departments.manage');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->can('departments.manage');
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->can('departments.manage');
    }
}
