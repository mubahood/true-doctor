<?php

namespace App\Support;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;

/**
 * The one API response envelope (HMS_PLAN.md §3.D constraint D — "keep the
 * manifest pattern" sibling: a consistent shape everywhere, not one ad hoc
 * per controller). Every API response, success or error, has this shape:
 *
 *   {"success": bool, "code": string, "message": string|null,
 *    "data": mixed, "errors": object|null}
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => 'ok',
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    /**
     * A success whose list is a slice or carries a tally: the envelope plus a
     * `meta` block, for endpoints that are not paginated (`total`, `truncated`).
     *
     * @param  array<string,mixed>  $meta
     */
    public static function withMeta(mixed $data, array $meta, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => 'ok',
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
            'errors' => null,
        ], $status);
    }

    /**
     * A paginated list in the standard envelope: items under `data`, page info
     * under `meta` (so the envelope shape stays consistent across endpoints).
     */
    /** @param  array<string,mixed>  $extraMeta  more for the meta block (a list's tally, say) */
    public static function paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, mixed $items = null, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => 'ok',
            'message' => null,
            'data' => $items ?? $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ] + $extraMeta,
            'errors' => null,
        ]);
    }

    /**
     * `retryable` and `retry_after` (seconds) are optional additions to the
     * error envelope: present only when the server has a view on whether the
     * same request may succeed later, so old clients see the same shape as before.
     *
     * @param  array<string, mixed>|null  $errors
     */
    public static function error(
        ApiErrorCode $code,
        string $message,
        int $status,
        ?array $errors = null,
        mixed $data = null,
        ?bool $retryable = null,
        ?int $retryAfter = null,
    ): JsonResponse {
        $body = [
            'success' => false,
            'code' => $code->value,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ];

        if ($retryable !== null) {
            $body['retryable'] = $retryable;
        }

        if ($retryAfter !== null) {
            $body['retry_after'] = $retryAfter;
        }

        return response()->json($body, $status);
    }
}
