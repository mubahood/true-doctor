<?php

namespace App\Livewire\Onboarding\Steps;

use App\Models\Hospital;
use App\Support\HospitalSettings;
use Illuminate\Support\Facades\Auth;

/**
 * Shared behaviour for the onboarding step components: resolve the hospital in
 * context, refuse anyone who cannot configure it, and tell the wizard when the
 * underlying data changed so it can re-evaluate progress and advance.
 */
trait InteractsWithSetup
{
    protected function hospital(): Hospital
    {
        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        return $hospital;
    }

    /** Only the owner/admin who can configure the hospital may run a setup step. */
    protected function authorizeSetup(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
    }

    /** Announce a completed step: the wizard re-reads the checklist and moves on. */
    protected function stepCompleted(string $message): void
    {
        $this->dispatch('toast', message: $message, type: 'success');
        $this->dispatch('onboarding-updated');
    }
}
