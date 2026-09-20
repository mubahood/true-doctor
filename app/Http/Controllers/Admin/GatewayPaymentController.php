<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Gateway\GatewayPaymentService;
use App\Services\Gateway\PaymentGateway;
use App\Support\HospitalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Flutterwave payment flow (HMS_PLAN.md §16). start() opens a hosted session and
 * redirects the payer to Flutterwave; callback() (browser return) and webhook()
 * (server-to-server) both funnel into the idempotent, verify-first
 * GatewayPaymentService::settle — money is only ever recorded after verification.
 */
class GatewayPaymentController extends Controller
{
    public function __construct(private readonly GatewayPaymentService $service) {}

    public function start(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('pay', $invoice);

        if (! $invoice->status->isPayable() || bccomp((string) $invoice->balance, '0', 2) <= 0) {
            return back()->with('error', 'This invoice has nothing outstanding to pay.');
        }

        $invoice->loadMissing('patient');
        [, $charge] = $this->service->initialize(
            $invoice,
            (string) $invoice->balance,
            route('gateway.callback'),
            [
                'email' => $invoice->patient?->email ?: 'billing@'.parse_url(config('app.url'), PHP_URL_HOST),
                'name' => $invoice->patient?->full_name,
                'phone' => $invoice->patient?->phone_1,
                'hospital' => app(HospitalSettings::class)->hospital()?->name,
            ],
        );

        if (! $charge->ok || $charge->link === null) {
            return back()->with('error', $charge->error ?? 'Could not start the online payment.');
        }

        return redirect()->away($charge->link);
    }

    /** Browser return from Flutterwave. Public — the payer may not be signed in. */
    public function callback(Request $request)
    {
        $transactionId = (string) $request->query('transaction_id', '');
        $flwStatus = (string) $request->query('status', '');

        $result = 'cancelled';
        if ($transactionId !== '' && in_array($flwStatus, ['successful', 'completed'], true)) {
            $result = $this->service->settle($transactionId);
        }

        return view('gateway.result', [
            'ok' => in_array($result, ['settled', 'already_settled'], true),
            'result' => $result,
        ]);
    }

    /** Server-to-server webhook. Public, CSRF-exempt; authenticity via verif-hash. */
    public function webhook(Request $request, PaymentGateway $gateway)
    {
        if (! $gateway->verifyWebhookSignature($request->header('verif-hash'))) {
            return response()->json(['status' => 'invalid signature'], 401);
        }

        $transactionId = (string) data_get($request->all(), 'data.id', '');
        if ($transactionId !== '') {
            $result = $this->service->settle($transactionId);
            Log::info('Flutterwave webhook settled', ['tx' => $transactionId, 'result' => $result]);
        }

        // Always 200 so Flutterwave doesn't retry a handled event.
        return response()->json(['status' => 'ok']);
    }
}
