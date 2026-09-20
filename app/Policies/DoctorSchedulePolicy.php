<?php

namespace App\Policies;

use App\Models\DoctorSchedule;
use App\Models\User;

class DoctorSchedulePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * Whoever books appointments needs to know when doctors are free. This used
     * to be `access-admin`, so any signed-in staff member could open the board
     * by typing the URL — the sidebar entry was gated tighter than the page it
     * pointed at. `appointments.view` closes that and widens the menu entry to
     * doctors, nurses and receptionists, who get a read-only board: every write
     * below still takes `schedules.manage`.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('appointments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('schedules.manage');
    }

    public function update(User $user, DoctorSchedule $schedule): bool
    {
        return $user->can('schedules.manage');
    }

    public function delete(User $user, DoctorSchedule $schedule): bool
    {
        return $user->can('schedules.manage');
    }
}
