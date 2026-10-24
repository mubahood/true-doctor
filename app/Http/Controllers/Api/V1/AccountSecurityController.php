<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AccountSecurityService;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Two-step sign-in and signed-in devices for the app: the same
 * TwoFactorService and AccountSecurityService as the web page
 * (docs/api/security.md).
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AccountSecurityService $security,
    ) {}

    /** POST auth/two-factor - the second step of auth/login: the challenge and a code give a token. */
    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:2000'],
            'code' => ['required', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $opened = $this->twoFactor->openChallenge($data['challenge']);
        if ($opened === null) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated, 'That took too long. Sign in again.', 401);
        }
        $user = $opened['user'];

        $key = 'api-two-factor:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::error(ApiErrorCode::TooManyRequests, 'Too many wrong codes. Wait a minute and try again.', 429, null, null, true, RateLimiter::availableIn($key));
        }

        if (! $user->is_active || ! $user->canAccessAdmin()) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'This account cannot sign in here.', 403);
        }

        if (! $this->twoFactor->verify($user, $data['code'])) {
            RateLimiter::hit($key, 60);

            return ApiResponse::error(ApiErrorCode::ValidationFailed, 'That code is not right. Use the newest code in the app, or a recovery code.', 422, ['code' => ['That code is not right. Use the newest code in the app, or a recovery code.']]);
        }
        RateLimiter::clear($key);

        $device = trim((string) ($data['device_name'] ?? '')) ?: $opened['device'];
        $token = $user->createToken($device !== '' ? $device : 'api')->plainTextToken;
        $user->loadMissing('hospital');

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
            'password_change_required' => (bool) $user->password_change_required,
            'recovery_codes_left' => $this->twoFactor->recoveryCodesLeft($user),
        ], 'Signed in.');
    }

    /** POST auth/two-factor/setup - a new secret to scan or type (not in force until confirmed). */
    public function setup(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseDemo($request)) {
            return $refusal;
        }
        if ($this->twoFactor->isEnabled($request->user())) {
            return ApiResponse::error(ApiErrorCode::Conflict, 'Two-step sign-in is already on.', 409);
        }

        $pending = $this->twoFactor->begin($request->user());

        return $this->secret(ApiResponse::success(['secret' => $pending['secret'], 'uri' => $pending['uri']]));
    }

    /** POST auth/two-factor/confirm - a code from the app turns it on; the recovery codes come back once. */
    public function confirm(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseDemo($request)) {
            return $refusal;
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        // A retry after a lost answer finds it already on: the codes of that answer
        // were never kept (no-store), so say so and point to new ones.
        if ($this->twoFactor->isEnabled($request->user())) {
            return ApiResponse::error(ApiErrorCode::Conflict, 'Two-step sign-in is already on. If you did not see your recovery codes, make new ones in My account.', 409);
        }

        $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        if ($codes === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, 'That code is not right. Type the newest code the app shows.', 422, ['code' => ['That code is not right. Type the newest code the app shows.']]);
        }

        return $this->secret(ApiResponse::success(['recovery_codes' => $codes], 'Two-step sign-in is on.'));
    }

    /**
     * POST auth/two-factor/recovery-codes - new codes, shown once; the old ones
     * stop working. Needs the password, as on the web.
     */
    public function regenerate(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseDemo($request)) {
            return $refusal;
        }
        $this->confirmPassword($request);
        if (! $this->twoFactor->isEnabled($request->user())) {
            return ApiResponse::error(ApiErrorCode::Conflict, 'Two-step sign-in is off, so there are no recovery codes to replace.', 409);
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());

        return $this->secret(ApiResponse::success(['recovery_codes' => $codes], 'New recovery codes made. The old ones no longer work.'));
    }

    /**
     * POST auth/two-factor/disable - turn your own two-step sign-in off, with
     * your password (a colleague's is turned off from their account instead).
     * For a role that must use it, this is the move to a new phone: the next
     * request holds the person on setting it up again.
     */
    public function disable(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseDemo($request)) {
            return $refusal;
        }
        $this->confirmPassword($request);

        $user = $request->user();
        if (! $this->twoFactor->isEnabled($user)) {
            return ApiResponse::error(ApiErrorCode::Conflict, 'Two-step sign-in is already off.', 409);
        }

        $this->twoFactor->disable($user);
        $required = $this->twoFactor->isRequired($user);

        return ApiResponse::success(
            ['two_factor_enabled' => false, 'two_factor_required' => $required],
            $required ? 'Two-step sign-in is off. Set it up on the new phone to carry on.' : 'Two-step sign-in is off.',
        );
    }

    /** GET auth/devices - the apps signed in to this account. */
    public function devices(Request $request): JsonResponse
    {
        return ApiResponse::success($this->security->devices($request->user(), $this->currentTokenId($request)));
    }

    /** DELETE auth/devices/{device} */
    public function revoke(Request $request, int $device): JsonResponse
    {
        if (! $this->security->revokeDevice($request->user(), $device)) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'No such device on this account.', 404);
        }

        return ApiResponse::success(null, 'That device is signed out.');
    }

    /** POST auth/sign-out-everywhere - every other app and browser; this one stays. */
    public function signOutEverywhere(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseDemo($request)) {
            return $refusal;
        }
        $request->validate(['password' => ['required', 'current_password:sanctum']], [
            'password.current_password' => 'That is not your password.',
        ]);

        $this->security->signOutEverywhere($request->user(), $this->currentTokenId($request));

        return ApiResponse::success(null, 'Every other app and browser is signed out.');
    }

    /** The password, checked the way the web's Account security page checks it, in its words. */
    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string', 'current_password:sanctum']], [
            'password.required' => 'Enter your password to confirm.',
            'password.current_password' => 'That is not your password.',
        ]);
    }

    /** An answer that carries a secret: never cached, never kept by the idempotency store. */
    private function secret(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function currentTokenId(Request $request): ?int
    {
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());

        return $token === null ? null : (int) $token->getKey();
    }

    private function refuseDemo(Request $request): ?JsonResponse
    {
        return Demo::isDemoUser($request->user())
            ? ApiResponse::error(ApiErrorCode::Forbidden, 'The demo accounts are shared, so their sign-in settings cannot be changed.', 403)
            : null;
    }
}
