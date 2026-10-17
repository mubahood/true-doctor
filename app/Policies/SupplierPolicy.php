<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

/** Suppliers are part of the store: read with pharmacy.view, kept with pharmacy.manage. */
class SupplierPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('pharmacy.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('pharmacy.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pharmacy.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('pharmacy.manage');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->can('pharmacy.manage');
    }
}
