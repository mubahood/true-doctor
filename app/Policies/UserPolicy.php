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
        return $actor->can('manage-users') && $this->manages($actor, $target) && $this->mayTouchAdmin($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can('manage-users') && $this->manages($actor, $target) && $this->mayTouchAdmin($actor, $target);
    }

    /**
     * A hospital admin's account (role, password, on or off, removal) is
     * changed only by another hospital admin. Somebody given "Manage staff
     * and settings" in Staff and access runs the other accounts; letting them
     * reset an admin's password would make the ability a way into the role.
     */
    private function mayTouchAdmin(User $actor, User $target): bool
    {
        return $target->role !== 'hospital_admin' || $actor->role === 'hospital_admin';
    }

    /** Same tenant only (a super-admin never reaches this — see before()). */
    private function manages(User $actor, User $target): bool
    {
        return $target->hospital_id !== null && $target->hospital_id === $actor->hospital_id;
    }
}
