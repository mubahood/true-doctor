<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What this person should be reminded of soon: the phone plans its local reminders from it. docs/api/notify.md. */
class ReminderController extends Controller
{
    private const MAX_DAYS = 14;

    public function upcoming(Request $request, ReminderService $reminders): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->query('from')) : CarbonImmutable::now();
        $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->query('to')) : $from->addDays(7);

        if ($to->lt($from)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'The end of the range is before its start.']);
        }
        $truncated = $to->gt($from->addDays(self::MAX_DAYS));
        if ($truncated) {
            $to = $from->addDays(self::MAX_DAYS);
        }

        $items = $reminders->upcoming($request->user(), $from, $to);

        return response()->json([
            'success' => true, 'code' => 'ok', 'message' => null,
            'data' => $items,
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'truncated' => $truncated,
                'server_time' => now()->toIso8601String(),
                'timezone' => $request->user()->hospital?->timezone,
            ],
            'errors' => null,
        ]);
    }
}
