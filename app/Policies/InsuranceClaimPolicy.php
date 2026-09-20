<?php

namespace App\Policies;

use App\Models\InsuranceClaim;
use App\Models\User;

class InsuranceClaimPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('insurance.view');
    }

    public function view(User $user, InsuranceClaim $claim): bool
    {
        return $user->can('insurance.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('insurance.manage');
    }
}
