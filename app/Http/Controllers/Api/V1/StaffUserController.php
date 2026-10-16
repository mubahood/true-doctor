<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlanLimitExceededException;
use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Services\StaffService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * User accounts — the web's User accounts page (manage-users). Every security
 * decision (tenancy, assignable roles, plan seats, temporary passwords, avatar
 * files, welcome mail) is StaffService's; this only authorises, validates and
 * reports. No response ever carries a password.
 */
class StaffUserController extends Controller
{
    use ReadsAdminInput;

    private const FIELDS = ['name', 'email', 'role', 'phone', 'bio', 'is_active', 'sees_patients'];

    public function __construct(private readonly StaffService $staff) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $q = trim((string) $request->query('q', ''));
        $rows = $this->staff->manageableBy($request->user())->with('staffProfile.department')
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->when($request->filled('role'), fn (Builder $b) => $b->where('role', (string) $request->query('role')))
            ->when($request->query('status') === 'active', fn (Builder $b) => $b->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn (Builder $b) => $b->where('is_active', false))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, StaffUserResource::collection($rows->items()));
    }

    /** What the roles carry, for the role picker: the permissions behind each name. */
    public function roles(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $assignable = $this->staff->assignableRoles($request->user());
        $roles = Role::with('permissions')->where('guard_name', 'web')->whereIn('name', $assignable)->get()->keyBy('name');

        $out = [];
        foreach ($assignable as $key) {
            $out[] = [
                'key' => $key,
                'label' => (new User(['role' => $key]))->role_label,
                'permissions' => ($roles[$key] ?? null)?->permissions->pluck('name')->sort()->values()->all() ?? [],
            ];
        }

        return ApiResponse::success($out);
    }

    public function show(Request $request, int $user): JsonResponse
    {
        $account = $this->manageable($request, $user);
        $this->authorize('view', $account);

        $account->load('staffProfile.department');

        return ApiResponse::success(array_merge((new StaffUserResource($account))->resolve(), [
            // What the role actually lets them do — the thing an audit is asking about.
            'abilities' => $account->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $input = $this->formInput($request, self::FIELDS) + ['is_active' => true, 'sees_patients' => false];
        $input['is_active'] = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
        $input['sees_patients'] = filter_var($input['sees_patients'], FILTER_VALIDATE_BOOLEAN);
        $input['avatar'] = $request->file('avatar');

        $data = $this->validated($input, $this->staff->rulesFor($request->user()));

        try {
            $result = $this->staff->create($request->user(), $this->staff->attributes($data), $request->file('avatar'));
        } catch (PlanLimitExceededException $e) {
            return $this->forbiddenLimit($e->getMessage());
        }

        $user = $result['user'];

        return ApiResponse::success(
            array_merge((new StaffUserResource($user))->resolve(), ['credentials_emailed' => $result['emailed']]),
            $result['emailed']
                ? "User {$user->name} created. Login credentials sent to {$user->email}."
                : "User {$user->name} created. Email delivery failed — share credentials manually.",
            201,
        );
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $account = $this->manageable($request, $user);
        $this->authorize('update', $account);

        $input = $this->formInput($request, self::FIELDS, $account);
        $input['is_active'] = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
        $input['sees_patients'] = filter_var($input['sees_patients'], FILTER_VALIDATE_BOOLEAN);
        $input['avatar'] = $request->file('avatar');
        $input += $request->only(['password', 'password_confirmation']);

        $data = $this->validated($input, $this->staff->rulesFor($request->user(), $account->id));

        if (! $data['is_active']) {
            try {
                $this->staff->assertNotSelf($request->user(), $account, 'deactivate');
            } catch (\DomainException $e) {
                return $this->refused($e->getMessage(), 'is_active');
            }
        }

        $this->staff->update(
            $account,
            $this->staff->attributes($data),
            $request->file('avatar'),
            $request->boolean('remove_avatar'),
            $data['password'] ?? null,
        );

        return ApiResponse::success(new StaffUserResource($account->fresh('staffProfile.department')), "User {$account->name} updated.");
    }

    public function activate(Request $request, int $user): JsonResponse
    {
        return $this->setActive($request, $user, true);
    }

    public function deactivate(Request $request, int $user): JsonResponse
    {
        return $this->setActive($request, $user, false);
    }

    /**
     * An administrator's reset: they choose a temporary password, and the
     * person must set their own at next sign-in (password_change_required).
     */
    public function resetPassword(Request $request, int $user): JsonResponse
    {
        $account = $this->manageable($request, $user);
        $this->authorize('update', $account);

        $data = $request->validate(['password' => ['required', 'string', Password::defaults(), 'confirmed']]);

        $this->staff->update($account, [], null, false, $data['password']);

        return ApiResponse::success(new StaffUserResource($account->fresh()), "{$account->name} must set a new password at next sign-in.");
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $account = $this->manageable($request, $user);
        $this->authorize('delete', $account);

        try {
            $this->staff->assertNotSelf($request->user(), $account, 'delete');
        } catch (\DomainException $e) {
            return $this->refused($e->getMessage());
        }

        $account->delete();

        return ApiResponse::success(null, "User {$account->name} deleted.");
    }

    private function setActive(Request $request, int $user, bool $active): JsonResponse
    {
        $account = $this->manageable($request, $user);
        $this->authorize('update', $account);

        if (! $active) {
            try {
                $this->staff->assertNotSelf($request->user(), $account, 'deactivate');
            } catch (\DomainException $e) {
                return $this->refused($e->getMessage(), 'is_active');
            }
        }

        $this->staff->setActive($account, $active);

        return ApiResponse::success(new StaffUserResource($account->fresh()), $active ? "{$account->name} can sign in again." : "{$account->name} can no longer sign in.");
    }

    /** A foreign tenant's row simply does not exist here (404). */
    private function manageable(Request $request, int $id): User
    {
        return $this->staff->manageableBy($request->user())->findOrFail($id);
    }
}
