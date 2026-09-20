<?php

namespace App\Policies;

use App\Models\FinancialYear;
use App\Models\User;

class FinancialYearPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('finance.view');
    }

    public function view(User $user, FinancialYear $year): bool
    {
        return $user->can('finance.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('finance.manage');
    }
}
