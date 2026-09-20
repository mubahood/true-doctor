<?php

namespace App\Policies;

use App\Models\Admission;
use App\Models\User;

class AdmissionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('ipd.view');
    }

    public function view(User $user, Admission $admission): bool
    {
        return $user->can('ipd.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('ipd.manage');
    }
}
