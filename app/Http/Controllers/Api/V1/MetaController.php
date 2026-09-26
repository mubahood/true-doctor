<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Hospital;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use App\Support\Navigation;
use App\Support\OnboardingStatus;
use App\Support\SubscriptionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything an app needs to draw the system the way the web draws it.
 *
 * GET /api/v1/meta, once after sign-in and again when the app comes back to
 * the foreground. Nothing here is copied into the app's code: the menu, the
 * statuses with their labels and colours, the way this hospital writes money,
 * and the state of its subscription all come from the same objects the web
 * panel renders from — so the two cannot drift apart.
 */
class MetaController extends Controller
{
    /** The API contract version; bumped only for breaking changes. */
    public const API_VERSION = 1;

    /** Enum badge classes → the handful of tones a client needs to know. */
    private const TONES = [
        'badge-success' => 'success', 'badge-active' => 'success', 'badge-verified' => 'success',
        'badge-info' => 'info', 'badge-pending' => 'info', 'badge-ongoing' => 'info',
        'badge-warn' => 'warn', 'badge-high' => 'warn',
        'badge-danger' => 'danger', 'badge-closed' => 'danger',
        'badge-neutral' => 'neutral', 'badge-brown' => 'neutral',
    ];

    public function __invoke(
        Request $request,
        HospitalSettings $settings,
        SubscriptionState $subscriptions,
        OnboardingStatus $onboarding,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = $request->user()->loadMissing('hospital');
        $hospital = Hospital::find(app(CurrentHospital::class)->id());
        $inSetup = $onboarding->mustCompleteSetup($user);

        return ApiResponse::success([
            'api_version' => self::API_VERSION,
            'sync_protocol' => SyncController::PROTOCOL_VERSION,
            'server_time' => now()->toIso8601String(),
            'web_url' => url('/'),
            'user' => (new UserResource($user))->toArray($request) + [
                'role_label' => $user->role_label,
                'phone' => $user->phone,
                'password_change_required' => (bool) $user->password_change_required,
            ],
            'hospital' => $hospital ? $this->hospital($hospital, $settings) : null,
            'subscription' => $hospital ? $this->subscription($hospital, $subscriptions) : null,
            'setup_required' => $inSetup,
            'navigation' => $this->navigation(Navigation::for($user, $inSetup)['sections']),
            'enums' => $this->enums(),
            // Option lists that are data, not enums — the same lists the web
            // forms read, so the app offers the same choices.
            'options' => [
                'districts' => \App\Models\District::query()->orderBy('name')->get(['id', 'name'])->toArray(),
                'blood_types' => \App\Http\Requests\PatientRequest::BLOOD_TYPES,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function hospital(Hospital $hospital, HospitalSettings $settings): array
    {
        $billing = $settings->billing();

        return [
            'id' => $hospital->id,
            'uuid' => $hospital->uuid,
            'name' => $hospital->name,
            'address' => $hospital->address,
            'timezone' => $hospital->timezone,
            'logo_url' => $this->logoUrl($hospital->logo),
            // Enough to write an amount exactly as HospitalSettings::format
            // does, without asking the server for every figure.
            'money' => [
                'code' => strtoupper((string) $billing['currency_code']),
                'symbol' => (string) ($billing['currency_symbol'] ?? ''),
                'position' => $billing['currency_position'] === 'after' ? 'after' : 'before',
                'decimals' => $settings->decimals(),
                'decimal_separator' => (string) $billing['decimal_separator'],
                'thousands_separator' => (string) $billing['thousands_separator'],
            ],
            'tax' => [
                'enabled' => $settings->taxEnabled(),
                'label' => $settings->taxLabel(),
                'rate' => $settings->taxRate(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function subscription(Hospital $hospital, SubscriptionState $state): array
    {
        $current = $state->current($hospital);
        $badge = $state->badge($hospital);

        return [
            'status' => $current?->status->value,
            'plan' => $current?->plan?->name,
            'grants_access' => $state->grantsAccess($hospital),
            'days_remaining' => $state->daysRemaining($hospital),
            'trial_ends_at' => $current?->trial_ends_at?->toIso8601String(),
            'ends_at' => $current?->ends_at?->toIso8601String(),
            'badge' => $badge ? [
                'label' => $badge->label,
                'tone' => $badge->tone,
                'nudge' => $badge->nudge,
                'urgent' => $badge->urgent,
            ] : null,
        ];
    }

    /**
     * The web menu, reduced to what a client draws: each destination is named
     * by its web route, which the app maps to a native screen — or, where it
     * has none yet, opens on the web at `web_url`.
     *
     * @param  list<array<string,mixed>>  $sections
     * @return list<array<string,mixed>>
     */
    private function navigation(array $sections): array
    {
        $item = fn (array $it) => [
            'type' => 'link',
            'route' => $it['route'],
            'label' => $it['label'],
            'icon' => $it['icon'],
            'web_url' => route($it['route']),
        ];

        return array_map(fn (array $s) => [
            'key' => $s['key'],
            'label' => $s['label'],
            'entries' => array_map(fn (array $e) => $e['type'] === 'link'
                ? $item($e)
                : ['type' => 'group', 'key' => $e['key'], 'label' => $e['label'], 'icon' => $e['icon'],
                    'items' => array_map($item, $e['items'])], $s['entries']),
        ], $sections);
    }

    /**
     * Every status and option list the app renders, with the web's own label
     * and badge tone for each value — read from app/Enums, so a label changed
     * there changes in the app with no release.
     *
     * @return array<string, list<array{value:string, label:string, tone:?string, icon:?string}>>
     */
    private function enums(): array
    {
        $out = [];

        foreach (glob(app_path('Enums/*.php')) ?: [] as $file) {
            $class = 'App\\Enums\\'.basename($file, '.php');

            if (! enum_exists($class) || ! method_exists($class, 'label')) {
                continue;
            }

            $out[Str::snake(class_basename($class))] = array_map(fn (\UnitEnum $case) => [
                'value' => $case instanceof \BackedEnum ? (string) $case->value : $case->name,
                'label' => $case->label(),
                'tone' => method_exists($case, 'badge') ? self::tone((string) $case->badge()) : null,
                'icon' => method_exists($case, 'icon') ? $case->icon() : null,
            ], $class::cases());
        }

        ksort($out);

        return $out;
    }

    /** A badge class (`badge-info`) or a bare tone (`active`) as a client tone. */
    public static function tone(string $badge): string
    {
        $class = str_starts_with($badge, 'badge-') ? $badge : 'badge-'.$badge;

        return self::TONES[$class] ?? 'neutral';
    }

    private function logoUrl(?string $path): ?string
    {
        if ($path === null || $path === '' || str_contains($path, '..')) {
            return null;
        }

        try {
            return Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
