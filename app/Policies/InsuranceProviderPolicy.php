<?php

namespace App\Policies;

use App\Models\InsuranceProvider;
use App\Models\User;

class InsuranceProviderPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('insurance.view');
    }

    public function create(User $user): bool
    {
        return $user->can('insurance.manage');
    }

    public function update(User $user, InsuranceProvider $provider): bool
    {
        return $user->can('insurance.manage');
    }

    public function delete(User $user, InsuranceProvider $provider): bool
    {
        return $user->can('insurance.manage');
    }
}
