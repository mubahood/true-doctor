<?php

namespace App\Policies;

use App\Models\StaffProfile;
use App\Models\User;

class StaffProfilePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function view(User $user, StaffProfile $profile): bool
    {
        return $user->can('access-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('staff.manage');
    }

    public function update(User $user, StaffProfile $profile): bool
    {
        return $user->can('staff.manage');
    }

    public function delete(User $user, StaffProfile $profile): bool
    {
        return $user->can('staff.manage');
    }
}
