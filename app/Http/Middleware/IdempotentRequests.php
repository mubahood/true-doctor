<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes API writes safe to retry (docs/api/idempotency.md).
 *
 * A client that never saw the answer (timeout after the server committed)
 * resends the same write with the same `Idempotency-Key`; the stored answer is
 * replayed and the controller does not run a second time.
 */
class IdempotentRequests
{
    public const TTL_HOURS = 24;

    /** Answers that are not final: the client may fix and resend under the same key. */
    private const NOT_STORED = [401, 403, 408, 422, 423, 429];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        // Money endpoints: an installed app must say which attempt this is, or a
        // retry after a lost answer could charge twice. Web and Field Mode (no
        // X-App-Version) are untouched. Runs after authentication, so a
        // signed-out caller still gets 401.
        if (($key === null || $key === '') && $request->user() !== null && $this->keyIsRequired($request)) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                'This request moves money or stock, so it needs an Idempotency-Key header (a fresh UUID per attempt; reuse it when retrying).',
                422,
                ['Idempotency-Key' => ['The Idempotency-Key header is required.']],
            );
        }

        if ($key === null || $key === '' || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $request->is('api/v1/sync/push', 'api/v1/auth/login')) {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/', $key)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, 'The Idempotency-Key header must be 1-100 characters: letters, digits and . _ : -', 422, ['idempotency_key' => ['Invalid Idempotency-Key.']]);
        }

        $hash = $this->fingerprint($request);

        $lock = Cache::lock('idempotency:'.$user->getKey().':'.$key, 120);
        if (! $lock->get()) {
            return ApiResponse::error(ApiErrorCode::RequestInProgress, 'This request is still being processed. Wait a moment and check the record.', 409);
        }

        try {
            $stored = DB::table('idempotency_keys')->where('user_id', $user->getKey())->where('key', $key)->first();
            if ($stored && $stored->created_at < now()->subHours(self::TTL_HOURS)->toDateTimeString()) {
                DB::table('idempotency_keys')->where('id', $stored->id)->delete();
                $stored = null;
            }

            if ($stored) {
                if (! hash_equals($stored->request_hash, $hash)) {
                    return ApiResponse::error(ApiErrorCode::IdempotencyKeyReused, 'This Idempotency-Key was already used for a different request.', 422);
                }

                return response($stored->body, (int) $stored->status, ['Content-Type' => 'application/json', 'Idempotent-Replay' => 'true']);
            }

            $response = $next($request);

            if ($this->isFinal($response)) {
                try {
                    DB::table('idempotency_keys')->insert([
                        'user_id' => $user->getKey(),
                        'key' => $key,
                        'request_hash' => $hash,
                        'status' => $response->getStatusCode(),
                        'body' => (string) $response->getContent(),
                        'created_at' => now(),
                    ]);
                } catch (QueryException) {
                    // Lost a race to store the same key: the first answer stands.
                }
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Routes whose writes move money or stock: taking a payment, a card's
     * top-up / charge / settlement, receiving, adjusting or writing off stock,
     * dispensing. Required only when the request carries `X-App-Version`.
     */
    public const KEY_REQUIRED_ROUTES = [
        'api.dental.status',
        'api.invoices.pay',
        'api.cards.transact',
        'api.cards.settle',
        'api.stock-items.receive',
        'api.stock-items.adjust',
        'api.stock-items.write-off',
        'api.dispensations.store',
        'api.finance.transactions.store',
        'api.finance.journal.store',
        'api.insurance-providers.deposit',
        'api.insurance-providers.adjust',
        'api.insurance-providers.settle',
        'api.insurance-providers.cards.settle',
        'api.patients.cards.store',
        'api.cards.holders.store',
        'api.purchase-orders.store',
        'api.purchase-orders.lines.store',
        'api.purchase-orders.receive',
        'api.purchase-orders.returns',
        // A refund pays money out; a credit note moves what is owed (docs/refunds.md).
        'api.invoices.refunds',
        'api.invoices.credit-notes',
    ];

    private function keyIsRequired(Request $request): bool
    {
        return trim((string) $request->header('X-App-Version', '')) !== ''
            && in_array($request->route()?->getName(), self::KEY_REQUIRED_ROUTES, true);
    }

    /**
     * An answer marked `Cache-Control: no-store` carries a secret (new recovery
     * codes, a two-step secret) and is never written to `idempotency_keys`,
     * where it would sit in clear for a day and in every backup. Such writes
     * are safe to run again: a retry makes a fresh secret, and the newest one
     * shown is the one that works.
     */
    private function isFinal(Response $response): bool
    {
        $status = $response->getStatusCode();

        return ($status >= 200 && $status < 300 || $status >= 400 && $status < 500)
            && ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')
            && ! in_array($status, self::NOT_STORED, true)
            && ! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            && ! $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            && str_contains((string) $response->headers->get('Content-Type'), 'json');
    }

    /** Method + path + query + body; for uploads, the file names and sizes instead of the file bytes. */
    private function fingerprint(Request $request): string
    {
        $parts = [$request->method(), $request->path(), $this->canonical($request->query())];

        if ($request->allFiles() !== []) {
            $files = [];
            $uploads = $request->allFiles();
            array_walk_recursive($uploads, function ($file, $name) use (&$files) {
                $files[] = $name.':'.$file->getClientOriginalName().':'.$file->getSize();
            });
            sort($files);
            $parts[] = $this->canonical($request->except(array_keys($request->allFiles())));
            $parts[] = implode('|', $files);
        } else {
            $parts[] = $this->canonical($request->all());
        }

        // A body with a password in it is fingerprinted with a keyed hash, so the
        // stored fingerprint cannot be used to guess the password offline.
        $secret = $request->hasAny(['password', 'current_password']);

        return $secret
            ? hash_hmac('sha256', implode("\n", $parts), (string) config('app.key'))
            : hash('sha256', implode("\n", $parts));
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort) {
            if (is_array($v)) {
                $v = array_map($sort, $v);
                if (! array_is_list($v)) {
                    ksort($v);
                }
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
