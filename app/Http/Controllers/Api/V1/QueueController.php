<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Support\ApiResponse;
use App\Support\CheckInQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/queue — today's check-in queue, as the web board shows it.
 *
 * Same lanes, same order, same wait clocks and thresholds (CheckInQueue), so
 * a desk running the app and a desk running the web see one queue.
 */
class QueueController extends Controller
{
    public function __invoke(Request $request, CheckInQueue $queue): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $doctor = $request->filled('doctor') ? $request->integer('doctor') : null;
        $lanes = $queue->lanes($queue->rows($doctor));

        $out = [];
        foreach ($lanes as $key => $lane) {
            $out[$key] = [
                'title' => $lane['title'],
                'note' => $lane['note'],
                'rows' => $lane['rows']->map(function (Appointment $a) use ($queue) {
                    $waited = $queue->waitedMinutes($a);
                    $late = $queue->lateMinutes($a);

                    return (new AppointmentResource($a))->toArray(request()) + [
                        'waited_minutes' => $waited,
                        'waited_clock' => $waited === null ? null : $queue->clock($waited),
                        'wait_tone' => $queue->waitTone($waited),
                        'late_minutes' => $late,
                    ];
                })->values()->all(),
            ];
        }

        return ApiResponse::success([
            'lanes' => $out,
            'tally' => $queue->tally($lanes, $doctor),
            'thresholds' => ['warn' => CheckInQueue::WAIT_WARN, 'bad' => CheckInQueue::WAIT_BAD],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
