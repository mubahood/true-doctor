<?php

namespace App\Livewire\InsuranceClaims;

use App\Enums\ClaimStatus;
use App\Models\InsuranceClaim;
use App\Services\InsuranceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Insurance claim detail (Detail shape, plan §4.4) — replaces
 * admin/insurance-claims/show.blade.php and InsuranceClaimController@show/@transition.
 *
 * The lifecycle is ClaimStatus's machine, driven through InsuranceService. One
 * of those moves is money: reaching Paid records an insurance payment on the
 * linked invoice, so that button is pessimistic (disabled for the whole
 * round-trip, nothing updates until the server answers) and carries wire:confirm
 * — house rule 12. Rejections and cancellations carry a reason.
 *
 * @property-read InsuranceClaim $claim
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $claimId;

    /** Reason recorded on a rejection / cancellation. */
    public ?string $note = null;

    public function mount(string $insuranceClaim): void
    {
        // Tenant-scoped resolution: another hospital's uuid is a 404, not a 403.
        $model = InsuranceClaim::where('uuid', $insuranceClaim)->firstOrFail();
        $this->authorize('view', $model);

        $this->claimId = $model->id;
    }

    #[Computed]
    public function claim(): InsuranceClaim
    {
        return InsuranceClaim::with(['patient', 'provider', 'invoice'])->findOrFail($this->claimId);
    }

    /** Advance the claim through the ClaimStatus machine. */
    public function transition(string $status, InsuranceService $service): void
    {
        $this->authorize('manage', InsuranceClaim::class);

        $to = ClaimStatus::tryFrom($status);
        if ($to === null) {
            $this->dispatch('toast', message: 'Unknown claim status.', type: 'error');

            return;
        }

        $data = $this->validate(['note' => ['nullable', 'string', 'max:255']]);
        $note = ($data['note'] ?? null) ?: null;

        try {
            // Illegal moves, overpayment and closed-period guards are all
            // RuntimeExceptions raised by InsuranceService / BillingService.
            $service->transition($this->claim, $to, Auth::id(), $note);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->note = null;
        unset($this->claim);
        $this->dispatch('toast', message: "Claim moved to {$to->label()}.", type: 'success');
    }

    public function render()
    {
        $claim = $this->claim;
        $this->authorize('view', $claim);

        return view('livewire.insurance-claims.show', ['claim' => $claim])->title($claim->claim_no);
    }
}
