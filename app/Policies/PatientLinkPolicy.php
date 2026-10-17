<?php

namespace App\Policies;

use App\Models\PatientLink;
use App\Models\User;

/**
 * Patient links (roadmap 05 §4). Anyone who may read patients may see which
 * links went out and who opened them; withdrawing one takes the same right as
 * sending it, which is the right to print that document.
 */
class PatientLinkPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('patients.view');
    }

    public function view(User $user, PatientLink $link): bool
    {
        return $user->can('patients.view');
    }

    public function revoke(User $user, PatientLink $link): bool
    {
        return $link->document_type->allows($user);
    }
}
