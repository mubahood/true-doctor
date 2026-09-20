<?php

namespace App\Policies;

use App\Models\Prescription;
use App\Models\User;

class PrescriptionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, Prescription $prescription): bool
    {
        return $user->can('visits.view');
    }

    /** Doctor writes prescriptions. */
    public function prescribe(User $user): bool
    {
        return $user->can('prescriptions.prescribe');
    }

    /** Nursing staff mark doses administered/missed. */
    public function administer(User $user): bool
    {
        return $user->can('prescriptions.administer');
    }
}
