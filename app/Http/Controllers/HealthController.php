<?php

namespace App\Http\Controllers;

use App\Support\Health\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * GET /health, for an uptime monitor: the overall status and each check's
 * status, nothing more (the messages, which can name paths and settings, stay
 * in `td:check`). 503 when something has failed, so a monitor that only reads
 * the status code still raises the alarm. Cached for 30 seconds.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = cache()->remember('health:public', 30, function () use ($health) {
            $checks = $health->run();

            return [
                'status' => HealthCheck::overall($checks),
                'checks' => array_combine(array_column($checks, 'key'), array_column($checks, 'status')),
                'checked_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($result, $result['status'] === HealthCheck::FAIL ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
