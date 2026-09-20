<?php

namespace App\Support;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;

/**
 * Enforces a hospital's subscription-plan limits (HMS_PLAN.md §21). A plan's
 * `limits` JSON may cap countable resources (max_staff, max_patients, max_beds);
 * a missing/null limit means unlimited. With no active subscription or plan
 * (e.g. a super-admin context), nothing is enforced. Counting uses the tenant's
 * own rows — beware: call within the resolved-hospital context.
 */
class PlanLimit
{
    /** Resource key => [plan limit key, model to count]. */
    private const RESOURCES = [
        'staff' => ['max_staff', User::class],
        'patients' => ['max_patients', Patient::class],
        'beds' => ['max_beds', Bed::class],
    ];

    public function __construct(private readonly CurrentHospital $current) {}

    /** Throw if creating one more of $resource would exceed the plan limit. */
    public function assertCanCreate(string $resource): void
    {
        $limit = $this->limitFor($resource);
        if ($limit === null) {
            return; // unlimited / not enforced
        }

        // Count by hospital_id explicitly — User isn't globally scoped (it's the
        // tenant owner), and for scoped models the extra filter is harmless.
        [, $model] = self::RESOURCES[$resource];
        $current = $model::query()->where('hospital_id', $this->current->id())->count();

        if ($current >= $limit) {
            throw PlanLimitExceededException::make($resource, $limit);
        }
    }

    /** The numeric limit for a resource, or null if unlimited / no plan. */
    public function limitFor(string $resource): ?int
    {
        if (! isset(self::RESOURCES[$resource])) {
            return null;
        }
        $hospitalId = $this->current->id();
        if ($hospitalId === null) {
            return null;
        }
        $hospital = Hospital::find($hospitalId);
        $plan = $hospital?->activeSubscription()?->plan;
        if ($plan === null) {
            return null;
        }

        [$limitKey] = self::RESOURCES[$resource];
        $value = $plan->limit($limitKey);

        return is_numeric($value) ? (int) $value : null;
    }
}
