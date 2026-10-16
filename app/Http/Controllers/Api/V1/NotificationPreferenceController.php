<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What this person wants to hear about, per category. docs/api/notify.md. */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rows($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'min:1', 'max:'.count(NotificationPreference::CATEGORIES)],
            'preferences.*.category' => ['required', 'in:'.implode(',', NotificationPreference::CATEGORIES)],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.channels' => ['nullable', 'array'],
            'preferences.*.channels.*' => ['in:database,push,sms,mail'],
            'preferences.*.quiet_start' => ['nullable', 'date_format:H:i'],
            'preferences.*.quiet_end' => ['nullable', 'date_format:H:i'],
            'preferences.*.tz' => ['nullable', 'timezone:all'],
        ]);

        foreach ($data['preferences'] as $p) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $request->user()->id, 'category' => $p['category']],
                [
                    'enabled' => (bool) $p['enabled'],
                    'channels' => $p['channels'] ?? null,
                    'quiet_start' => $p['quiet_start'] ?? null,
                    'quiet_end' => $p['quiet_end'] ?? null,
                    'tz' => $p['tz'] ?? null,
                ],
            );
        }

        return ApiResponse::success($this->rows($request));
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(Request $request): array
    {
        $user = $request->user();
        $defaults = NotificationPreference::defaultsFor($user);
        $saved = NotificationPreference::savedFor($user);

        return array_map(function (string $category) use ($defaults, $saved) {
            $p = isset($saved[$category]) ? $saved[$category] : null;

            return [
                'category' => $category,
                'enabled' => $p !== null ? $p->enabled : $defaults[$category],
                'default_enabled' => $defaults[$category],
                'is_default' => $p === null,
                'channels' => $p?->channels,
                'quiet_start' => $p !== null && $p->quiet_start !== null ? $p->quiet_start : NotificationPreference::DEFAULT_QUIET_START,
                'quiet_end' => $p !== null && $p->quiet_end !== null ? $p->quiet_end : NotificationPreference::DEFAULT_QUIET_END,
                'tz' => $p?->tz,
            ];
        }, NotificationPreference::CATEGORIES);
    }
}
