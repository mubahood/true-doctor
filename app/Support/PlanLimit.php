<?php

namespace App\Support;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\User;

/**
 * Enforces a hospital's subscription-plan limits (HMS_PLAN.md §21). A plan's
 * `limits` JSON may cap staff accounts and beds (max_staff, max_beds); a
 * missing/null limit means unlimited.
 *
 * Patients are never limited, in either edition: a clinic must never be
 * stopped from registering the patient standing at its desk. A `max_patients`
 * left in an old plan's JSON is ignored (docs/decisions.md, 2026-10-17). With no active subscription or plan
 * (e.g. a super-admin context), nothing is enforced. Counting uses the tenant's
 * own rows — beware: call within the resolved-hospital context.
 */
class PlanLimit
{
    /** Resource key => [plan limit key, model to count]. */
    private const RESOURCES = [
        'staff' => ['max_staff', User::class],
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
            return null; // patients, and anything else never capped
        }
        $hospitalId = $this->current->id();
        if ($hospitalId === null) {
            return null;
        }

        [$limitKey] = self::RESOURCES[$resource];
        if (Edition::isStandalone()) {
            // A licence may meter nonclinical seats, never the ability to add
            // a bed during care. S3 holds even for a valid licence.
            if ($resource !== 'staff') {
                return null;
            }

            return app(\App\Support\Licensing\InstalledLicence::class)->limit($limitKey);
        }

        $hospital = Hospital::find($hospitalId);
        $plan = $hospital?->activeSubscription()?->plan;
        if ($plan === null) {
            return null;
        }

        $value = $plan->limit($limitKey);

        return is_numeric($value) ? (int) $value : null;
    }
}
