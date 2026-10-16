<?php

namespace App\Support;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a visitor becomes when they walk into the demonstration: one account
 * per role, chosen by the server, never by the visitor. Shared by the app's
 * door (Api\V1\DemoController) and the web's (/demo), so both open exactly
 * the same accounts.
 *
 * The account for a role is the first active, seeded `@test.com` staff member
 * of that role in the demonstration hospital: never a platform account, never
 * another hospital, never an account the visitor named.
 */
final class DemoDoor
{
    public static function hospital(): ?Hospital
    {
        if (! Demo::enabled()) {
            return null;
        }

        return Hospital::query()->where('slug', Demo::hospitalSlug())->first();
    }

    /** @return Collection<string, User> keyed by role */
    public static function accounts(?Hospital $hospital = null): Collection
    {
        $hospital ??= self::hospital();
        if ($hospital === null) {
            return collect();
        }

        return User::query()
            ->where('hospital_id', $hospital->id)
            ->where('email', 'like', '%@test.com')
            ->where('is_admin', false)
            ->where('is_active', true)
            ->whereIn('role', DemoPersonas::roles())
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u) => ! $u->isSuperAdmin() && $u->canAccessAdmin())
            ->unique('role')
            ->keyBy('role');
    }

    /**
     * The roles a visitor can walk in as, with what to try as each.
     *
     * @return list<array<string,mixed>>
     */
    public static function roles(?Hospital $hospital = null): array
    {
        $accounts = self::accounts($hospital);
        $roles = [];
        foreach (DemoPersonas::all() as $key => $persona) {
            $user = $accounts->get($key);
            if ($user !== null) {
                $roles[] = ['key' => $key, 'label' => $user->role_label] + $persona;
            }
        }

        return $roles;
    }
}
