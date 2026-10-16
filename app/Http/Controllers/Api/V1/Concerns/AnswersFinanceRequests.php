<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What every finance endpoint does the way the web does: the same gate as the
 * Livewire pages (a permission, and a hospital to keep books of), and a
 * business-rule refusal (closed period, empty card, illegal move) as a 422 in
 * the envelope, on the field it belongs to.
 */
trait AnswersFinanceRequests
{
    /** The web's gate (KeepsBooks): the permission, and a hospital to keep the books of. */
    protected function authorizeBooks(string $ability = 'finance.view'): void
    {
        abort_unless(request()->user()?->can($ability), 403);
        abort_if(app(CurrentHospital::class)->id() === null, 404);
    }

    /** A refusal from a service, shaped like a validation error. */
    protected function refuse(\Throwable $e, ?string $field = null): JsonResponse
    {
        return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, $field === null ? null : [$field => [$e->getMessage()]]);
    }

    /** The page size, restricted to the web's choices. */
    protected function perPage(Request $request): int
    {
        $n = (int) $request->query('per_page', 20);

        return in_array($n, [10, 20, 50, 100], true) ? $n : 20;
    }

    protected function date(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
