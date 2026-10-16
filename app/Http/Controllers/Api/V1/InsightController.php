<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Insights\InsightEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/insights - what is worth this person's attention right now,
 * ranked, built only from what their permissions and practice allow
 * (App\Support\Insights). Cached per user for 60 seconds.
 */
class InsightController extends Controller
{
    public function __invoke(Request $request, InsightEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.InsightEngine::MAX_LIMIT],
        ]);

        $items = $engine->for($request->user(), (int) ($data['limit'] ?? InsightEngine::DEFAULT_LIMIT));

        return ApiResponse::success([
            'insights' => $items,
            'generated_at' => now()->toIso8601String(),
            'cache_seconds' => InsightEngine::TTL,
        ]);
    }
}
