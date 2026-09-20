<?php

namespace App\Services\Gateway;

use App\Enums\PaymentMethod;
use App\Models\GatewayLog;
use App\Models\Invoice;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates gateway payments on top of a PaymentGateway adapter. initialize()
 * opens a hosted session and logs it; settle() is the ONE place money moves — it
 * verifies server-side, then records a Payment through BillingService. It is
 * idempotent (a gateway_log row is locked and marked successful once), so the
 * redirect callback and the webhook can both call it without double-crediting.
 */
class GatewayPaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly BillingService $billing,
    ) {}

    /** @param array<string,string|null> $customer @return array{0:GatewayLog,1:GatewayCharge} */
    public function initialize(Invoice $invoice, string $amount, string $redirectUrl, array $customer): array
    {
        $txRef = 'TD-'.$invoice->uuid.'-'.bin2hex(random_bytes(5));

        $log = GatewayLog::create([
            'invoice_id' => $invoice->id,
            'provider' => 'flutterwave',
            'tx_ref' => $txRef,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => $invoice->currency,
        ]);

        $charge = $this->gateway->initialize([
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'redirect_url' => $redirectUrl,
            'customer' => $customer,
            'meta' => ['invoice' => $invoice->uuid],
        ]);

        if (! $charge->ok) {
            $log->update(['status' => 'failed', 'meta' => ['error' => $charge->error]]);
        }

        return [$log, $charge];
    }

    /**
     * Verify a gateway transaction and, if genuinely successful, record the
     * payment exactly once. Safe to call from both the browser callback and the
     * webhook. Returns a short status string for logging.
     */
    public function settle(string $providerTransactionId): string
    {
        $v = $this->gateway->verify($providerTransactionId);
        if (! $v->successful || $v->reference === '') {
            return 'not_successful';
        }

        return DB::transaction(function () use ($v) {
            /** @var GatewayLog|null $log */
            $log = GatewayLog::withoutGlobalScopes()->where('tx_ref', $v->reference)->lockForUpdate()->first();
            if ($log === null) {
                return 'unknown_reference';
            }
            if ($log->isSettled()) {
                return 'already_settled';   // idempotent — webhook + callback race
            }

            // Guard against a spoofed/misconfigured amount or currency.
            if (strtoupper($v->currency) !== strtoupper($log->currency)) {
                $log->update(['status' => 'failed', 'verified' => true, 'meta' => ['reason' => 'currency_mismatch']]);

                return 'currency_mismatch';
            }
            if (bccomp($v->amount, (string) $log->amount, 2) < 0) {
                $log->update(['status' => 'failed', 'verified' => true, 'meta' => ['reason' => 'amount_short', 'paid' => $v->amount]]);

                return 'amount_short';
            }

            // Tenant context so writes auto-fill hospital_id (no HTTP session in a webhook).
            app(CurrentHospital::class)->set($log->hospital_id);

            $paymentId = null;

            if ($log->invoice_id !== null) {
                // Invoice payment.
                $invoice = Invoice::find($log->invoice_id);
                if ($invoice === null) {
                    $log->update(['status' => 'failed', 'verified' => true, 'meta' => ['reason' => 'no_invoice']]);

                    return 'no_invoice';
                }
                if ($invoice->status->isPayable()) {
                    // Cap at the outstanding balance (a concurrent cash payment may have reduced it).
                    $toRecord = bccomp((string) $log->amount, (string) $invoice->balance, 2) > 0
                        ? (string) $invoice->balance
                        : (string) $log->amount;

                    if (bccomp($toRecord, '0', 2) > 0) {
                        $payment = $this->billing->recordPayment($invoice, PaymentMethod::Flutterwave, $toRecord, ['reference' => $log->tx_ref]);
                        $paymentId = $payment->id;
                    }
                }
            } elseif (($log->meta['purpose'] ?? null) === 'subscription') {
                // Subscription activation — convert a trial to a paid period.
                app(\App\Services\SubscriptionCheckoutService::class)->activate($log);
            }

            $log->update([
                'status' => 'successful',
                'verified' => true,
                'provider_ref' => $v->providerRef,
                'payment_type' => $v->paymentType,
                'payment_id' => $paymentId,
            ]);

            return 'settled';
        });
    }
}
