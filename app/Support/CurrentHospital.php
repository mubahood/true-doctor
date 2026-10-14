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

    /**
     * Run something as one hospital, then put the context back.
     *
     * For work that belongs to a record rather than to the request: the books
     * an invoice posts into are its hospital's, whether it was raised at a
     * counter, by a queued job or by a seeder with no tenant resolved at all.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public function within(int $hospitalId, \Closure $work): mixed
    {
        $previous = $this->id;
        $this->id = $hospitalId;

        try {
            return $work();
        } finally {
            $this->id = $previous;
        }
    }
}
