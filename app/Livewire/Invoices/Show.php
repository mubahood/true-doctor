<?php

namespace App\Livewire\Invoices;

use App\Enums\PaymentMethod;
use App\Http\Requests\PaymentRequest;
use App\Models\Invoice;
use App\Models\PatientCard;
use App\Services\BillingService;
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
 * @property-read Invoice $invoice
 * @property-read Collection<int,PatientCard> $cards
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

        unset($this->invoice, $this->cards);
        $this->showPayment = false;
        $this->dispatch('toast', message: 'Payment recorded.', type: 'success');
    }

    public function render()
    {
        $invoice = $this->invoice;
        $this->authorize('view', $invoice);

        return view('livewire.invoices.show', [
            'invoice' => $invoice,
            'methods' => PaymentMethod::options(),
        ])->title($invoice->invoice_no);
    }
}
