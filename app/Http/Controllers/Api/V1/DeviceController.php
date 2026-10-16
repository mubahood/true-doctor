<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\Push\PushManager;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's phones, as far as notifications go. A person only
 * ever sees or changes their own rows; another user's device is a 404.
 * docs/api/notify.md.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DeviceToken::where('user_id', $request->user()->id)->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')->get()->map(fn (DeviceToken $d) => $this->row($d))->all();

        return ApiResponse::success($rows);
    }

    public function pushToken(Request $request, string $deviceUuid): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:191'],
            'platform' => ['required', 'in:android,ios,web,windows,macos,linux'],
            'provider' => ['nullable', 'in:none,fcm,apns,webpush'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:16'],
            'tz' => ['nullable', 'timezone:all'],
            'push_enabled' => ['nullable', 'boolean'],
        ]);
        abort_if(strlen($deviceUuid) > 64, 404);

        $userId = $request->user()->id;
        $token = $data['token'] ?? null;

        $device = DeviceToken::firstOrNew(['user_id' => $userId, 'device_uuid' => $deviceUuid]);

        // The same token on another row of this person's (an old registration
        // of this phone, before it had an id) is that row, not a second one.
        if ($token !== null) {
            DeviceToken::where('user_id', $userId)->where('token', $token)
                ->when($device->exists, fn ($q) => $q->whereKeyNot($device->getKey()))
                ->delete();
        }

        $device->fill([
            'token' => $token ?? $device->token,
            'platform' => $data['platform'],
            'provider' => $data['provider'] ?? ($token !== null ? $this->providerFor($data['platform']) : ($device->provider ?: 'none')),
            'app_version' => $data['app_version'] ?? $device->app_version,
            'locale' => $data['locale'] ?? $device->locale,
            'tz' => $data['tz'] ?? $device->tz,
            'push_enabled' => (bool) ($data['push_enabled'] ?? true),
            'revoked_at' => null,   // registering again is how a device comes back
            'last_seen_at' => now(),
        ])->save();

        return ApiResponse::success($this->row($device));
    }

    public function destroy(Request $request, string $deviceUuid): JsonResponse
    {
        $device = DeviceToken::where('user_id', $request->user()->id)->where('device_uuid', $deviceUuid)->firstOrFail();
        $device->forceFill(['revoked_at' => now(), 'push_enabled' => false])->save();

        return ApiResponse::success(null, 'Device removed.');
    }

    private function providerFor(string $platform): string
    {
        return match ($platform) {
            'ios' => 'apns',
            'web' => 'webpush',
            default => 'fcm',
        };
    }

    /** @return array<string,mixed> */
    private function row(DeviceToken $d): array
    {
        return [
            'device_uuid' => $d->device_uuid,
            'platform' => $d->platform,
            'provider' => $d->provider,
            'app_version' => $d->app_version,
            'locale' => $d->locale,
            'tz' => $d->tz,
            'push_enabled' => (bool) $d->push_enabled,
            'has_token' => $d->token !== null,
            'push_delivers' => (bool) $d->push_enabled && $d->token !== null && app(PushManager::class)->delivers(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
        ];
    }
}
