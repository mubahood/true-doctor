<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrationRequest;
use App\Http\Resources\UserResource;
use App\Models\Plan;
use App\Services\RegistrationService;
use App\Support\ApiResponse;
use App\Support\HumanCheck;
use App\Support\PlatformCurrency;
use Illuminate\Http\JsonResponse;

/**
 * Hospital self-registration for the app (SaaS only; the routes 404 on a
 * standalone installation). The same RegistrationRequest rules (including the
 * web's HumanCheck) and the same RegistrationService as POST /register, then
 * a Sanctum token as auth/login would give, so the app is signed in straight
 * into setup.
 */
class PublicRegistrationController extends Controller
{
    public function plans(): JsonResponse
    {
        return ApiResponse::success([
            'trial_days' => self::trialDays(),
            'support' => \App\Support\SupportContact::platform()->toArray(),
            'plans' => Plan::where('is_active', true)->orderBy('price')->get()->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'price' => (string) $p->price,
                'price_label' => PlatformCurrency::format($p->price),
                'billing_cycle' => $p->billing_cycle->value,
                'limits' => $p->limits ?? (object) [],
                'features' => $p->features ?? [],
                'is_featured' => (bool) $p->is_featured,
                'trial_days' => self::trialDays(),
            ])->values(),
        ]);
    }

    /**
     * The picture check, as JSON: a challenge id, the signed "form drawn at"
     * stamp, and the PNG inline. The app shows the picture and posts the
     * three back with the typed characters.
     */
    public function humanCheck(): JsonResponse
    {
        $id = HumanCheck::issue('register');

        return ApiResponse::success([
            'human_id' => $id,
            'human_started' => HumanCheck::stamp('register'),
            'image' => 'data:image/png;base64,'.base64_encode(HumanCheck::image((string) HumanCheck::code($id))),
            'expires_in' => HumanCheck::TTL,
            'min_seconds' => HumanCheck::MIN_SECONDS,
        ])->header('Cache-Control', 'no-store');
    }

    public function register(RegistrationRequest $request, RegistrationService $service): JsonResponse
    {
        $owner = $service->register($request->safe()->except(['human_id', 'human_answer', 'human_started', 'website']));

        $device = trim((string) $request->input('device_name', ''));
        $token = $owner->createToken($device !== '' ? mb_substr($device, 0, 100) : 'api')->plainTextToken;
        $owner->loadMissing('hospital');

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($owner),
            'password_change_required' => (bool) $owner->password_change_required,
        ], 'Welcome! Your '.self::trialDays().'-day trial has started.', 201);
    }

    /** The service owns the number; read it rather than keep a second copy. */
    private static function trialDays(): int
    {
        return (int) (new \ReflectionClassConstant(RegistrationService::class, 'TRIAL_DAYS'))->getValue();
    }
}
