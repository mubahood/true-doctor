<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Hospital;
use App\Support\ApiResponse;
use App\Support\Capabilities;
use App\Support\CurrentHospital;
use App\Support\Demo;
use App\Support\Edition;
use App\Support\EnumCatalogue;
use App\Support\HospitalSettings;
use App\Support\Navigation;
use App\Support\OnboardingStatus;
use App\Support\SubscriptionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success($this->document($request));
    }

    /**
     * The whole `meta` document. Also the `meta` of `GET /bootstrap`, so the
     * two can never describe the system differently.
     *
     * Existing keys are unchanged (installed apps read them); `capabilities`
     * is added beside them.
     *
     * @return array<string,mixed>
     */
    public function document(Request $request): array
    {
        $settings = app(HospitalSettings::class);
        $subscriptions = app(SubscriptionState::class);
        $onboarding = app(OnboardingStatus::class);

        /** @var \App\Models\User $user */
        $user = $request->user()->loadMissing('hospital');
        $hospital = Hospital::find(app(CurrentHospital::class)->id());
        $inSetup = $onboarding->mustCompleteSetup($user);

        return [
            'api_version' => self::API_VERSION,
            'edition' => Edition::name(),
            'version' => Edition::version(),
            'sync_protocol' => SyncController::PROTOCOL_VERSION,
            'server_time' => now()->toIso8601String(),
            'web_url' => url('/'),
            // So the app can say "a new version is out" before it is required.
            'app' => [
                'min_version' => config('services.mobile.min_version'),
                'latest_version' => config('services.mobile.latest_version'),
                'download_url' => config('services.mobile.download_url'),
            ],
            'user' => (new UserResource($user))->toArray($request) + [
                'role_label' => $user->role_label,
                'phone' => $user->phone,
                'password_change_required' => (bool) $user->password_change_required,
            ],
            'hospital' => $hospital ? $this->hospital($hospital, $settings) : null,
            'subscription' => Edition::isSaas() && $hospital ? $this->subscription($hospital, $subscriptions) : null,
            'setup_required' => $inSetup,
            'navigation' => $this->navigation(Navigation::for($user, $inSetup)['sections']),
            'enums' => EnumCatalogue::all(),
            // Option lists that are data, not enums - the same lists the web
            // forms read, so the app offers the same choices.
            'options' => [
                'districts' => \App\Models\District::query()->orderBy('name')->get(['id', 'name'])->toArray(),
                'blood_types' => \App\Http\Requests\PatientRequest::BLOOD_TYPES,
            ],
            // What this server can do for this user: versions, edition,
            // permissions, features, limits (docs/api/plane.md).
            'capabilities' => Capabilities::for($user, $hospital),
            // Two-step sign-in, so the app can offer it or insist on it (docs/api/security.md).
            'security' => [
                'two_factor_enabled' => app(\App\Services\TwoFactorService::class)->isEnabled($user),
                'two_factor_required' => app(\App\Services\TwoFactorService::class)->isRequired($user),
                'recovery_codes_left' => app(\App\Services\TwoFactorService::class)->recoveryCodesLeft($user),
                // This server turns it off and makes new recovery codes from the app
                // (auth/two-factor/disable, auth/two-factor/recovery-codes); older ones do not.
                'self_service' => true,
            ],
            // The order form: the kinds of work this practice is offered (no
            // admission without wards) and the words its fields use (docs/orders.md).
            'orders' => \App\Services\OrderDesk::form(\App\Support\Practice\Practice::of($hospital)),
            // Who to ask for help, and the prefilled messages (docs/api/support.md).
            'support' => \App\Support\SupportContact::forHospital($hospital)->toArray(),
        ] + (Demo::isDemoUser($user) ? ['demo' => [
            'active' => true,
            'role' => $user->role,
            'role_label' => $user->role_label,
            'hospital' => $hospital?->name,
            'resets' => Demo::resets(),
        ]] : []);
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
            // The hospital's own look (Settings → Hospital), so the app can wear
            // it too. Every field is null when the hospital has set none.
            'brand' => (function () use ($hospital): array {
                $brand = new \App\Support\HospitalBrand($hospital);

                return [
                    'colour' => $brand->colour(),
                    'mark_url' => $brand->markUrl(),
                    'wordmark_url' => $brand->wordmarkUrl(),
                    'tagline' => $brand->tagline(),
                ];
            })(),
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

    /** A badge class (`badge-info`) or a bare tone (`active`) as a client tone. */
    public static function tone(string $badge): string
    {
        return EnumCatalogue::tone($badge);
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
