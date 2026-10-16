<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AgendaService;
use App\Services\NextActionsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What this person should do next, and their next few days (docs/api/journeys.md). Everything is filtered by their own permissions. */
class MeController extends Controller
{
    public function nextActions(Request $request, NextActionsService $actions): JsonResponse
    {
        return ApiResponse::success([
            'items' => $actions->for($request->user()),
            'generated_at' => now()->toIso8601String(),
            'cache_seconds' => NextActionsService::TTL,
        ]);
    }

    public function agenda(Request $request, AgendaService $agenda): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:'.AgendaService::MAX_DAYS]]);
        $days = (int) ($data['days'] ?? 3);

        return ApiResponse::success([
            'items' => $agenda->for($request->user(), $days),
            'days' => $days,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
