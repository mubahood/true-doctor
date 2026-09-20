<?php

namespace App\Policies;

use App\Models\User;

/**
 * Staff-account management. Two conditions, always both: the actor holds the
 * `manage-users` capability, and the target account is one the actor may manage
 * (their own hospital — User has no global tenant scope, so this is explicit).
 * A structural super-admin bypasses both.
 */
class UserPolicy
{
    public function before(User $actor, string $ability): ?bool
    {
        return $actor->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->can('manage-users');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('manage-users') && $this->manages($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->can('manage-users');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('manage-users') && $this->manages($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can('manage-users') && $this->manages($actor, $target);
    }

    /** Same tenant only (a super-admin never reaches this — see before()). */
    private function manages(User $actor, User $target): bool
    {
        return $target->hospital_id !== null && $target->hospital_id === $actor->hospital_id;
    }
}
