<?php

namespace App\Policies;

use App\Models\StockCategory;
use App\Models\User;

class StockCategoryPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('pharmacy.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pharmacy.manage');
    }

    public function update(User $user, StockCategory $category): bool
    {
        return $user->can('pharmacy.manage');
    }

    public function delete(User $user, StockCategory $category): bool
    {
        return $user->can('pharmacy.manage');
    }
}
