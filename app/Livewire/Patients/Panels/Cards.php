<?php

namespace App\Livewire\Patients\Panels;

use App\Http\Requests\CardIssueRequest;
use App\Http\Requests\CardTransactionRequest;
use App\Models\InsuranceProvider;
use App\Models\PatientCard;
use App\Services\CardService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * This patient's cards, and the money that has moved on them.
 *
 * WHERE THIS ENDS AND THE CARD PAGE BEGINS (docs/cards.md). This panel answers
 * "what cards does this person have, and what is on them" — issue one, top it
 * up, charge it. Everything about the CARD itself — its terms, who else may
 * spend on it, which insurer stands behind it — lives on the card's own page,
 * one click from every row.
 *
 * That split is deliberate rather than an omission: a control that exists in
 * two places is a control that will one day behave differently in the two, and
 * these are balances and credit limits. There is exactly one of each in the
 * system.
 *
 * The whole panel is gated on `manageCards` (plan D5): a balance and a credit
 * limit are never rendered for a role that may not act on them. Money moves
 * only through CardService (transaction + row lock + ledger); domain failures
 * (insufficient funds, expired card, closed period) surface as an inline error
 * on the amount field, never as a reload.
 *
 * @property-read Collection<int,PatientCard> $cards
 * @property-read Collection<int,InsuranceProvider> $insurers
 */
#[Lazy]
class Cards extends Component
{
    use AuthorizesRequests, InteractsWithPatient;

    // Issue slide-over
    public bool $showIssue = false;

    public bool $accepts_credit = false;

    public ?string $max_credit = null;

    public ?string $expiry = null;

    public ?int $insurance_provider_id = null;

    public ?string $member_no = null;

    // Credit / debit slide-over
    public bool $showTxn = false;

    #[Locked]
    public ?int $txnCardId = null;

    #[Locked]
    public string $txnType = 'credit';

    public ?string $amount = null;

    public ?string $description = null;

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;

        $this->authorize('manageCards', $this->patient);
    }

    /** @return Collection<int, PatientCard> */
    #[Computed]
    public function cards(): Collection
    {
        return $this->patient->cards()
            ->with(['provider', 'records' => fn ($q) => $q->with(['createdBy', 'patient'])])
            ->withCount(['holders' => fn ($q) => $q->where('status', \App\Enums\CardHolderStatus::Active)])
            ->get();
    }

    /**
     * The insurers a card may be issued against. Few, and only the live ones —
     * a card cannot be opened against a company the hospital has stopped
     * dealing with.
     *
     * @return Collection<int,InsuranceProvider>
     */
    #[Computed]
    public function insurers(): Collection
    {
        return InsuranceProvider::where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_credit_limit']);
    }

    // ── Issue ──────────────────────────────────────────────────

    public function openIssue(): void
    {
        $this->authorize('manageCards', $this->patient);

        $this->reset(['accepts_credit', 'max_credit', 'expiry', 'insurance_provider_id', 'member_no']);
        $this->resetErrorBag();
        $this->showIssue = true;
    }

    public function issue(CardService $service): void
    {
        $this->authorize('manageCards', $this->patient);

        $data = $this->validate(CardIssueRequest::rulesFor());
        $data['accepts_credit'] = $this->accepts_credit;
        $data['max_credit'] = $this->accepts_credit ? ($data['max_credit'] ?: 0) : 0;
        $data['expiry'] = $data['expiry'] ?: null;
        $data['insurance_provider_id'] = $this->insurance_provider_id;
        $data['member_no'] = $this->member_no;

        try {
            $card = $service->issue($this->patient, $data, Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('insurance_provider_id', $e->getMessage());

            return;
        }

        $this->showIssue = false;
        $this->reset(['accepts_credit', 'max_credit', 'expiry', 'insurance_provider_id', 'member_no']);
        unset($this->cards);

        $this->dispatch('toast', message: "Card issued — {$card->masked()}.", type: 'success');
        $this->dispatch('patient-updated');
    }

    // ── Credit / debit ─────────────────────────────────────────

    public function openTxn(int $cardId, string $type): void
    {
        $this->authorize('manageCards', $this->patient);

        $card = $this->resolveCard($cardId);

        $this->txnCardId = $card->id;
        $this->txnType = $type === 'debit' ? 'debit' : 'credit';
        $this->reset(['amount', 'description']);
        $this->resetErrorBag();
        $this->showTxn = true;
    }

    public function postTxn(CardService $service): void
    {
        $this->authorize('manageCards', $this->patient);

        $data = $this->validate(CardTransactionRequest::rulesFor());
        $card = $this->resolveCard((int) $this->txnCardId);
        $amount = CardTransactionRequest::normalise($data['amount']);
        $note = $data['description'] ?: null;

        try {
            // InsufficientFundsException / ClosedPeriodException / card-state
            // failures are all RuntimeExceptions raised by CardService.
            $this->txnType === 'debit'
                ? $service->debit($card, $amount, $note, Auth::id())
                : $service->credit($card, $amount, $note, Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->showTxn = false;
        $this->reset(['amount', 'description']);
        unset($this->cards);

        $this->dispatch('toast', message: $this->txnType === 'debit' ? 'Card charged.' : 'Card topped up.', type: 'success');
        $this->dispatch('patient-updated');
    }

    /** A card reached from this panel must belong to this patient (both tenant-scoped). */
    private function resolveCard(int $cardId): PatientCard
    {
        /** @var PatientCard */
        return $this->patient->cards()->whereKey($cardId)->firstOrFail();
    }

    public function render()
    {
        $this->authorize('manageCards', $this->patient);

        return view('livewire.patients.panels.cards');
    }
}
