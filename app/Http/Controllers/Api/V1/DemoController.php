<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Hospital;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use App\Support\Demo;
use App\Support\DemoPersonas;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The app's "Try the demo" door (docs/api/demo.md).
 *
 * The same demonstration hospital the web offers at /test-login, but the app
 * never holds or types the public demo password: the SERVER signs the visitor
 * in as a role. This endpoint issues tokens to strangers, so it is narrow on
 * purpose:
 *
 *  - the client sends a ROLE KEY, never an email or an id;
 *  - the account is the first active, seeded staff member of that role in the
 *    demonstration hospital, and nobody else (never a platform account, never
 *    another hospital, never an account without a hospital);
 *  - the token is named `demo-app:<device>` and expires in 24 hours;
 *  - nothing about the account is changed (no password reset, no flag cleared).
 */
class DemoController extends Controller
{
    /** How long a demonstration token lives. */
    public const TOKEN_HOURS = 24;

    /** GET /public/demo - what the door offers. 200 with enabled:false when there is none. */
    public function show(): JsonResponse
    {
        $hospital = $this->hospital();
        $accounts = $hospital === null ? collect() : $this->accounts($hospital);

        if ($hospital === null || $accounts->isEmpty()) {
            return ApiResponse::success(['enabled' => false, 'hospital' => null, 'resets' => false, 'roles' => []]);
        }

        $roles = [];
        foreach (DemoPersonas::all() as $key => $persona) {
            $user = $accounts->get($key);
            if ($user === null) {
                continue;
            }
            $roles[] = ['key' => $key, 'label' => $user->role_label] + $persona;
        }

        $billing = app(CurrentHospital::class)->within($hospital->id, fn () => app(HospitalSettings::class)->billing());

        return ApiResponse::success([
            'enabled' => true,
            'hospital' => ['name' => $hospital->name, 'currency' => strtoupper((string) $billing['currency_code'])],
            'resets' => Demo::resets(),
            'roles' => $roles,
        ]);
    }

    /** POST /public/demo/login - sign in as a role of the demonstration hospital. */
    public function login(Request $request): JsonResponse
    {
        $hospital = $this->hospital();
        $accounts = $hospital === null ? collect() : $this->accounts($hospital);

        if ($hospital === null || $accounts->isEmpty()) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'There is no demonstration here.', 404);
        }

        $data = $request->validate([
            'role' => ['required', 'string', 'max:40'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $accounts->get($data['role']);
        if ($user === null) {
            throw ValidationException::withMessages(['role' => ['That demonstration role is not available.']]);
        }

        // Expired demo tokens of this account are tidied away as we go.
        $user->tokens()->where('name', 'like', 'demo-app:%')->where('expires_at', '<', now())->delete();

        $name = 'demo-app:'.(($data['device_name'] ?? '') !== '' ? $data['device_name'] : 'app');
        $token = $user->createToken($name, ['*'], now()->addHours(self::TOKEN_HOURS))->plainTextToken;
        $user->setRelation('hospital', $hospital);

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
            'password_change_required' => false,
            'demo' => ['role' => $user->role, 'role_label' => $user->role_label, 'hospital' => $hospital->name],
        ], 'Signed in to the demonstration.');
    }

    private function hospital(): ?Hospital
    {
        if (! Demo::enabled()) {
            return null;
        }

        return Hospital::query()->where('slug', Demo::hospitalSlug())->first();
    }

    /**
     * The one account per role the door can open, keyed by role.
     *
     * Same selection as the web rail (AuthenticatedSessionController::demoAccounts):
     * seeded `@test.com` staff who belong to a hospital and are not platform
     * accounts - narrowed here to the demonstration hospital, to roles the
     * catalogue knows, to active accounts that can use the panel, and to the
     * lowest id per role so the answer is the same every time.
     *
     * @return \Illuminate\Support\Collection<string, User>
     */
    private function accounts(Hospital $hospital): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('hospital_id', $hospital->id)
            ->where('email', 'like', '%@test.com')
            ->where('is_admin', false)
            ->where('is_active', true)
            ->whereIn('role', DemoPersonas::roles())
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u) => ! $u->isSuperAdmin() && $u->canAccessAdmin())
            ->unique('role')
            ->keyBy('role');
    }
}
