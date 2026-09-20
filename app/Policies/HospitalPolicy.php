<?php

namespace App\Policies;

use App\Models\Hospital;
use App\Models\User;

/**
 * The platform surface (/super/*) belongs to super-admins alone: a hospital is
 * a *tenant*, not one of a tenant's records, so no hospital-scoped role may
 * ever read or write one (J3). The gate is the same for every ability.
 */
class HospitalPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Hospital $hospital): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Hospital $hospital): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Hospital $hospital): bool
    {
        return $user->isSuperAdmin();
    }
}
