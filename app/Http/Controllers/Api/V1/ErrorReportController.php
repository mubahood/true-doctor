<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use App\Support\Errors\ErrorTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/diagnostics/errors - the app sends the crashes it recorded
 * (already scrubbed on the phone; scrubbed again here) so they appear beside
 * the server's own in the error list. Up to 20 at a time.
 */
class ErrorReportController extends Controller
{
    public function __invoke(Request $request, ErrorTracker $tracker): JsonResponse
    {
        $data = $request->validate([
            'errors' => ['required', 'array', 'min:1', 'max:20'],
            'errors.*.kind' => ['required', 'string', 'max:120'],
            'errors.*.message' => ['nullable', 'string', 'max:2000'],
            'errors.*.where' => ['nullable', 'string', 'max:500'],
            'errors.*.app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $refs = [];
        foreach ($data['errors'] as $report) {
            $refs[] = $tracker->captureFromApp($report, $request->user()?->getKey(), app(CurrentHospital::class)->id());
        }

        return ApiResponse::success(['refs' => $refs], 'Thank you. These help us fix what went wrong.');
    }
}
