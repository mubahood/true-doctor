<?php

namespace App\Policies;

use App\Models\StockItem;
use App\Models\User;

class StockItemPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('pharmacy.view');
    }

    public function view(User $user, StockItem $item): bool
    {
        return $user->can('pharmacy.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pharmacy.manage');
    }

    public function update(User $user, StockItem $item): bool
    {
        return $user->can('pharmacy.manage');
    }

    public function delete(User $user, StockItem $item): bool
    {
        return $user->can('pharmacy.manage');
    }
}
