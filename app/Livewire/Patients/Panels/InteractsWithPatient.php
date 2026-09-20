<?php

namespace App\Livewire\Patients\Panels;

use App\Models\Patient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Shared plumbing for the lazy panels of the patient workspace.
 *
 * A panel never receives a hydrated model from its parent: it carries only the
 * numeric id (#[Locked], so the client cannot swap it) and re-resolves the
 * patient through the tenant global scope on every request. A cross-tenant id
 * therefore 404s in mount() and in every action, exactly like the old
 * route-model-bound controllers did.
 *
 * @property-read Patient $patient  the #[Computed] accessor below, read as a property
 */
trait InteractsWithPatient
{
    #[Locked]
    public int $patientId;

    /** The tenant-scoped patient this panel belongs to (cached per request). */
    #[Computed]
    public function patient(): Patient
    {
        return Patient::findOrFail($this->patientId);
    }
}
