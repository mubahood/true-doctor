<?php

namespace App\Services;

use App\Models\RoleAbility;
use App\Models\User;
use App\Models\UserAbility;
use App\Support\Access\Abilities;
use App\Support\Access\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Settings, Staff and access: a clinic deciding what each role, and now and
 * then one person, may do (docs/rbac.md).
 *
 * The default matrix (RbacSeeder) is never edited. A choice that differs from
 * it is a RoleAbility row for this hospital; a choice that puts a role back
 * where the default has it removes the row. An extra ability for one person is
 * a UserAbility row. All three are audited by the model observer, so "who
 * gave the receptionist discounts, and when" has an answer.
 *
 * The safeguards are here, not on the screen: the hospital admin role cannot
 * be changed (so the clinic can never lock itself out of this page), only a
 * hospital admin may change anything, and abilities that depend on another
 * move with it (diagnosing needs seeing the notes).
 */
final class AccessService
{
    public function __construct(private readonly PermissionResolver $resolver) {}

    /** Only a hospital admin decides who may do what in their clinic. */
    public static function canManage(?User $actor): bool
    {
        return $actor !== null && $actor->hospital_id !== null && $actor->role === Abilities::LOCKED_ROLE;
    }

    /**
     * The table on the screen: every editable role against every ability.
     *
     * @return array<string, array<string, array{on:bool, partial:bool, default:bool, changed:bool}>>
     */
    public function matrix(): array
    {
        $choices = RoleAbility::query()->get(['role', 'ability', 'allowed'])
            ->groupBy('role')->map(fn ($rows) => $rows->pluck('allowed', 'ability')->all())->all();

        $out = [];
        foreach (Abilities::ROLES as $role) {
            $defaults = $this->defaultPermissions($role);
            $effective = $this->effective($defaults, $choices[$role] ?? []);
            foreach (array_keys(Abilities::ALL) as $ability) {
                $perms = Abilities::permissions($ability);
                $held = count(array_intersect($perms, $effective));
                $out[$role][$ability] = [
                    'on' => $held === count($perms),
                    'partial' => $held > 0 && $held < count($perms),
                    'default' => array_diff($perms, $defaults) === [],
                    'changed' => array_key_exists($ability, $choices[$role] ?? []),
                ];
            }
        }

        return $out;
    }

    /**
     * Switch an ability on or off for a role. Returns the abilities that
     * moved, the one asked for first, so the screen can say what else changed.
     *
     * @return list<string>
     *
     * @throws \DomainException
     */
    public function setForRole(User $actor, string $role, string $ability, bool $allowed): array
    {
        $this->assertCanManage($actor);
        if ($role === Abilities::LOCKED_ROLE) {
            throw new \DomainException('The hospital admin keeps every ability, so the clinic can never be locked out.');
        }
        if (! Abilities::roleIsEditable($role)) {
            throw new \DomainException('That role cannot be changed here.');
        }
        if (! Abilities::exists($ability)) {
            throw new \DomainException('That ability is not one of the list.');
        }

        // Diagnosing without reading the notes, or the reverse, makes no sense:
        // switching one on brings what it needs, switching one off takes what needs it.
        $moves = [$ability];
        foreach ($allowed ? Abilities::needs($ability) : Abilities::neededBy($ability) as $linked) {
            $moves[] = $linked;
        }

        DB::transaction(function () use ($actor, $role, $moves, $allowed) {
            $defaults = $this->defaultPermissions($role);
            foreach ($moves as $key) {
                // The standard already says this when it gives all of the
                // ability (for on) or none of it (for off); a role that has
                // part of it by default needs a row either way.
                $perms = Abilities::permissions($key);
                $standard = $allowed
                    ? array_diff($perms, $defaults) === []
                    : array_intersect($perms, $defaults) === [];
                $row = RoleAbility::query()->where('role', $role)->where('ability', $key)->lockForUpdate()->first();

                if ($standard) {
                    // Back where the default has it: the clinic follows the default again.
                    $row?->delete();

                    continue;
                }

                if ($row === null) {
                    RoleAbility::create(['role' => $role, 'ability' => $key, 'allowed' => $allowed, 'changed_by' => $actor->id]);
                } elseif ($row->allowed !== $allowed) {
                    $row->update(['allowed' => $allowed, 'changed_by' => $actor->id]);
                }
            }
        });

        $this->resolver->flush();

        return $moves;
    }

    /** Put a role back to the default matrix. */
    public function resetRole(User $actor, string $role): void
    {
        $this->assertCanManage($actor);
        if (! Abilities::roleIsEditable($role)) {
            throw new \DomainException('That role cannot be changed here.');
        }

        // One by one, so each removal is in the audit trail.
        RoleAbility::query()->where('role', $role)->get()->each->delete();
        $this->resolver->flush();
    }

    /** @return list<string> the extra abilities this person has, on top of their role */
    public function personal(User $user): array
    {
        if ($user->hospital_id === null) {
            return [];
        }

        return UserAbility::query()->where('user_id', $user->id)->orderBy('ability')->pluck('ability')
            ->filter(fn ($a) => Abilities::exists((string) $a))->map(fn ($a) => (string) $a)->values()->all();
    }

    /**
     * Make this person's extra abilities exactly $abilities. The caller has
     * already made sure $user is one of this hospital's staff.
     *
     * @param  list<string>  $abilities
     *
     * @throws \DomainException
     */
    public function setPersonal(User $actor, User $user, array $abilities): void
    {
        $this->assertCanManage($actor);
        if ($user->hospital_id === null || $user->hospital_id !== $actor->hospital_id) {
            throw new \DomainException('That account is not one of this clinic\'s.');
        }

        $wanted = array_values(array_unique(array_filter($abilities, fn ($a) => Abilities::exists((string) $a))));
        if ($wanted !== [] && ! Abilities::roleIsEditable((string) $user->role)) {
            throw new \DomainException('A hospital admin already has every ability.');
        }
        // An ability brings what it needs, here as on a role.
        foreach ($wanted as $a) {
            foreach (Abilities::needs($a) as $n) {
                if (! in_array($n, $wanted, true)) {
                    $wanted[] = $n;
                }
            }
        }

        DB::transaction(function () use ($actor, $user, $wanted) {
            $have = UserAbility::query()->where('user_id', $user->id)->lockForUpdate()->get()->keyBy('ability');

            foreach ($have as $ability => $row) {
                if (! in_array($ability, $wanted, true)) {
                    $row->delete();
                }
            }
            foreach ($wanted as $ability) {
                if (! $have->has($ability)) {
                    UserAbility::create(['user_id' => $user->id, 'ability' => $ability, 'granted_by' => $actor->id]);
                }
            }
        });

        $this->resolver->flush();
    }

    /**
     * What a role may do in this clinic, after its choices: the permission
     * strings, for the API's role picker.
     *
     * @return list<string>
     */
    public function rolePermissions(string $role): array
    {
        $defaults = $this->defaultPermissions($role);
        if (! Abilities::roleIsEditable($role)) {
            return $defaults;
        }

        $choices = RoleAbility::query()->where('role', $role)->pluck('allowed', 'ability')->all();

        return $this->effective($defaults, $choices);
    }

    /** @return list<string> the role's permissions in the default matrix */
    public function defaultPermissions(string $role): array
    {
        $model = Role::query()->where('name', $role)->where('guard_name', 'web')->first();

        return $model === null ? [] : $model->permissions->pluck('name')->map(fn ($n) => (string) $n)->sort()->values()->all();
    }

    /**
     * @param  list<string>  $defaults
     * @param  array<string, bool|int>  $choices  ability => allowed
     * @return list<string>
     */
    private function effective(array $defaults, array $choices): array
    {
        $strong = $revoke = $weak = [];
        foreach ($choices as $ability => $allowed) {
            if (! Abilities::exists((string) $ability)) {
                continue;
            }
            foreach (Abilities::permissions((string) $ability) as $p) {
                if ($allowed) {
                    $strong[$p] = true;
                } else {
                    $revoke[$p] = true;
                }
            }
            if ($allowed) {
                foreach (Abilities::granted((string) $ability) as $p) {
                    $weak[$p] = true;
                }
            }
        }

        $out = [];
        foreach (array_unique([...$defaults, ...array_keys($strong), ...array_keys($weak)]) as $p) {
            if (isset($strong[$p]) || (! isset($revoke[$p]) && (in_array($p, $defaults, true) || isset($weak[$p])))) {
                $out[] = $p;
            }
        }
        sort($out);

        return $out;
    }

    private function assertCanManage(User $actor): void
    {
        if (! self::canManage($actor)) {
            throw new \DomainException('Only a hospital admin can change who may do what.');
        }
    }
}
