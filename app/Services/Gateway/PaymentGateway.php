<?php

namespace App\Services\Gateway;

/**
 * A payment gateway adapter (HMS_PLAN.md §16). Concrete providers (Flutterwave,
 * …) sit behind this interface so the rest of the app never talks to a vendor
 * SDK directly and providers are swappable/mocked. Money is only ever moved
 * after a server-side verify() — never trusting a client redirect's status.
 */
interface PaymentGateway
{
    /**
     * Create a hosted payment session for an amount.
     *
     * @param  array{tx_ref:string,amount:string,currency:string,redirect_url:string,customer:array<string,string|null>,meta?:array<string,mixed>}  $data
     */
    public function initialize(array $data): GatewayCharge;

    /** Server-side verify a transaction by the provider's own id. */
    public function verify(string $providerTransactionId): GatewayVerification;

    /** True if an inbound webhook's signature header authenticates it. */
    public function verifyWebhookSignature(?string $signatureHeader): bool;
}
