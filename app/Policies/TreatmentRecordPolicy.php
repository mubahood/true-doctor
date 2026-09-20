<?php

namespace App\Policies;

use App\Models\TreatmentRecord;
use App\Models\User;

class TreatmentRecordPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('treatments.view');
    }

    public function view(User $user, TreatmentRecord $record): bool
    {
        return $user->can('treatments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('treatments.manage');
    }

    public function delete(User $user, TreatmentRecord $record): bool
    {
        return $user->can('treatments.manage');
    }
}
