<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('services.manage');
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->can('services.manage');
    }
}
