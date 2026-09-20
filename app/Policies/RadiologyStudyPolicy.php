<?php

namespace App\Policies;

use App\Models\RadiologyStudy;
use App\Models\User;

class RadiologyStudyPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('radiology.view');
    }

    public function create(User $user): bool
    {
        return $user->can('radiology.manage');
    }

    public function update(User $user, RadiologyStudy $study): bool
    {
        return $user->can('radiology.manage');
    }

    public function delete(User $user, RadiologyStudy $study): bool
    {
        return $user->can('radiology.manage');
    }
}
