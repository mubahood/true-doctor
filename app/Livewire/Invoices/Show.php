<?php

namespace App\Livewire\Invoices;

use App\Enums\PaymentMethod;
use App\Http\Requests\InvoiceCreditRequest;
use App\Http\Requests\PaymentRequest;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\PatientCard;
use App\Models\User;
use App\Services\BillingService;
use App\Services\InvoiceCreditService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Invoice detail (Detail shape, plan §4.4) — replaces admin/invoices/show.blade.php,
 * InvoiceController@show and PaymentController@store.
 *
 * Money is pessimistic (house rule 12): "Record payment" disables itself while
 * the round-trip is in flight and only the server's answer updates the totals.
 * Every failure BillingService can raise (overpayment, insufficient card funds,
 * closed accounting period, non-payable invoice) is a RuntimeException subclass
 * and surfaces as an inline error under the amount — never a bare \Throwable.
 *
 * Two deliberate non-Livewire escapes, both sanctioned by the house rules: the
 * PDF/receipt links (downloads) and the Flutterwave button (a gateway redirect
 * needs a real POST + CSRF to leave the SPA).
 *
 * Credit notes and refunds (docs/refunds.md) are asked for here and, by
 * someone holding billing.refund, approved or rejected here too.
 *
 * @property-read Invoice $invoice
 * @property-read Collection<int,PatientCard> $cards
 * @property-read Collection<int,InvoiceCredit> $credits
 * @property-read array<string,string> $creditFigures
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $invoiceId;

    public bool $showPayment = false;

    public string $method = 'cash';

    public string $amount = '';

    public ?string $reference = null;

    public ?string $card_uuid = null;

    // ── Credit note / refund dialog ──────────────────────────────────────

    public bool $showCredit = false;

    /** @var array{kind:string,amount:string,reason:string,method:string,reference:?string,card_uuid:?string} */
    public array $credit = ['kind' => 'credit_note', 'amount' => '', 'reason' => '', 'method' => 'cash', 'reference' => null, 'card_uuid' => null];

    public bool $showReject = false;

    #[Locked]
    public ?int $rejectId = null;

    public string $rejectNote = '';

    public function mount(string $invoice): void
    {
        // Tenant-scoped resolution: another hospital's uuid is a 404, not a 403.
        $model = Invoice::where('uuid', $invoice)->firstOrFail();
        $this->authorize('view', $model);

        $this->invoiceId = $model->id;
        $this->amount = (string) $model->balance;
    }

    #[Computed]
    public function invoice(): Invoice
    {
        return Invoice::with(['patient', 'items', 'payments.receivedBy', 'visit'])
            ->findOrFail($this->invoiceId);
    }

    /**
     * Prepaid cards of this invoice's patient — computed, so the query only runs
     * when the payment slide-over renders the card picker (plan finding L).
     *
     * @return Collection<int,PatientCard>
     */
    #[Computed]
    public function cards(): Collection
    {
        return $this->invoice->patient?->cards()->orderByDesc('id')->get() ?? collect();
    }

    public function openPayment(): void
    {
        $this->authorize('pay', $this->invoice);

        $this->resetErrorBag();
        $this->method = PaymentMethod::Cash->value;
        $this->amount = (string) $this->invoice->balance;
        $this->reference = null;
        $this->card_uuid = null;
        $this->showPayment = true;
    }

    public function recordPayment(BillingService $billing): void
    {
        $invoice = $this->invoice;
        $this->authorize('pay', $invoice);

        $data = $this->validate(PaymentRequest::rulesFor(), PaymentRequest::messagesFor());

        $opts = ['reference' => $data['reference'] ?? null];

        if (filled($data['card_uuid'] ?? null)) {
            $card = PatientCard::where('uuid', $data['card_uuid'])->first();
            if ($card === null) {
                $this->addError('card_uuid', 'That prepaid card could not be found.');

                return;
            }
            $opts['card'] = $card;
        }

        try {
            $billing->recordPayment(
                $invoice,
                PaymentMethod::from($data['method']),
                number_format((float) $data['amount'], 2, '.', ''),
                $opts,
                Auth::id(),
            );
        } catch (RuntimeException $e) {
            // OverpaymentException, InsufficientFundsException, ClosedPeriodException
            // and the service's own guards all extend RuntimeException.
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->forgetMoney();
        $this->showPayment = false;
        $this->dispatch('toast', message: 'Payment recorded.', type: 'success');
    }

    // ── Credit notes and refunds ─────────────────────────────────────────

    /** @return Collection<int,InvoiceCredit> */
    #[Computed]
    public function credits(): Collection
    {
        return $this->invoice->credits()->with(['requestedBy:id,name', 'decidedBy:id,name'])->get();
    }

    /** @return array<string,string> */
    #[Computed]
    public function creditFigures(): array
    {
        return app(InvoiceCreditService::class)->figures($this->invoice);
    }

    public function openCredit(string $kind): void
    {
        $this->authorize('credit', $this->invoice);
        $kind = $kind === 'refund' ? 'refund' : 'credit_note';

        $this->resetErrorBag();
        $f = $this->creditFigures;
        $this->credit = [
            'kind' => $kind,
            'amount' => $kind === 'refund' ? $f['can_refund'] : '',
            'reason' => '',
            'method' => PaymentMethod::Cash->value,
            'reference' => null,
            'card_uuid' => null,
        ];
        $this->showCredit = true;
    }

    public function saveCredit(InvoiceCreditService $service): void
    {
        $invoice = $this->invoice;
        $this->authorize('credit', $invoice);
        $kind = $this->credit['kind'] === 'refund' ? 'refund' : 'credit_note';

        $data = $this->validate(InvoiceCreditRequest::rulesFor($kind, 'credit.'), InvoiceCreditRequest::messagesFor('credit.'))['credit'];
        /** @var User $user */
        $user = Auth::user();
        $amount = \App\Support\HospitalSettings::decimal($data['amount'], 2);

        try {
            $made = $kind === 'refund'
                ? $service->requestRefund(
                    $invoice, PaymentMethod::from($data['method']), $amount, $data['reason'], $user,
                    $data['reference'] ?? null,
                    filled($data['card_uuid'] ?? null) ? PatientCard::where('uuid', $data['card_uuid'])->first() : null,
                )
                : $service->requestCreditNote($invoice, $amount, $data['reason'], $user);
        } catch (RuntimeException $e) {
            $this->addError('credit.amount', $e->getMessage());

            return;
        }

        $this->forgetMoney();
        $this->showCredit = false;
        $this->dispatch('toast', type: 'success', message: $made->isPending()
            ? $made->kind->label().' sent for approval.'
            : $made->kind->label().' '.$made->number.' approved.');
    }

    public function approveCredit(int $id, InvoiceCreditService $service): void
    {
        $credit = InvoiceCredit::where('invoice_id', $this->invoiceId)->findOrFail($id);
        $this->authorize('approve', $credit);

        try {
            /** @var User $user */
            $user = Auth::user();
            $credit = $service->approve($credit, $user);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->forgetMoney();
        $this->dispatch('toast', type: 'success', message: $credit->kind->label().' '.$credit->number.' approved.');
    }

    public function openReject(int $id): void
    {
        $credit = InvoiceCredit::where('invoice_id', $this->invoiceId)->findOrFail($id);
        $this->authorize('approve', $credit);

        $this->resetErrorBag();
        $this->rejectId = $credit->id;
        $this->rejectNote = '';
        $this->showReject = true;
    }

    public function rejectCredit(InvoiceCreditService $service): void
    {
        $credit = InvoiceCredit::where('invoice_id', $this->invoiceId)->findOrFail((int) $this->rejectId);
        $this->authorize('approve', $credit);
        $this->validate(['rejectNote' => ['required', 'string', 'min:3', 'max:500']], ['rejectNote.required' => 'Say why it is rejected.']);

        try {
            /** @var User $user */
            $user = Auth::user();
            $service->reject($credit, $this->rejectNote, $user);
        } catch (RuntimeException $e) {
            $this->addError('rejectNote', $e->getMessage());

            return;
        }

        $this->forgetMoney();
        $this->showReject = false;
        $this->dispatch('toast', type: 'success', message: 'Rejected.');
    }

    private function forgetMoney(): void
    {
        unset($this->invoice, $this->credits, $this->creditFigures, $this->cards);
    }

    public function render()
    {
        $invoice = $this->invoice;
        $this->authorize('view', $invoice);

        return view('livewire.invoices.show', [
            'invoice' => $invoice,
            'methods' => PaymentMethod::options(),
            'refundMethods' => array_intersect_key(PaymentMethod::options(), array_flip(InvoiceCreditService::REFUND_METHODS)),
        ])->title($invoice->invoice_no);
    }
}
