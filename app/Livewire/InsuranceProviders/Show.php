<?php

namespace App\Livewire\InsuranceProviders;

use App\Models\InsuranceProvider;
use App\Models\InsuranceTransaction;
use App\Models\PatientCard;
use App\Services\InsuranceLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * One insurer: what it has deposited, what its members owe, and what they spent
 * it on (docs/cards.md).
 *
 * The directory entry in InsuranceProviders\Index is setup — a name and a phone
 * number, edited twice a year. This is the working page behind it, and the two
 * do not overlap: nothing editable here is editable there.
 *
 * Money moves only through InsuranceLedgerService, which locks the provider row
 * and writes both ledgers in one transaction.
 *
 * @property-read InsuranceProvider $provider
 * @property-read array $usage
 * @property-read array{float:string,ledger:string,agrees:bool} $proof
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Locked]
    public int $providerId = 0;

    // ── The report window ────────────────────────────────────────────────
    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    // ── Take a deposit ───────────────────────────────────────────────────
    public bool $showDeposit = false;

    public ?string $amount = null;

    public ?string $reference = null;

    public ?string $notes = null;

    // ── Correct the float ────────────────────────────────────────────────
    public bool $showAdjust = false;

    /** 'add' or 'remove' — a signed box is a box people get wrong. */
    public string $direction = 'remove';

    public ?string $adjustAmount = null;

    public ?string $reason = null;

    public function mount(InsuranceProvider $insuranceProvider): void
    {
        $this->providerId = $insuranceProvider->id;
        $this->authorize('viewAny', InsuranceProvider::class);

        $this->from = $this->from ?: Carbon::now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: Carbon::now()->toDateString();
    }

    #[Computed]
    public function provider(): InsuranceProvider
    {
        return InsuranceProvider::findOrFail($this->providerId);
    }

    /** May this person move the insurer's money, or only read it? */
    #[Computed]
    public function mayManage(): bool
    {
        return Auth::user()?->can('insurance.manage') === true;
    }

    /** @return Collection<int,PatientCard> */
    #[Computed]
    public function cards(): Collection
    {
        return PatientCard::with('patient')
            ->where('insurance_provider_id', $this->providerId)
            // In debt first: that is what the page is opened to deal with.
            ->orderByRaw('CASE WHEN balance < 0 THEN 0 ELSE 1 END')
            ->orderBy('balance')
            ->limit(200)
            ->get();
    }

    /** @return LengthAwarePaginator<int,InsuranceTransaction> */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return InsuranceTransaction::with(['card.patient', 'createdBy'])
            ->where('insurance_provider_id', $this->providerId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function usage(): array
    {
        return app(InsuranceLedgerService::class)->usage(
            $this->provider,
            Carbon::parse($this->from ?: Carbon::now()->startOfMonth()->toDateString()),
            Carbon::parse($this->to ?: Carbon::now()->toDateString()),
        );
    }

    /** @return array{float:string,ledger:string,agrees:bool} */
    #[Computed]
    public function proof(): array
    {
        return app(InsuranceLedgerService::class)->reconcile($this->provider);
    }

    // ── Deposits ─────────────────────────────────────────────────────────

    public function openDeposit(): void
    {
        $this->authorize('update', $this->provider);
        $this->reset(['amount', 'reference', 'notes']);
        $this->resetErrorBag();
        $this->showDeposit = true;
    }

    public function saveDeposit(InsuranceLedgerService $ledger): void
    {
        $this->authorize('update', $this->provider);

        $this->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $ledger->deposit($this->provider, $this->money($this->amount), $this->reference, $this->notes, Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->showDeposit = false;
        $this->refreshLedger();
        $this->dispatch('toast', message: 'Deposit recorded.', type: 'success');
    }

    // ── Corrections ──────────────────────────────────────────────────────

    public function openAdjust(): void
    {
        $this->authorize('update', $this->provider);
        $this->reset(['adjustAmount', 'reason', 'direction']);
        $this->resetErrorBag();
        $this->showAdjust = true;
    }

    public function saveAdjust(InsuranceLedgerService $ledger): void
    {
        $this->authorize('update', $this->provider);

        $this->validate([
            'direction' => ['required', 'in:add,remove'],
            'adjustAmount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['adjustAmount' => 'amount']);

        $signed = $this->direction === 'remove'
            ? bcmul($this->money($this->adjustAmount), '-1', 2)
            : $this->money($this->adjustAmount);

        try {
            $ledger->adjust($this->provider, $signed, (string) $this->reason, Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('adjustAmount', $e->getMessage());

            return;
        }

        $this->showAdjust = false;
        $this->refreshLedger();
        $this->dispatch('toast', message: 'Float corrected.', type: 'success');
    }

    // ── Clearing the members ─────────────────────────────────────────────

    public function settleAll(InsuranceLedgerService $ledger): void
    {
        $this->authorize('update', $this->provider);

        try {
            $result = $ledger->settleAll($this->provider, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->refreshLedger();

        if ($result['settled'] === 0) {
            $this->dispatch('toast', message: 'Nothing to clear.', type: 'info');

            return;
        }

        $money = fn (string $a) => \App\Support\HospitalSettings::money($a);

        $this->dispatch(
            'toast',
            message: bccomp($result['unpaid'], '0', 2) > 0
                ? "{$result['settled']} cleared, {$money($result['paid'])} paid — {$money($result['unpaid'])} still owing, the float ran out."
                : "{$result['settled']} cleared, {$money($result['paid'])} paid.",
            type: bccomp($result['unpaid'], '0', 2) > 0 ? 'info' : 'success',
        );
    }

    public function settleOne(int $cardId, InsuranceLedgerService $ledger): void
    {
        $this->authorize('update', $this->provider);

        /** @var PatientCard $card */
        $card = PatientCard::where('insurance_provider_id', $this->providerId)->whereKey($cardId)->firstOrFail();

        try {
            $entry = $ledger->settleCard($card, null, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->refreshLedger();

        $this->dispatch(
            'toast',
            message: $entry === null
                ? 'Nothing to clear on that card.'
                : \App\Support\HospitalSettings::money((string) $entry->amount).' cleared.',
            type: $entry === null ? 'info' : 'success',
        );
    }

    public function updatedFrom(): void
    {
        unset($this->usage);
    }

    public function updatedTo(): void
    {
        unset($this->usage);
    }

    private function refreshLedger(): void
    {
        unset($this->provider, $this->cards, $this->entries, $this->usage, $this->proof);
        $this->resetPage();
    }

    private function money(?string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    public function render()
    {
        $this->authorize('viewAny', InsuranceProvider::class);

        return view('livewire.insurance-providers.show')->title($this->provider->name);
    }
}
