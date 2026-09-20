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
     * A paginated list in the standard envelope: items under `data`, page info
     * under `meta` (so the envelope shape stays consistent across endpoints).
     */
    public static function paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, mixed $items = null): JsonResponse
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
            ],
            'errors' => null,
        ]);
    }

    /** @param array<string, mixed>|null $errors */
    public static function error(
        ApiErrorCode $code,
        string $message,
        int $status,
        ?array $errors = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'code' => $code->value,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
