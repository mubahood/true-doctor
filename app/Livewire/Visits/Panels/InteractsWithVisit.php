<?php

namespace App\Livewire\Visits\Panels;

use App\Models\Visit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Shared plumbing for the lazy panels of the visit workspace.
 *
 * A panel never receives a hydrated model from its parent: it carries only the
 * numeric id (#[Locked], so the client cannot swap it) and re-resolves the
 * visit through the tenant global scope on every request. A cross-tenant
 * id therefore 404s in mount() and in every action, exactly like the old
 * route-model-bound controllers did.
 *
 * @property-read Visit $visit  the #[Computed] accessor below, read as a property
 */
trait InteractsWithVisit
{
    #[Locked]
    public int $visitId;

    /** The tenant-scoped visit this panel belongs to (cached per request). */
    #[Computed]
    public function visit(): Visit
    {
        return Visit::findOrFail($this->visitId);
    }
}
