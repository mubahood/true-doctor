<?php

namespace App\Policies;

use App\Models\PatientCard;
use App\Models\User;

/**
 * A card is money, so there is no read-only view of one: a balance and a credit
 * limit are not things to show somebody who may not act on them. One
 * permission, `patients.card.manage`, gates the whole subject — the same rule
 * PatientPolicy@manageCards has always applied to the panel on the patient
 * page, so the hospital-wide section cannot show more than the panel does.
 */
class PatientCardPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('patients.card.manage');
    }

    public function view(User $user, PatientCard $card): bool
    {
        return $user->can('patients.card.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('patients.card.manage');
    }

    public function update(User $user, PatientCard $card): bool
    {
        return $user->can('patients.card.manage');
    }
}
