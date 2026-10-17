<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Purchase orders, deliveries and returns: read with pharmacy.view; raised,
 * sent, received against, returned from, closed and cancelled with
 * pharmacy.manage (the same people who receive stock by hand today).
 */
class PurchaseOrderPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('pharmacy.view');
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $user->can('pharmacy.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pharmacy.manage');
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $user->can('pharmacy.manage');
    }
}
