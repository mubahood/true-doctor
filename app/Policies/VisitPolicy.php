<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;

class VisitPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('visits.view');
    }

    public function view(User $user, Visit $visit): bool
    {
        return $user->can('visits.view');
    }

    public function create(User $user): bool
    {
        return $user->can('visits.create');
    }

    /** Triage — record vitals. */
    public function recordVitals(User $user, Visit $visit): bool
    {
        return $user->can('visits.vitals');
    }

    /** Doctor — write the clinical narrative / diagnosis. */
    public function diagnose(User $user, Visit $visit): bool
    {
        return $user->can('visits.diagnose');
    }

    /** Advance / cancel the pipeline. */
    public function manage(User $user, Visit $visit): bool
    {
        return $user->can('visits.manage');
    }

    /**
     * Put the visit at a stage the pipeline would refuse.
     *
     * Deliberately its own permission and not `visits.manage`: everyone who
     * runs a visit moves it forward, and almost nobody should be able to move
     * it back, re-open a finished one, or skip the billing stage.
     */
    public function overrideStage(User $user, Visit $visit): bool
    {
        return $user->can('visits.override');
    }
}
