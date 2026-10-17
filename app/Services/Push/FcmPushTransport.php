<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, HTTP v1 (PUSH_DRIVER=fcm).
 *
 * Authenticates as the Firebase project's service account: a short JWT signed
 * with its private key is exchanged for an access token (cached until a minute
 * before it expires), which sends one message per device. Only the public
 * title and body travel (PushChannel never hands over private text); the rest
 * goes as string data the app routes on.
 *
 * A token FCM says is no longer registered (the app was uninstalled or its
 * token rotated) is cleared on the device row, so it is not tried again; the
 * install keeps working and registers its next token.
 */
final class FcmPushTransport implements PushTransport
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function send(string $token, string $platform, array $payload): bool
    {
        $account = $this->account();
        if ($account === null || $token === '') {
            return false;
        }

        $access = $this->accessToken($account);
        if ($access === null) {
            return false;
        }

        $data = array_map(fn ($v) => is_scalar($v) ? (string) $v : (string) json_encode($v), array_filter([
            'category' => $payload['category'] ?? null,
            'route' => $payload['route'] ?? null,
            'entity' => $payload['entity'] ?? null,
            'entity_uuid' => $payload['entity_uuid'] ?? null,
            'dedupe_key' => $payload['dedupe_key'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $high = ($payload['priority'] ?? 'normal') === 'high';
        $message = [
            'token' => $token,
            'notification' => array_filter(['title' => $payload['title'] ?? null, 'body' => $payload['body'] ?? null]),
            'data' => $data === [] ? new \stdClass : $data,
            'android' => array_filter([
                'priority' => $high ? 'HIGH' : 'NORMAL',
                'collapse_key' => $payload['dedupe_key'] ?? null,
                'notification' => array_filter(['channel_id' => $payload['category'] ?? null, 'tag' => $payload['dedupe_key'] ?? null]),
            ]),
            'apns' => ['headers' => ['apns-priority' => $high ? '10' : '5']],
        ];

        try {
            $response = Http::withToken($access)->acceptJson()->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send", ['message' => $message]);
        } catch (ConnectionException $e) {
            Log::warning('push.fcm: no answer', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        $status = (string) ($response->json('error.details.0.errorCode') ?? $response->json('error.status') ?? '');
        if (in_array($status, ['UNREGISTERED', 'NOT_FOUND'], true) || $response->status() === 404) {
            DeviceToken::query()->withoutGlobalScopes()->where('token', $token)->update(['token' => null]);
        } elseif ($response->status() === 401) {
            Cache::forget($this->cacheKey($account));
        }
        Log::warning('push.fcm: refused', ['status' => $response->status(), 'error' => $status]);

        return false;
    }

    public function delivers(): bool
    {
        return $this->account() !== null;
    }

    /**
     * The service account: FCM_CREDENTIALS is the path to its JSON file, or
     * the JSON itself (base64 allowed, for hosts that mangle newlines).
     *
     * @return array{project_id:string,client_email:string,private_key:string}|null
     */
    private function account(): ?array
    {
        $raw = trim((string) config('push.fcm.credentials', ''));
        if ($raw === '') {
            return null;
        }
        if (! str_starts_with($raw, '{')) {
            $raw = is_file($raw) ? (string) file_get_contents($raw) : (string) base64_decode($raw, true);
        }
        $json = json_decode($raw, true);

        return is_array($json) && isset($json['project_id'], $json['client_email'], $json['private_key'])
            ? ['project_id' => (string) $json['project_id'], 'client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key']]
            : null;
    }

    /** @param array{project_id:string,client_email:string,private_key:string} $account */
    private function accessToken(array $account): ?string
    {
        $cached = Cache::get($this->cacheKey($account));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $segment = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $unsigned = $segment(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$segment([
            'iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600,
        ]);
        $signature = '';
        if (! openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('push.fcm: the service account key could not sign');

            return null;
        }
        $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $token = (string) $response->json('access_token', '');
        if (! $response->successful() || $token === '') {
            Log::warning('push.fcm: no access token', ['status' => $response->status()]);

            return null;
        }
        Cache::put($this->cacheKey($account), $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    /** @param array{project_id:string,client_email:string,private_key:string} $account */
    private function cacheKey(array $account): string
    {
        return 'push.fcm.token.'.sha1($account['client_email']);
    }
}
