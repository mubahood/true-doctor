<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Ward;

class WardPolicy
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

    public function update(User $user, Ward $model): bool
    {
        return $user->can('wards.manage');
    }

    public function delete(User $user, Ward $model): bool
    {
        return $user->can('wards.manage');
    }
}
