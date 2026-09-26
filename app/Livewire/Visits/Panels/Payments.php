<?php

namespace App\Livewire\Visits\Panels;

use App\Enums\PaymentMethod;
use App\Enums\VisitStage;
use App\Http\Requests\PaymentRequest;
use App\Models\Invoice;
use App\Models\PatientCard;
use App\Models\Payment;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * What has been paid against this visit's bill, and taking the next payment.
 *
 * The last link in the chain the whole model rests on: a visit has orders, an
 * order has items, the items are the bill, and this is what settles it.
 *
 * Each method needs something different, and the panel asks for exactly that:
 * a prepaid card needs a card, mobile money and a bank transfer need the
 * reference that proves them, insurance needs the authorisation. Those were
 * all supported by BillingService and none of them reachable from here — the
 * panel passed an empty options array, which made a card payment impossible
 * and a reference unrecordable.
 *
 * @property-read Invoice|null $invoice
 * @property-read Collection<int,Payment> $payments
 * @property-read Collection<int,PatientCard> $cards
 * @property-read list<PaymentMethod> $methods
 * @property-read bool $needsReference
 * @property-read string|null $cardBalance
 */
#[Lazy]
class Payments extends Component
{
    use AuthorizesRequests, InteractsWithVisit, ShowsTheNextStep;

    /** Settling the bill is what opens the gate out of Payment. */
    protected function ownsStage(): VisitStage
    {
        return VisitStage::Payment;
    }

    public bool $showTake = false;

    public string $method = 'cash';

    public ?string $amount = null;

    public ?int $patient_card_id = null;

    public ?string $reference = null;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
    }

    #[Computed]
    public function invoice(): ?Invoice
    {
        return Invoice::where('visit_id', $this->visitId)->latest('id')->first();
    }

    /** @return Collection<int,Payment> */
    #[Computed]
    public function payments(): Collection
    {
        $invoice = $this->invoice;

        return $invoice === null
            ? new Collection
            : $invoice->payments()->with('receivedBy')->latest('id')->get();
    }

    /**
     * The ways money can be taken at a counter.
     *
     * A gateway is not one of them. Flutterwave settles itself and is recorded
     * server-side once it has been verified — offering it here would let
     * somebody mark an invoice paid with money nobody has confirmed arrived.
     *
     * @return list<PaymentMethod>
     */
    #[Computed]
    public function methods(): array
    {
        return PaymentMethod::recordable();
    }

    /**
     * Every card this patient may spend on — their own, and any a family
     * member has put them on (docs/cards.md). Few, either way.
     *
     * @return Collection<int,PatientCard>
     */
    #[Computed]
    public function cards(): Collection
    {
        return PatientCard::spendableBy($this->visit->patient_id)
            ->orderByDesc('id')
            ->get();
    }

    /** What the chosen card has on it, so nobody offers what is not there. */
    #[Computed]
    public function cardBalance(): ?string
    {
        if ($this->patient_card_id === null) {
            return null;
        }

        $card = $this->cards->firstWhere('id', $this->patient_card_id);

        return $card === null ? null : (string) $card->balance;
    }

    /**
     * Whether this method is one whose proof lives outside the system.
     *
     * Cash is its own receipt. A transfer, a mobile-money payment or an
     * insurance settlement is somebody else's record, and without the
     * reference there is no way back to it when the figures are questioned.
     */
    #[Computed]
    public function needsReference(): bool
    {
        return PaymentMethod::tryFrom((string) $this->method)?->needsReference() ?? false;
    }

    public function updatedMethod(): void
    {
        $this->patient_card_id = $this->method === PaymentMethod::Card->value
            ? $this->cards->first(fn (PatientCard $card) => $card->status->canTransact() && ! $card->isExpired())?->id
            : null;
        $this->reference = null;
        $this->resetErrorBag();
        unset($this->needsReference, $this->cardBalance);
    }

    public function openTake(): void
    {
        abort_unless(Auth::user()?->can('billing.manage'), 403);

        $invoice = $this->invoice;
        abort_if($invoice === null, 403);

        $this->reset(['method', 'patient_card_id', 'reference']);
        $this->amount = (string) $invoice->balance;
        $this->resetErrorBag();
        unset($this->cards, $this->needsReference, $this->cardBalance);
        $this->showTake = true;
    }

    /** The common case is settling it, so that is one click. */
    public function payInFull(): void
    {
        $invoice = $this->invoice;

        $this->amount = $invoice === null ? '0' : (string) $invoice->balance;
    }

    public function take(BillingService $billing): void
    {
        abort_unless(Auth::user()?->can('billing.manage'), 403);

        $invoice = $this->invoice;
        if ($invoice === null) {
            return;
        }

        // PaymentRequest's rules (method, amount, reference), with this
        // panel's own card field.
        $rules = PaymentRequest::rulesFor();
        unset($rules['card_uuid']);
        $this->validate($rules + [
            'patient_card_id' => [$this->method === PaymentMethod::Card->value ? 'required' : 'nullable', 'integer'],
        ], PaymentRequest::messagesFor() + ['patient_card_id.required' => 'Choose the card to debit.']);

        $opts = ['reference' => ($this->reference ?? '') !== '' ? trim((string) $this->reference) : null];

        if ($this->method === PaymentMethod::Card->value) {
            // Scoped to this patient: a card belonging to somebody else is not
            // this invoice's to spend. BillingService checks it again.
            $opts['card'] = $this->cards->firstWhere('id', $this->patient_card_id);
        }

        try {
            $billing->recordPayment($invoice, PaymentMethod::from($this->method),
                (string) $this->amount, $opts, Auth::id());
        } catch (Throwable $e) {
            // Overpayment, a closed accounting period, a card with nothing on
            // it — all of them are sentences the reader should see.
            $this->addError('amount', $e instanceof RuntimeException ? $e->getMessage() : 'That payment could not be recorded.');

            return;
        }

        $this->showTake = false;
        $this->reset(['method', 'patient_card_id', 'reference']);
        $this->refresh();

        $this->dispatch('toast', message: 'Payment recorded.', type: 'success');
        $this->dispatch('visit-updated');
    }

    #[On('visit-updated')]
    public function refresh(): void
    {
        unset($this->visit, $this->invoice, $this->payments, $this->cards,
            $this->cardBalance, $this->needsReference, $this->nextStep);
    }

    public function render()
    {
        $this->authorize('view', $this->visit);

        return view('livewire.visits.panels.payments');
    }
}
