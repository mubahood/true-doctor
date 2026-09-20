<?php

namespace App\Policies;

use App\Models\Bed;
use App\Models\User;

class BedPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('ipd.view');
    }

    public function create(User $user): bool
    {
        return $user->can('wards.manage');
    }

    public function update(User $user, Bed $model): bool
    {
        return $user->can('wards.manage');
    }

    public function delete(User $user, Bed $model): bool
    {
        return $user->can('wards.manage');
    }
}
