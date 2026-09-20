<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Token auth for the API (Sanctum). One mechanism only (C16). RBAC is enforced
 * on every resource endpoint by the same Policies as the admin panel (C13), so
 * a token never grants more than the user's role.
 */
class AuthController extends Controller
{
    /** A real bcrypt hash of a random string — compared against when the account does not exist. */
    private const DUMMY_HASH = '$2y$04$H1bKcLwDbwSjqqB5xf9RwOG/2JtTJj4Uo7lMQQfQ1M0V4NeWzJ6Y6';

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower((string) $request->validated('email'));
        $key = 'api-login:'.sha1($email.'|'.$request->ip());

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::error(ApiErrorCode::TooManyRequests, 'Too many sign-in attempts. Try again in a minute.', 429);
        }

        $user = User::where('email', $email)->first();

        // Always run a hash check so a missing account costs the same time as a wrong password.
        $valid = Hash::check($request->validated('password'), $user !== null ? $user->password : self::DUMMY_HASH) && $user !== null;

        if (! $valid) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

            return ApiResponse::error(ApiErrorCode::Unauthenticated, 'Invalid credentials.', 401);
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        if (! $user->is_active) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'This account is disabled.', 403);
        }

        $token = $user->createToken($request->validated('device_name') ?: 'api')->plainTextToken;
        $user->loadMissing('hospital');

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
            'password_change_required' => (bool) $user->password_change_required,
        ], 'Signed in.');
    }

    public function me(): JsonResponse
    {
        $user = request()->user();
        $user->loadMissing('hospital');

        return ApiResponse::success(new UserResource($user));
    }

    public function logout(): JsonResponse
    {
        $token = request()->user()?->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(null, 'Signed out.');
    }
}
