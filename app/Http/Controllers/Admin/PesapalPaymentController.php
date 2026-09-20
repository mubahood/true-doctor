<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Services\Gateway\GatewayPaymentService;
use App\Services\Gateway\PesapalGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pesapal payment flow for subscription checkout. Both entry points carry only
 * an OrderTrackingId with no trustworthy status of their own (Pesapal's
 * design, not an oversight — see PesapalGateway's docblock); both funnel into
 * the same idempotent, verify-first GatewayPaymentService::settle() the
 * Flutterwave flow uses.
 *
 * GatewayPaymentService is built by hand here, not injected — a contextual
 * binding keyed on THIS controller would not reach PaymentGateway anyway:
 * Laravel's `when()->needs()->give()` matches on whichever class is directly
 * being built at that moment, and here that's GatewayPaymentService (shared
 * with the Flutterwave invoice flow), not PesapalPaymentController. Building
 * it explicitly with an explicitly-resolved PesapalGateway sidesteps that
 * entirely and keeps the Flutterwave default binding untouched everywhere else.
 */
class PesapalPaymentController extends Controller
{
    private readonly GatewayPaymentService $service;

    public function __construct(PesapalGateway $gateway, BillingService $billing)
    {
        $this->service = new GatewayPaymentService($gateway, $billing);
    }

    /** Browser return from Pesapal. Public — the payer may not be signed in. */
    public function callback(Request $request)
    {
        $trackingId = (string) $request->query('OrderTrackingId', '');

        $result = $trackingId !== '' ? $this->service->settle($trackingId) : 'missing_tracking_id';

        return view('gateway.result', [
            'ok' => in_array($result, ['settled', 'already_settled'], true),
            'result' => $result,
        ]);
    }

    /**
     * Server-to-server IPN. Public; GET because the IPN URL is registered with
     * ipn_notification_type "GET" (PesapalGateway::ipnId). Must reply with the
     * exact acknowledgement shape below or Pesapal treats it as unhandled and
     * keeps retrying — see developer.pesapal.com's IPN documentation.
     */
    public function ipn(Request $request): JsonResponse
    {
        $trackingId = (string) $request->query('OrderTrackingId', '');
        $merchantRef = (string) $request->query('OrderMerchantReference', '');
        $notificationType = (string) $request->query('OrderNotificationType', 'IPNCHANGE');

        // "status" here acknowledges that WE processed the notification, not
        // whether the payment itself succeeded — a cleanly-determined failed
        // payment (not_successful) is still a 200; only a processing problem on
        // our end (an unrecognised reference, a mismatched amount/currency) is
        // a 500, so Pesapal knows to keep retrying it.
        $ok = false;
        if ($trackingId !== '') {
            $result = $this->service->settle($trackingId);
            $ok = in_array($result, ['settled', 'already_settled', 'not_successful'], true);
            Log::info('Pesapal IPN settled', ['tracking_id' => $trackingId, 'result' => $result]);
        }

        return response()->json([
            'orderNotificationType' => $notificationType,
            'orderTrackingId' => $trackingId,
            'orderMerchantReference' => $merchantRef,
            'status' => $ok ? 200 : 500,
        ]);
    }
}
