<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function view(User $user, Room $room): bool
    {
        return $user->can('access-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('rooms.manage');
    }

    public function update(User $user, Room $room): bool
    {
        return $user->can('rooms.manage');
    }

    public function delete(User $user, Room $room): bool
    {
        return $user->can('rooms.manage');
    }
}
