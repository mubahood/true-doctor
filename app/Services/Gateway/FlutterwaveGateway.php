<?php

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flutterwave v3 adapter. All secrets come from config('services.flutterwave')
 * (env only — C11). initialize() creates a Standard hosted-payment link;
 * verify() confirms a transaction server-side; verifyWebhookSignature() checks
 * the `verif-hash` header against our configured secret hash.
 */
class FlutterwaveGateway implements PaymentGateway
{
    /** @var array<string,mixed> */
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('services.flutterwave');
    }

    public function initialize(array $data): GatewayCharge
    {
        $payload = [
            'tx_ref' => $data['tx_ref'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'redirect_url' => $data['redirect_url'],
            'payment_options' => $this->cfg['payment_options'],
            'customer' => array_filter([
                'email' => $data['customer']['email'] ?? 'patient@example.com',
                'name' => $data['customer']['name'] ?? null,
                'phonenumber' => $data['customer']['phone'] ?? null,
            ]),
            'customizations' => ['title' => $data['customer']['hospital'] ?? config('app.name')],
            'meta' => $data['meta'] ?? [],
        ];

        try {
            $res = $this->client()->post('/v3/payments', $payload);
        } catch (\Throwable $e) {
            Log::warning('Flutterwave initialize failed', ['error' => $e->getMessage()]);

            return new GatewayCharge(false, $data['tx_ref'], null, 'Gateway unreachable.');
        }

        $body = $res->json();
        if (($body['status'] ?? null) === 'success' && isset($body['data']['link'])) {
            return new GatewayCharge(true, $data['tx_ref'], $body['data']['link']);
        }

        return new GatewayCharge(false, $data['tx_ref'], null, $body['message'] ?? 'Could not start payment.');
    }

    public function verify(string $providerTransactionId): GatewayVerification
    {
        try {
            $res = $this->client()->get("/v3/transactions/{$providerTransactionId}/verify");
        } catch (\Throwable $e) {
            Log::warning('Flutterwave verify failed', ['error' => $e->getMessage()]);

            return new GatewayVerification(false, '', null, '0.00', '', null, 'Gateway unreachable.');
        }

        $d = $res->json('data') ?? [];
        $successful = ($res->json('status') === 'success') && (($d['status'] ?? null) === 'successful');

        return new GatewayVerification(
            successful: $successful,
            reference: (string) ($d['tx_ref'] ?? ''),
            providerRef: isset($d['id']) ? (string) $d['id'] : null,
            amount: \App\Support\HospitalSettings::decimal($d['amount'] ?? 0, 2),
            currency: (string) ($d['currency'] ?? ''),
            paymentType: $d['payment_type'] ?? null,
            error: $successful ? null : ($d['processor_response'] ?? 'Not successful.'),
        );
    }

    public function verifyWebhookSignature(?string $signatureHeader): bool
    {
        $expected = (string) ($this->cfg['secret_hash'] ?? '');

        return $expected !== '' && is_string($signatureHeader) && hash_equals($expected, $signatureHeader);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->cfg['base_url'], '/'))
            ->timeout((int) $this->cfg['timeout'])
            ->withToken((string) $this->cfg['secret_key'])
            ->acceptJson()
            ->asJson();
    }
}
