<?php

namespace App\Livewire\Admissions\Panels;

use App\Models\Admission;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Shared plumbing for the lazy panels of the admission workspace.
 *
 * A panel never receives a hydrated model from its parent: it carries only the
 * numeric id (#[Locked], so the client cannot swap it) and re-resolves the
 * admission through the tenant global scope on every request. A cross-tenant id
 * therefore 404s in mount() and in every action, exactly like the old
 * route-model-bound NursingController did.
 *
 * `guard()` reproduces that controller's two checks verbatim: ipd.manage, then
 * "only an active admission accepts new entries" as a 422 with the same message.
 *
 * @property-read Admission $admission  the #[Computed] accessor below, read as a property
 */
trait InteractsWithAdmission
{
    #[Locked]
    public int $admissionId;

    /** The tenant-scoped admission this panel belongs to (cached per request). */
    #[Computed]
    public function admission(): Admission
    {
        return Admission::findOrFail($this->admissionId);
    }

    /** Append-only logs are refused on a closed stay — same 422 as NursingController. */
    protected function guardActive(): Admission
    {
        $admission = $this->admission;

        $this->authorize('manage', Admission::class);
        abort_unless($admission->status->isActive(), 422, 'This admission is closed.');

        return $admission;
    }
}
