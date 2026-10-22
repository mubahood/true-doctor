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

    /**
     * Read the clinical record of the visit: complaints, diagnosis, notes,
     * prescriptions, the dental chart, results, the visit report. Vitals are
     * not in it: the desk takes them at intake.
     * The desk and the cashier see that a visit happened, its reason and its
     * bill, not this (docs/rbac.md).
     */
    public function viewClinical(User $user, Visit $visit): bool
    {
        return $user->can('visits.clinical');
    }

    /**
     * See the visit's vitals: the clinical team, whoever records them, and
     * the desk that takes them at intake (`visits.create`). Not the cashier.
     */
    public function viewVitals(User $user, Visit $visit): bool
    {
        return $user->can('visits.clinical') || $user->can('visits.vitals') || $user->can('visits.create');
    }

    /**
     * See this visit's bill. Whoever sees the clinic's money sees every bill;
     * somebody who only puts charges on bills (a doctor, by default) sees the
     * bills of their own patients.
     */
    public function viewBill(User $user, Visit $visit): bool
    {
        if ($user->can('billing.view')) {
            return true;
        }

        return $user->can('billing.manage') && $visit->doctor_user_id !== null && $visit->doctor_user_id === $user->id;
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

    /**
     * Delete a visit opened by mistake (VisitService::delete). Its own
     * permission, given to the hospital admin by default and to whoever
     * Staff and access allows.
     */
    public function delete(User $user, Visit $visit): bool
    {
        return $user->can('visits.delete');
    }
}
