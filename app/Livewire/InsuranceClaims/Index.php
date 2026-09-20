<?php

namespace App\Livewire\InsuranceClaims;

use App\Enums\ClaimStatus;
use App\Http\Requests\InsuranceClaimRequest;
use App\Livewire\Concerns\WithTable;
use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Services\InsuranceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Insurance claims list — status filter + search + an AJAX slide-over to raise a
 * claim. Creation (numbering, lifecycle) runs through InsuranceService; this
 * component authorizes, validates (InsuranceClaimRequest::rulesFor) and reports.
 * Patient and invoice are picked through <livewire:ui.select-search> so neither
 * table is queried in full (C4/D3/L1).
 *
 * @property-read InsuranceClaim|null $peeked
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, WithTable;

    /** Modal fields a <livewire:ui.select-search> child may set. */
    private const PICKERS = ['patient_id', 'invoice_id', 'insurance_provider_id'];

    #[Url(history: true)]
    public string $status = '';

    // ── New-claim modal state ──────────────────────────────────
    public bool $showForm = false;

    public ?int $patient_id = null;

    public ?int $insurance_provider_id = null;

    public ?int $invoice_id = null;

    public ?string $amount = null;

    public ?string $notes = null;

    public function mount(): void
    {
        $this->authorize('viewAny', InsuranceClaim::class);
    }

    protected function resetsPage(): array
    {
        return ['status'];
    }

    public function create(): void
    {
        $this->authorize('manage', InsuranceClaim::class);
        $this->reset(['patient_id', 'insurance_provider_id', 'invoice_id', 'amount', 'notes']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    /** A <livewire:ui.select-search> child reports a pick. */
    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if (in_array($name, self::PICKERS, true)) {
            $this->{$name} = null;
        }
    }

    /** Shared with InsuranceClaimRequest — one source of truth (§4.5, C10). */
    protected function rules(): array
    {
        return InsuranceClaimRequest::rulesFor();
    }

    public function save(InsuranceService $service): void
    {
        $this->authorize('manage', InsuranceClaim::class);

        $data = $this->validate();
        if (($data['notes'] ?? '') === '') {
            $data['notes'] = null;
        }

        try {
            $claim = $service->createClaim($data, auth()->id());
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'The claim could not be created. Please try again.', type: 'error');

            return;
        }

        // The dialog closes and the list is where it was. Redirecting to the
        // new record threw whoever opened it out of the list they were working
        // down, and the commonest thing after creating one is creating the
        // next — it is in the table behind the dialog, and the toast names it.
        $this->showForm = false;
        $this->resetPage();
        $this->dispatch('toast', message: "Claim {$claim->claim_no} created.", type: 'success');
    }

    /** @return Collection<int, InsuranceProvider> */
    #[Computed]
    public function providers(): Collection
    {
        return InsuranceProvider::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statuses(): array
    {
        return ClaimStatus::options();
    }

    // ── Reading one without leaving the list ─────────────────────────────

    public bool $showPeek = false;

    public ?int $peekId = null;

    /**
     * One claim, over the list rather than instead of it.
     *
     * Working a claims list means asking the same question of row after row —
     * what is it against, how much of the invoice does it cover, where has it
     * got to — and a page per answer loses the reader's place every time.
     */
    public function peek(int $id): void
    {
        $claim = InsuranceClaim::findOrFail($id);
        $this->authorize('view', $claim);

        $this->peekId = $claim->id;
        unset($this->peeked);
        $this->showPeek = true;
    }

    public function closePeek(): void
    {
        $this->reset(['showPeek', 'peekId']);
    }

    #[Computed]
    public function peeked(): ?InsuranceClaim
    {
        return $this->peekId === null
            ? null
            : InsuranceClaim::with(['patient', 'provider', 'invoice', 'resolvedBy'])->find($this->peekId);
    }

    public function render()
    {
        $this->authorize('viewAny', InsuranceClaim::class);

        $claims = InsuranceClaim::with(['patient', 'provider'])
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', function (Builder $query) {
                $term = $this->search;
                $query->where(function (Builder $qq) use ($term) {
                    $qq->where('claim_no', 'like', "%{$term}%")
                        ->orWhereHas('patient', fn (Builder $p) => $p->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('patient_no', 'like', "%{$term}%"));
                });
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.insurance-claims.index', ['rows' => $claims])->title('Insurance claims');
    }
}
