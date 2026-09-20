<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

/**
 * Who may see and control the machines holding a copy of patient records.
 *
 * `manage-users` rather than a new permission: the list of devices carrying
 * PHI is administrative in exactly the way the staff list is, and it is the
 * same people who need it. Inventing `devices.manage` would mean a permission
 * nothing had granted anybody, so every hospital's list would be invisible
 * until somebody noticed — which is the "permission nothing enforces" failure
 * `decisions.md` already warns about, upside down.
 */
class DevicePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('manage-users');
    }

    public function view(User $user, Device $device): bool
    {
        return $user->can('manage-users');
    }

    /** Revoking a device is the one action here, and it is not reversible for
     *  the data already on the machine — only for future syncs. */
    public function manage(User $user): bool
    {
        return $user->can('manage-users');
    }
}
