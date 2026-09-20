<?php

namespace App\Policies;

use App\Models\LabTest;
use App\Models\User;

class LabTestPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('lab.view');
    }

    public function create(User $user): bool
    {
        return $user->can('lab.manage');
    }

    public function update(User $user, LabTest $test): bool
    {
        return $user->can('lab.manage');
    }

    public function delete(User $user, LabTest $test): bool
    {
        return $user->can('lab.manage');
    }
}
