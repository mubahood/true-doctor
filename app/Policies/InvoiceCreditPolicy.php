<?php

namespace App\Policies;

use App\Models\InvoiceCredit;
use App\Models\User;

/** Credit notes and refunds (docs/refunds.md): read with the invoices, decided with billing.refund. */
class InvoiceCreditPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('billing.view');
    }

    public function view(User $user, InvoiceCredit $credit): bool
    {
        return $user->can('billing.view');
    }

    public function approve(User $user, InvoiceCredit $credit): bool
    {
        return $user->can('billing.refund');
    }
}
