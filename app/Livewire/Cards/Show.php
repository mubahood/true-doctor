<?php

namespace App\Livewire\Cards;

use App\Enums\CardHolderRelationship;
use App\Enums\CardStatus;
use App\Http\Requests\CardTransactionRequest;
use App\Models\CardHolder;
use App\Models\CardRecord;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Services\CardService;
use App\Services\InsuranceLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * One card: what it is allowed to do, who may spend on it, and every movement
 * it has ever made (docs/cards.md).
 *
 * Everything money-facing goes through CardService or InsuranceLedgerService —
 * this component validates, authorises and reports, and writes nothing itself.
 * Domain failures surface inline on the field that caused them, never as a
 * reload, because the person is looking at a balance while they press the
 * button (house rule 12).
 *
 * @property-read PatientCard $card
 * @property-read Collection<int,CardHolder> $holders
 * @property-read array{balance:string,ledger:string,agrees:bool} $proof
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Locked]
    public string $uuid = '';

    // ── Money ────────────────────────────────────────────────────────────
    public bool $showTxn = false;

    #[Locked]
    public string $txnType = 'credit';

    public ?string $amount = null;

    public ?string $description = null;

    // ── Terms ────────────────────────────────────────────────────────────
    public bool $showTerms = false;

    public string $status = 'active';

    public bool $accepts_credit = false;

    public ?string $max_credit = null;

    public ?string $expiry = null;

    public ?int $insurance_provider_id = null;

    public ?string $member_no = null;

    // ── Holders ──────────────────────────────────────────────────────────
    public bool $showHolder = false;

    public ?int $holder_patient_id = null;

    public string $relationship = 'child';

    public int $formNonce = 0;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->authorize('view', $this->card);
    }

    #[Computed]
    public function card(): PatientCard
    {
        return PatientCard::with(['patient', 'provider', 'issuer'])
            ->where('uuid', $this->uuid)
            ->firstOrFail();
    }

    /** @return Collection<int,CardHolder> */
    #[Computed]
    public function holders(): Collection
    {
        return CardHolder::with(['patient', 'addedBy'])
            ->where('patient_card_id', $this->card->id)
            ->orderBy('status')
            ->orderBy('id')
            ->get();
    }

    /** @return LengthAwarePaginator<int,CardRecord> */
    #[Computed]
    public function records(): LengthAwarePaginator
    {
        return CardRecord::with(['patient', 'createdBy'])
            ->where('patient_card_id', $this->card->id)
            ->latest('created_at')
            ->latest('id')
            ->paginate(20);
    }

    /** @return Collection<int,InsuranceProvider> */
    #[Computed]
    public function insurers(): Collection
    {
        return InsuranceProvider::where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_credit_limit']);
    }

    /**
     * The balance against its own ledger. A card is money, and money that
     * cannot be proved is money nobody should trust.
     *
     * @return array{balance:string,ledger:string,agrees:bool}
     */
    #[Computed]
    public function proof(): array
    {
        return app(CardService::class)->reconcile($this->card);
    }

    // ── Money ────────────────────────────────────────────────────────────

    public function openTxn(string $type): void
    {
        $this->authorize('update', $this->card);
        $this->txnType = $type === 'debit' ? 'debit' : 'credit';
        $this->reset(['amount', 'description']);
        $this->resetErrorBag();
        $this->showTxn = true;
    }

    public function postTxn(CardService $cards): void
    {
        $this->authorize('update', $this->card);
        $data = $this->validate(CardTransactionRequest::rulesFor());
        $amount = CardTransactionRequest::normalise($data['amount']);

        try {
            $this->txnType === 'debit'
                ? $cards->debit($this->card, $amount, $data['description'] ?? null, Auth::id())
                : $cards->credit($this->card, $amount, $data['description'] ?? null, Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->showTxn = false;
        $this->refreshCard();
        $this->dispatch('toast', message: $this->txnType === 'debit' ? 'Card charged.' : 'Card topped up.', type: 'success');
    }

    // ── Terms ────────────────────────────────────────────────────────────

    public function openTerms(): void
    {
        $this->authorize('update', $this->card);

        $card = $this->card;
        $this->status = $card->status->value;
        $this->accepts_credit = $card->accepts_credit;
        $this->max_credit = $card->accepts_credit ? (string) $card->max_credit : null;
        $this->expiry = $card->expiry?->toDateString();
        $this->insurance_provider_id = $card->insurance_provider_id;
        $this->member_no = $card->member_no;
        $this->resetErrorBag();
        $this->showTerms = true;
    }

    public function saveTerms(CardService $cards): void
    {
        $this->authorize('update', $this->card);

        $this->validate([
            'status' => ['required', new \Illuminate\Validation\Rules\Enum(CardStatus::class)],
            'accepts_credit' => ['boolean'],
            'max_credit' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'expiry' => ['nullable', 'date'],
            'insurance_provider_id' => ['nullable', 'integer'],
            'member_no' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $cards->updateTerms($this->card, [
                'status' => $this->status,
                'accepts_credit' => $this->accepts_credit,
                'max_credit' => $this->max_credit ?? 0,
                'expiry' => $this->expiry,
                'insurance_provider_id' => $this->insurance_provider_id,
                'member_no' => $this->member_no,
            ], Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('max_credit', $e->getMessage());

            return;
        }

        $this->showTerms = false;
        $this->refreshCard();
        $this->dispatch('toast', message: 'Card updated.', type: 'success');
    }

    // ── Holders ──────────────────────────────────────────────────────────

    public function openHolder(): void
    {
        $this->authorize('update', $this->card);
        $this->reset(['holder_patient_id', 'relationship']);
        $this->formNonce++;
        $this->resetErrorBag();
        $this->showHolder = true;
    }

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if ($name === 'holder_patient_id') {
            $this->holder_patient_id = $id;
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if ($name === 'holder_patient_id') {
            $this->holder_patient_id = null;
        }
    }

    public function addHolder(CardService $cards): void
    {
        $this->authorize('update', $this->card);

        $this->validate([
            'holder_patient_id' => ['required', 'integer'],
            'relationship' => ['required', new \Illuminate\Validation\Rules\Enum(CardHolderRelationship::class)],
        ], [], ['holder_patient_id' => 'patient']);

        /** @var Patient|null $patient */
        $patient = Patient::find($this->holder_patient_id);

        if ($patient === null) {
            $this->addError('holder_patient_id', 'That patient is not one of this hospital’s.');

            return;
        }

        try {
            $cards->addHolder($this->card, $patient, CardHolderRelationship::from($this->relationship), Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('holder_patient_id', $e->getMessage());

            return;
        }

        $this->showHolder = false;
        $this->refreshCard();
        $this->dispatch('toast', message: $patient->fullName().' can now use this card.', type: 'success');
    }

    public function revokeHolder(int $holderId, CardService $cards): void
    {
        $this->authorize('update', $this->card);

        /** @var CardHolder $holder */
        $holder = CardHolder::where('patient_card_id', $this->card->id)->whereKey($holderId)->firstOrFail();

        $cards->revokeHolder($holder, Auth::id());

        $this->refreshCard();
        $this->dispatch('toast', message: 'Taken off the card.', type: 'success');
    }

    // ── The insurer clears it ────────────────────────────────────────────

    public function settle(InsuranceLedgerService $ledger): void
    {
        $this->authorize('update', $this->card);

        if (Auth::user()?->can('insurance.manage') !== true) {
            $this->dispatch('toast', message: 'Only someone who manages insurance can spend an insurer’s float.', type: 'error');

            return;
        }

        try {
            $entry = $ledger->settleCard($this->card, null, Auth::id());
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->refreshCard();

        $this->dispatch(
            'toast',
            message: $entry === null
                ? 'Nothing to clear — the float is empty or the card is square.'
                : \App\Support\HospitalSettings::money((string) $entry->amount).' cleared from the insurer’s float.',
            type: $entry === null ? 'info' : 'success',
        );
    }

    private function refreshCard(): void
    {
        unset($this->card, $this->holders, $this->records, $this->proof);
        $this->resetPage();
    }

    public function render()
    {
        $this->authorize('view', $this->card);

        return view('livewire.cards.show', [
            'statuses' => CardStatus::options(),
            'relationships' => CardHolderRelationship::options(),
        ])->title('Card '.$this->card->masked());
    }
}
