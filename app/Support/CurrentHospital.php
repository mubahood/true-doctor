<?php

namespace App\Support;

/**
 * The resolved tenant context for this request/process, set by
 * App\Http\Middleware\ResolveHospital. Bound as a container singleton
 * (see AppServiceProvider) so it's shared by the global scope, middleware,
 * and application code that needs to know "which hospital am I in".
 *
 * No hospital resolved (`id() === null`) means unscoped: either a console/
 * queue context with no HTTP request, or a super-admin who hasn't switched
 * into a specific hospital. HospitalScope treats that as "don't filter",
 * never as "filter to nothing" — the alternative (silently hiding all rows)
 * would be its own kind of tenancy bug.
 */
class CurrentHospital
{
    private ?int $id = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function set(?int $hospitalId): void
    {
        $this->id = $hospitalId;
    }
}
