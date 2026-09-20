<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

/**
 * A subscription is billing state owned by the platform, not by the tenant it
 * points at: a hospital admin must never be able to extend or reactivate their
 * own subscription, so every ability is super-admin only.
 */
class SubscriptionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->isSuperAdmin();
    }
}
