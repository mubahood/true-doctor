<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('billing.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('billing.manage');
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.manage');
    }
}
