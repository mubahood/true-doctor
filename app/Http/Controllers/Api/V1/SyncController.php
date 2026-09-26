<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncPushRequest;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Services\Sync\PullCursor;
use App\Services\Sync\PullService;
use App\Services\Sync\SyncEngine;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use App\Support\SyncRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The endpoints an offline device talks to.
 *
 * Dedicated rather than reusing the ordinary CRUD controllers, for four
 * reasons the plan sets out (§9): batching, per-operation results that do not
 * fail the batch, idempotency keyed on `operation_id`, and version checks.
 * Bolting all four onto `PatientController@store` would make the ordinary API
 * worse for every other consumer.
 *
 * Everything else is shared: the same `ApiResponse` envelope, the same
 * Sanctum guard, the same Policies, the same tenant scope resolved by
 * `ResolveHospital`.
 */
class SyncController extends Controller
{
    /** The wire format this server speaks. A device on another is refused. */
    public const PROTOCOL_VERSION = 1;

    public function __construct(
        private readonly SyncEngine $engine,
        private readonly PullService $pull,
        private readonly CurrentHospital $current,
    ) {}

    /**
     * Where this device stands, and whether it may sync at all.
     *
     * Deliberately cheap: the client calls it as a reachability probe every
     * fifteen seconds while offline, so it must not do real work (plan §13).
     */
    public function status(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $device?->update(['last_seen_at' => now()]);

        return ApiResponse::success([
            'protocol_version' => self::PROTOCOL_VERSION,
            'server_time' => now()->toIso8601String(),
            'hospital_id' => $this->current->id(),
            'device' => $device === null ? null : $this->deviceSummary($device),
            'accepts' => $this->engine->acceptedEntities(),
            'current_revision' => SyncRevision::current(),
            // This device's, when it says which it is: the hospital-wide count
            // is an administrator's question (/admin/offline-devices).
            'open_conflicts' => SyncConflict::whereNull('resolved_at')
                ->when($device instanceof Device, fn ($q) => $q->where('device_id', $device->id))
                ->count(),
        ]);
    }

    /** @return array<string,mixed> */
    private function deviceSummary(Device $device): array
    {
        return [
            'device_uuid' => $device->device_uuid,
            'label' => $device->label,
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            'pull_cursor' => $device->pull_cursor,
            'revoked' => ! $device->isActive(),
        ];
    }

    /**
     * Register this browser as trusted to hold patient data offline.
     *
     * An explicit, named, audited act — not a side effect of logging in. A
     * hospital has to be able to see the list of machines carrying a copy of
     * its records and take one off it (plan §12).
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'label' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:80'],
        ]);

        $user = Auth::user();
        $hospitalId = $this->current->id();

        if ($hospitalId === null) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                'Offline mode needs a hospital context. Sign in to a hospital first.',
                403,
            );
        }

        // Scoped by the global scope, so an id belonging to another hospital
        // reads as absent and is created here rather than hijacked.
        $existing = Device::where('device_uuid', $data['device_uuid'])->first();

        if ($existing !== null && ! $existing->isActive()) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                'This device has been blocked from offline use. Ask an administrator.',
                403,
            );
        }

        // Re-registering keeps the original date: "registered on" is when this
        // machine was first trusted, not when it last said hello.
        $registeredAt = $existing === null ? now() : ($existing->registered_at ?? now());

        $device = Device::updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            [
                'hospital_id' => $hospitalId,
                'user_id' => $user->id,
                'label' => $data['label'],
                'platform' => $data['platform'] ?? null,
                'registered_at' => $registeredAt,
                'last_seen_at' => now(),
                'protocol_version' => self::PROTOCOL_VERSION,
            ],
        );

        activity()
            ->causedBy($user)
            ->performedOn($device)
            ->withProperties(['label' => $device->label, 'platform' => $device->platform])
            ->log('offline device registered');

        return ApiResponse::success([
            'device_uuid' => $device->device_uuid,
            'label' => $device->label,
            'protocol_version' => self::PROTOCOL_VERSION,
            'server_time' => now()->toIso8601String(),
        ], 'This device may now work offline.');
    }

    /** Apply a batch of operations. */
    public function push(SyncPushRequest $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        // Required, as the protocol says (§2) and as pull and ack already
        // were. Without it a revoked device could keep writing by leaving the
        // header off — revocation would stop only the honest ones.
        if ($device === null) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Sending work needs a registered device.', 403);
        }

        $outcome = $this->engine->push(
            $request->validated('operations'),
            Auth::user(),
            $device,
            (string) $request->input('protocol_version', self::PROTOCOL_VERSION),
        );

        return ApiResponse::success($outcome);
    }

    /**
     * One page of everything this device has not seen.
     *
     * The device saves the page and only THEN sends the next cursor back
     * (invariant I-4). Nothing here advances anything server-side: the cursor
     * returned is a value the client owns, and `ack` is what records it.
     */
    public function pull(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        if ($device === null) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Pulling needs a registered device.', 403);
        }

        $cursor = PullCursor::decode(
            $request->query('cursor'),
            (int) $this->current->id(),
            $device->device_uuid,
        );

        return ApiResponse::success($this->pull->page($cursor, Auth::user(), $device));
    }

    /**
     * The device says it has committed a page.
     *
     * Kept server-side only so an administrator can see how far behind a
     * device is, and so a wiped device can be told where it was. The device's
     * own copy is authority for what it asks for next — a server that decided
     * the cursor could skip a page the device never managed to save.
     */
    public function ack(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        if ($device === null) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Acknowledging needs a registered device.', 403);
        }

        $data = $request->validate([
            'cursor' => ['required', 'string', 'max:2000'],
            'applied' => ['nullable', 'integer', 'min:0'],
        ]);

        $device->update(['pull_cursor' => $data['cursor'], 'last_sync_at' => now()]);

        return ApiResponse::success([
            'pull_cursor' => $device->pull_cursor,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * The device settled a conflict without sending anything — "keep the
     * server's" changes nothing on the server, so without this the conflict
     * stayed open for ever. ("Keep mine" closes itself when its new operation
     * is accepted.)
     */
    public function resolve(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        if ($device === null) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Resolving needs a registered device.', 403);
        }

        $data = $request->validate([
            'operation_id' => ['required', 'string', 'max:40'],
            'resolution' => ['required', 'in:kept_server,kept_mine,cancelled'],
        ]);

        $closed = $this->engine->closeConflicts($device, '', $data['resolution'], Auth::user(), $data['operation_id']);

        return ApiResponse::success(['closed' => $closed]);
    }

    /** Catalogues the device reads and never edits. */
    public function reference(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        return ApiResponse::success([
            'reference' => $this->pull->reference(Auth::user(), (int) $request->query('since', '0')),
            'reference_version' => SyncRevision::current(),
        ]);
    }

    /**
     * A device that has been blocked, or is speaking a protocol this server
     * does not, is turned away before any work happens.
     *
     * @return Device|JsonResponse|null
     */
    private function resolveDevice(Request $request)
    {
        $uuid = $request->header('X-Device-Id') ?? $request->input('device_uuid');

        if ($uuid === null) {
            // Not every caller has registered yet — `status` is reachable
            // without a device so a browser can ask before it commits.
            return null;
        }

        $device = Device::where('device_uuid', $uuid)->first();

        if ($device === null) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                'This device is not registered for offline use.',
                403,
            );
        }

        if (! $device->isActive()) {
            // The client wipes its local database on seeing this code, so it
            // must be unambiguous and must not be reused for anything else.
            return response()->json([
                'success' => false,
                'code' => 'device_revoked',
                'message' => $device->revoked_reason
                    ?: 'This device has been blocked from offline use. Its local copy will be cleared.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        if ((int) $request->input('protocol_version', self::PROTOCOL_VERSION) !== self::PROTOCOL_VERSION) {
            return response()->json([
                'success' => false,
                'code' => 'protocol_mismatch',
                'message' => 'This app is a different version from the server. Reload to update — your unsent work is kept.',
                'data' => ['server_protocol' => self::PROTOCOL_VERSION],
                'errors' => null,
            ], 409);
        }

        return $device;
    }

    /**
     * What this device has already sent, so a client that lost its local state
     * can tell what landed instead of re-sending a shift's work blind.
     */
    public function operations(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        // A device's own history, never the whole hospital's: without a device
        // this used to return every operation any device had sent.
        if ($device === null) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Reading sent work needs a registered device.', 403);
        }

        $since = $request->date('since') ?? now()->subDays(7);

        $rows = SyncOperation::query()
            ->where('device_id', $device->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(500)
            // Never the payload — only what happened to it (plan §27).
            ->get(['operation_id', 'entity', 'entity_uuid', 'operation', 'status', 'reason_code', 'server_id', 'version', 'processed_at']);

        return ApiResponse::success(['operations' => $rows]);
    }
}
