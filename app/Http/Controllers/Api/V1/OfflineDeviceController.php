<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\SyncOperation;
use App\Models\SyncSession;
use App\Services\Sync\DeviceService;
use App\Services\Sync\PullService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Offline devices — the web's Offline devices page (manage-users): which
 * machines hold a copy of this hospital's records, and the power to block one.
 * Never the payload of anything: the operations ledger stores a hash, not a
 * record, and only its diagnostics are shown here.
 *
 * `readiness` is the other page, Offline readiness: any signed-in user asking
 * "am I ready to work without a connection?" about their own machines.
 */
class OfflineDeviceController extends Controller
{
    public function __construct(private readonly DeviceService $devices) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manage', Device::class);

        $state = in_array($request->query('state'), ['active', 'revoked', 'stale'], true) ? (string) $request->query('state') : '';
        $rows = $this->devices->query($state, trim((string) $request->query('q', '')))
            ->paginate(max(1, min((int) $request->query('per_page', 20), 100)));

        return ApiResponse::paginated($rows, DeviceResource::collection($rows->items()), ['figures' => $this->devices->figures()]);
    }

    public function show(Device $device): JsonResponse
    {
        $this->authorize('manage', Device::class);

        $device->load(['user', 'revokedBy']);

        return ApiResponse::success(array_merge((new DeviceResource($device))->resolve(), [
            // Columns chosen so the payload cannot be selected by accident.
            'recent_operations' => SyncOperation::where('device_id', $device->id)->latest('id')->limit(12)
                ->get(['id', 'operation_id', 'entity', 'entity_uuid', 'operation', 'status', 'reason_code', 'message', 'processed_at', 'created_at'])
                ->map(fn (SyncOperation $o) => [
                    'operation_id' => $o->operation_id,
                    'entity' => $o->entity,
                    'entity_uuid' => $o->entity_uuid,
                    'operation' => $o->operation,
                    'status' => $o->status,
                    'reason_code' => $o->reason_code,
                    'message' => $o->message,
                    'processed_at' => $o->processed_at?->toIso8601String(),
                    'created_at' => $o->created_at?->toIso8601String(),
                ])->values(),
            'recent_sessions' => SyncSession::where('device_id', $device->id)->latest('id')->limit(6)->get()
                ->map(fn (SyncSession $s) => [
                    'kind' => $s->kind,
                    'operation_count' => $s->operation_count,
                    'accepted_count' => $s->accepted_count,
                    'rejected_count' => $s->rejected_count,
                    'conflict_count' => $s->conflict_count,
                    'duration_ms' => $s->duration_ms,
                    'created_at' => $s->created_at?->toIso8601String(),
                ])->values(),
        ]));
    }

    /** Block a device. A reason is required: the device shows it to whoever next opens it. */
    public function revoke(Request $request, Device $device): JsonResponse
    {
        $this->authorize('manage', Device::class);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [
            'reason.required' => 'Say why. The device shows this to whoever next opens it.',
        ]);

        $this->devices->revoke($device, $data['reason'], $request->user());

        return ApiResponse::success(new DeviceResource($device->fresh(['user', 'revokedBy'])),
            $device->label.' is blocked. Its copy is cleared the next time it reaches the server.');
    }

    /** Let a device back in. */
    public function restore(Request $request, Device $device): JsonResponse
    {
        $this->authorize('manage', Device::class);

        $this->devices->restore($device, $request->user());

        return ApiResponse::success(new DeviceResource($device->fresh(['user', 'revokedBy'])), $device->label.' may work offline again.');
    }

    /**
     * What this user's device would receive (so the size of the job is known
     * before starting it on a phone tether) and the machines registered to
     * them. Everything else on the web page is measured in the browser.
     */
    public function readiness(Request $request, PullService $pull): JsonResponse
    {
        $available = $pull->availableFor($request->user());

        return ApiResponse::success([
            'enabled' => (bool) config('offline.enabled', true),
            'available' => $available,
            'total' => array_sum($available),
            'windows' => [
                'patient_days' => (int) config('offline.window.patient_days', PullService::PATIENT_WINDOW_DAYS),
                'visit_days' => (int) config('offline.window.visit_days', PullService::VISIT_WINDOW_DAYS),
            ],
            'devices' => Device::query()->where('user_id', $request->user()->id)->orderByDesc('last_seen_at')->get()
                ->map(fn (Device $d) => (new DeviceResource($d))->resolve())->values(),
        ]);
    }
}
