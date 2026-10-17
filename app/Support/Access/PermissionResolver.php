<?php

namespace App\Support\Access;

use App\Models\RoleAbility;
use App\Models\User;
use App\Models\UserAbility;
use Illuminate\Database\QueryException;

/**
 * A clinic's own access choices, applied on top of the default matrix.
 *
 * User::hasPermissionTo() asks here first, so every check there is goes
 * through it: `$user->can()`, `@can`, Policies, the `permission:` route
 * middleware, Spatie's Gate hook and therefore the API. Spatie's own
 * permission cache (the role matrix) is left alone: these rows are separate,
 * and read once per request or queued job (a scoped binding).
 *
 * In order: a permission an ability hands out directly (to the role, or to
 * this one person) wins; then one the clinic took from the role; then one
 * handed out only as a companion (`also` in Abilities). Nothing here ever
 * applies to the hospital admin or a platform account.
 */
final class PermissionResolver
{
    /** @var array<int, array{strong: array<string,true>, revoke: array<string,true>, weak: array<string,true>}> */
    private array $byUser = [];

    /** true or false when the clinic decided; null to fall back on the role matrix. */
    public function decide(User $user, string $permission): ?bool
    {
        $a = $this->adjustments($user);

        return match (true) {
            isset($a['strong'][$permission]) => true,
            isset($a['revoke'][$permission]) => false,
            isset($a['weak'][$permission]) => true,
            default => null,
        };
    }

    /** @return array{strong: array<string,true>, revoke: array<string,true>, weak: array<string,true>} */
    public function adjustments(User $user): array
    {
        $none = ['strong' => [], 'revoke' => [], 'weak' => []];

        // The hospital admin and platform accounts are never adjusted: the
        // admin keeps everything, so a clinic cannot lock itself out.
        if (! $user->exists || $user->hospital_id === null || ! Abilities::roleIsEditable((string) $user->role)) {
            return $none;
        }

        return $this->byUser[$user->id] ??= $this->load($user);
    }

    /** Forget what was read, after a change. */
    public function flush(): void
    {
        $this->byUser = [];
    }

    /** @return array{strong: array<string,true>, revoke: array<string,true>, weak: array<string,true>} */
    private function load(User $user): array
    {
        $strong = $revoke = $weak = [];

        try {
            // By the account's own hospital, and through the tenant scope as
            // well: with no tenant resolved yet (a queued job, the start of an
            // API request) the scope alone would read every clinic's choices.
            $roleRows = RoleAbility::query()
                ->where('hospital_id', $user->hospital_id)
                ->where('role', (string) $user->role)
                ->get(['ability', 'allowed']);

            $personal = UserAbility::query()
                ->where('hospital_id', $user->hospital_id)
                ->where('user_id', $user->id)
                ->pluck('ability');
        } catch (QueryException) {
            // Before the migration that makes the tables has run.
            return ['strong' => [], 'revoke' => [], 'weak' => []];
        }

        foreach ($roleRows as $row) {
            if (! Abilities::exists($row->ability)) {
                continue;
            }
            foreach (Abilities::permissions($row->ability) as $p) {
                if ($row->allowed) {
                    $strong[$p] = true;
                } else {
                    $revoke[$p] = true;
                }
            }
            if ($row->allowed) {
                foreach (Abilities::granted($row->ability) as $p) {
                    $weak[$p] = true;
                }
            }
        }

        foreach ($personal as $ability) {
            if (! Abilities::exists((string) $ability)) {
                continue;
            }
            foreach (Abilities::permissions((string) $ability) as $p) {
                $strong[$p] = true;
            }
            foreach (Abilities::granted((string) $ability) as $p) {
                $weak[$p] = true;
            }
        }

        return ['strong' => $strong, 'revoke' => $revoke, 'weak' => $weak];
    }
}
