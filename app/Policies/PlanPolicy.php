<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

/**
 * Plans are platform catalogue rows (price + limits JSON) — only super-admins
 * may read or write them from the back office; tenants see them through the
 * public pricing page and checkout, never through a policy check.
 */
class PlanPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->isSuperAdmin();
    }
}
