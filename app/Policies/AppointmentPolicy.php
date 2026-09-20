<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('appointments.view');
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('appointments.manage');
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.manage');
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.manage');
    }
}
