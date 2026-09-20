<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/**
 * Mirrors the RBAC matrix; enforced identically in Blade and (later) API (C13).
 * The global scope guarantees a user only ever loads their own hospital's
 * patients, so these checks are purely about *capability*, not tenancy.
 */
class PatientPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('patients.view');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->can('patients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('patients.create');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->can('patients.update');
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->can('patients.delete');
    }

    /** Issue / top-up / charge a patient's prepaid card (money). */
    public function manageCards(User $user, Patient $patient): bool
    {
        return $user->can('patients.card.manage');
    }

    /** Upload / view / remove patient documents. */
    public function manageDocuments(User $user, Patient $patient): bool
    {
        return $user->can('patients.update');
    }

    /** Link / unlink dependents and guardians. */
    public function manageDependents(User $user, Patient $patient): bool
    {
        return $user->can('patients.update');
    }
}
