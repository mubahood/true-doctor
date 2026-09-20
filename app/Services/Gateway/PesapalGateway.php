<?php

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pesapal API 3.0 adapter (used for subscription checkout — see the
 * contextual bindings in AppServiceProvider). Three things make this API
 * shaped differently from Flutterwave's:
 *
 *  - Auth is a short-lived (~5 min) bearer token fetched via RequestToken,
 *    not a static secret key — cached here for 4 minutes to stay inside that
 *    window with margin.
 *  - Before submitting an order you must have already registered a callback
 *    URL to get an `notification_id` (IPN id) — registered once and cached
 *    long-lived; safe to re-register (idempotent enough in practice) if the
 *    cache is ever cold.
 *  - There is no inbound webhook signature to check: initialize() gets back
 *    an `order_tracking_id`, and both the browser callback and the IPN call
 *    carry it back to us bearing no trustworthy status of their own — the
 *    only trustworthy status comes from calling GetTransactionStatus
 *    ourselves, authenticated with our own bearer token. verify() IS that
 *    call, so verifyWebhookSignature() has nothing further to check.
 */
class PesapalGateway implements PaymentGateway
{
    /** @var array<string,mixed> */
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('services.pesapal');
    }

    public function initialize(array $data): GatewayCharge
    {
        $token = $this->token();
        if ($token === null) {
            return new GatewayCharge(false, $data['tx_ref'], null, 'Payment gateway unreachable.');
        }

        $ipnId = $this->ipnId($token);
        if ($ipnId === null) {
            return new GatewayCharge(false, $data['tx_ref'], null, 'Payment gateway not configured (IPN registration failed).');
        }

        $customer = $data['customer'];
        [$firstName, $lastName] = $this->splitName($customer['name'] ?? null);

        $payload = [
            'id' => $data['tx_ref'],
            'currency' => $data['currency'],
            'amount' => (float) $data['amount'],
            'description' => Str::limit((string) ($data['meta']['description'] ?? 'Payment'), 100, ''),
            'callback_url' => $data['redirect_url'],
            'notification_id' => $ipnId,
            'billing_address' => array_filter([
                'email_address' => $customer['email'] ?? null,
                'phone_number' => $customer['phone'] ?? null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'country_code' => 'UG',
            ]),
        ];

        try {
            $res = $this->client($token)->post('/api/Transactions/SubmitOrderRequest', $payload);
        } catch (\Throwable $e) {
            Log::warning('Pesapal initialize failed', ['error' => $e->getMessage()]);

            return new GatewayCharge(false, $data['tx_ref'], null, 'Gateway unreachable.');
        }

        $body = $res->json();
        if ($res->successful() && ($body['status'] ?? null) === '200' && filled($body['redirect_url'] ?? null)) {
            return new GatewayCharge(true, $data['tx_ref'], $body['redirect_url']);
        }

        return new GatewayCharge(false, $data['tx_ref'], null, data_get($body, 'error.message') ?? data_get($body, 'message') ?? 'Could not start payment.');
    }

    /** $providerTransactionId is Pesapal's order_tracking_id (from the callback/IPN query string). */
    public function verify(string $providerTransactionId): GatewayVerification
    {
        $token = $this->token();
        if ($token === null) {
            return new GatewayVerification(false, '', null, '0.00', '', null, 'Gateway unreachable.');
        }

        try {
            $res = $this->client($token)->get('/api/Transactions/GetTransactionStatus', [
                'orderTrackingId' => $providerTransactionId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Pesapal verify failed', ['error' => $e->getMessage()]);

            return new GatewayVerification(false, '', null, '0.00', '', null, 'Gateway unreachable.');
        }

        $body = $res->json() ?? [];
        // status_code: 0 INVALID, 1 COMPLETED, 2 FAILED, 3 REVERSED.
        $successful = $res->successful() && (int) ($body['status_code'] ?? -1) === 1;

        return new GatewayVerification(
            successful: $successful,
            reference: (string) ($body['merchant_reference'] ?? ''),
            providerRef: $providerTransactionId,
            amount: \App\Support\HospitalSettings::decimal($body['amount'] ?? 0, 2),
            currency: (string) ($body['currency'] ?? ''),
            paymentType: $body['payment_method'] ?? null,
            error: $successful ? null : ((string) ($body['payment_status_description'] ?? data_get($body, 'error.message') ?? 'Not successful.')),
        );
    }

    /**
     * Nothing to check here — see the class docblock. The real trust boundary
     * is verify()'s own authenticated call to Pesapal, made unconditionally by
     * GatewayPaymentService::settle() regardless of what an inbound call claims.
     */
    public function verifyWebhookSignature(?string $signatureHeader): bool
    {
        return true;
    }

    /** Bearer token, cached just under Pesapal's ~5 minute expiry. */
    private function token(): ?string
    {
        return Cache::remember('pesapal:token:'.md5((string) $this->cfg['consumer_key']), 240, function () {
            try {
                $res = Http::baseUrl($this->baseUrl())->timeout((int) $this->cfg['timeout'])->acceptJson()->asJson()
                    ->post('/api/Auth/RequestToken', [
                        'consumer_key' => $this->cfg['consumer_key'],
                        'consumer_secret' => $this->cfg['consumer_secret'],
                    ]);
            } catch (\Throwable $e) {
                Log::warning('Pesapal auth failed', ['error' => $e->getMessage()]);

                return null;
            }

            $token = $res->json('token');

            if (! $res->successful() || ! is_string($token) || $token === '') {
                Log::warning('Pesapal auth rejected', ['body' => $res->json()]);
                Cache::forget('pesapal:token:'.md5((string) $this->cfg['consumer_key']));

                return null;
            }

            return $token;
        });
    }

    /**
     * The notification_id SubmitOrderRequest requires, from registering our IPN
     * URL once. Cached for a month; re-registers itself if the cache is cold.
     */
    private function ipnId(string $token): ?string
    {
        $ipnUrl = (string) ($this->cfg['ipn_url'] ?? '');
        if ($ipnUrl === '') {
            Log::warning('Pesapal IPN URL is not configured (services.pesapal.ipn_url).');

            return null;
        }

        return Cache::remember('pesapal:ipn_id:'.md5($ipnUrl), 60 * 60 * 24 * 30, function () use ($token, $ipnUrl) {
            try {
                $res = $this->client($token)->post('/api/URLSetup/RegisterIPN', [
                    'url' => $ipnUrl,
                    'ipn_notification_type' => 'GET',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Pesapal IPN registration failed', ['error' => $e->getMessage()]);

                return null;
            }

            $id = $res->json('ipn_id');

            if (! $res->successful() || ! is_string($id) || $id === '') {
                Log::warning('Pesapal IPN registration rejected', ['body' => $res->json()]);

                return null;
            }

            return $id;
        });
    }

    /** @return array{0:?string,1:?string} */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [$parts[0], $parts[1] ?? null];
    }

    private function baseUrl(): string
    {
        $url = $this->cfg['environment'] === 'production' ? $this->cfg['production_url'] : $this->cfg['sandbox_url'];

        return rtrim((string) $url, '/');
    }

    private function client(string $token): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout((int) $this->cfg['timeout'])
            ->withToken($token)
            ->acceptJson()
            ->asJson();
    }
}
