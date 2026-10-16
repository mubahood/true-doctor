<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What every list endpoint shares: one page-size rule and the delta protocol.
 *
 * ── Page size ───────────────────────────────────────────────────────────────
 * `perPage()` reads `?per_page=`, falls back to the controller's own default
 * (20 for most lists, 25/50 where a list always had more) and never exceeds
 * `PER_PAGE_MAX` (100; advertised as `capabilities.limits.per_page_max`).
 *
 * ── Delta ───────────────────────────────────────────────────────────────────
 * `?updated_since=<ISO 8601>` returns only rows whose `updated_at` is later
 * than that instant; `&include_deleted=1` also returns soft-deleted rows, which
 * carry `deleted_at` (a soft delete moves `updated_at`, so a deletion is a
 * change like any other). The page is then ordered `updated_at, id` ascending so
 * the last page's `meta.next_since` (the largest `updated_at` returned) is a
 * safe place to resume. `meta.server_time` is the server's clock.
 *
 * `updated_at` has one-second resolution and the filter is strict (`>`): a row
 * written in the same second as `next_since`, after the client read, would be
 * missed. Clients resume from `next_since` minus two seconds; re-delivering a
 * row is harmless because a pull is an upsert.
 *
 * The tenant scope is untouched: `withTrashed()` removes only the soft-delete
 * scope, never the hospital one (HospitalScope).
 */
trait PagesAndDeltas
{
    public const PER_PAGE_MAX = 100;

    protected function perPage(Request $request, int $default = 20): int
    {
        return max(1, min((int) $request->query('per_page', $default), self::PER_PAGE_MAX));
    }

    /**
     * Narrow a list query to the delta the request asked for (no-op without one).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyDelta(Builder $query, Request $request): Builder
    {
        $since = $this->updatedSince($request);
        $model = $query->getModel();
        $table = $model->getTable();

        if ($request->boolean('include_deleted') && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        if ($since !== null) {
            $query->where("{$table}.updated_at", '>', $since)
                ->reorder()
                ->orderBy("{$table}.updated_at")
                ->orderBy("{$table}.id");
        }

        return $query;
    }

    /** The instant `updated_since` names, or null. A value that is not a date is a 422. */
    protected function updatedSince(Request $request): ?Carbon
    {
        $raw = $request->query('updated_since');

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $raw)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'updated_since' => ['updated_since must be an ISO 8601 date-time, e.g. 2026-10-16T08:30:00Z.'],
            ]);
        }
    }

    /**
     * A paginated list as the envelope: each row is its Resource plus
     * `updated_at` and `deleted_at` (a Resource's own keys win), and the meta
     * gains `server_time` and `next_since`.
     *
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @param  array<string,mixed>  $extraMeta
     */
    protected function deltaPage(Request $request, LengthAwarePaginator $page, string $resource, array $extraMeta = []): JsonResponse
    {
        $models = array_values($page->items());
        $rows = $resource::collection($models)->resolve($request);

        $max = null;
        foreach ($models as $i => $model) {
            $updated = $model->getAttribute('updated_at');
            $deleted = $model->getAttribute('deleted_at');

            $rows[$i] = ($rows[$i] ?? []) + [
                'updated_at' => $updated?->toIso8601String(),
                'deleted_at' => $deleted?->toIso8601String(),
            ];

            if ($updated !== null && ($max === null || $updated->gt($max))) {
                $max = $updated;
            }
        }

        return ApiResponse::paginated($page, $rows, $extraMeta + [
            'server_time' => now()->toIso8601String(),
            // Nothing returned: the caller's own `updated_since` stands.
            'next_since' => $max?->toIso8601String() ?? $this->updatedSince($request)?->toIso8601String(),
        ]);
    }
}
