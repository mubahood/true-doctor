<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAbility;
use App\Services\AccessService;
use App\Services\StaffService;
use App\Support\Access\Abilities;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings, Staff and access for the app (docs/api/admin.md): each role
 * against what it may do, and one person's extra abilities. AccessService
 * holds every rule, as it does for the web screen; anybody who runs the
 * settings may read, only a hospital admin may change, and the hospital
 * admin role itself never changes.
 */
class AccessController extends Controller
{
    use ReadsAdminInput;

    public function __construct(
        private readonly AccessService $access,
        private readonly StaffService $staff,
    ) {}

    /** GET admin/access - the roles, each ability's state and standard, and who has extras. */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->oneHospital();

        return ApiResponse::success($this->document($request->user()));
    }

    /** PUT admin/access/roles/{role}/abilities/{ability} {state: on|off|default} */
    public function set(Request $request, string $role, string $ability): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->oneHospital();
        $actor = $request->user();
        if (! AccessService::canManage($actor)) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Only a hospital admin can change who may do what.', 403);
        }

        $state = (string) $request->validate([
            'state' => ['required', 'string', Rule::in(['on', 'off', 'default'])],
        ], ['state.in' => 'Say on, off or default.'])['state'];

        try {
            $moved = $state === 'default'
                ? $this->access->restoreDefault($actor, $role, $ability)
                : $this->access->setForRole($actor, $role, $ability, $state === 'on');
        } catch (\DomainException $e) {
            return $this->refused($e->getMessage());
        }

        $matrix = $this->access->matrix();
        $document = $this->document($actor, $matrix);
        $label = $this->roleLabel($role);
        $names = fn (array $keys) => implode(', ', array_map(fn ($a) => Abilities::label((string) $a), $keys));

        if ($state === 'default') {
            // The ones that moved with it follow the way the standard went.
            $others = array_values(array_diff($moved, [$ability]));
            $on = (bool) ($matrix[$role][$ability]['on'] ?? false);
            $message = Abilities::label($ability).": back to the standard for every {$label}."
                .($others === [] ? '' : ' '.$names($others).': '.($on ? 'on' : 'off').' with it.');
        } else {
            $message = $names($moved).": {$state} for every {$label}.";
        }

        return ApiResponse::success($document + ['moved' => $moved], $message);
    }

    /** POST admin/access/roles/{role}/reset - the whole role back to the standard. */
    public function reset(Request $request, string $role): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->oneHospital();
        $actor = $request->user();
        if (! AccessService::canManage($actor)) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Only a hospital admin can change who may do what.', 403);
        }

        try {
            $this->access->resetRole($actor, $role);
        } catch (\DomainException $e) {
            return $this->refused($e->getMessage());
        }

        return ApiResponse::success($this->document($actor), 'The '.$this->roleLabel($role).' role is back to the standard settings.');
    }

    /** GET admin/users/{id}/abilities - one person's extra abilities, and whether this caller may change them. */
    public function personal(Request $request, int $user): JsonResponse
    {
        $account = $this->manageable($request, $user);
        $this->authorize('view', $account);

        return ApiResponse::success($this->personalDocument($request->user(), $account));
    }

    /** PUT admin/users/{id}/abilities {abilities: [...]} - make this person's extras exactly these. */
    public function setPersonal(Request $request, int $user): JsonResponse
    {
        $account = $this->manageable($request, $user);
        $this->authorize('update', $account);
        $actor = $request->user();
        if (! AccessService::canManage($actor)) {
            return ApiResponse::error(ApiErrorCode::Forbidden, 'Only a hospital admin can give extra abilities.', 403);
        }

        $data = $request->validate([
            'abilities' => ['present', 'array'],
            'abilities.*' => ['string', Rule::in(array_keys(Abilities::ALL))],
        ], ['abilities.*.in' => 'That ability is not one of the list.']);

        try {
            $this->access->setPersonal($actor, $account, array_values($data['abilities']));
        } catch (\DomainException $e) {
            return $this->refused($e->getMessage(), 'abilities');
        }

        return ApiResponse::success($this->personalDocument($actor, $account->fresh() ?? $account), "Extra abilities saved for {$account->name}.");
    }

    // ── Shapes ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, array{on:bool, partial:bool, default:bool, changed:bool}>>|null  $matrix
     * @return array<string,mixed>
     */
    private function document(User $actor, ?array $matrix = null): array
    {
        $matrix ??= $this->access->matrix();

        // The hospital admin first, the way the web's table has it: every
        // ability, always, so the clinic can never be locked out.
        $roles = [[
            'key' => Abilities::LOCKED_ROLE,
            'label' => (new User(['role' => Abilities::LOCKED_ROLE]))->role_label,
            'locked' => true,
            'changed' => false,
            'abilities' => array_map(fn () => ['on' => true, 'partial' => false, 'default' => true, 'changed' => false], Abilities::ALL),
        ]];
        foreach (Abilities::ROLES as $role) {
            $cells = $matrix[$role] ?? [];
            $roles[] = [
                'key' => $role,
                'label' => (new User(['role' => $role]))->role_label,
                'locked' => false,
                'changed' => collect($cells)->contains(fn ($c) => $c['changed']),
                'abilities' => $cells,
            ];
        }

        // Each person's extra abilities, through the tenant scope; the people
        // come from this hospital's staff only (User carries no scope).
        $extras = UserAbility::query()->get(['user_id', 'ability'])->groupBy('user_id');
        $people = $extras->isEmpty() ? collect() : User::query()->currentHospital()
            ->whereKey($extras->keys()->all())->orderBy('name')->get(['id', 'name', 'role']);

        return [
            'can_edit' => AccessService::canManage($actor),
            'locked_note' => 'The hospital admin always keeps everything, so the clinic can never be locked out.',
            'abilities' => $this->catalogue(),
            'roles' => $roles,
            'people' => $people->map(fn (User $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'role' => $p->role,
                'role_label' => $p->role_label,
                'extras' => collect($extras[$p->id] ?? [])
                    ->filter(fn ($row) => Abilities::exists((string) $row->ability))
                    ->map(fn ($row) => ['key' => (string) $row->ability, 'label' => Abilities::label((string) $row->ability)])
                    ->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function personalDocument(User $actor, User $account): array
    {
        $role = strtolower((string) $account->role_label);

        return [
            'user' => ['id' => $account->id, 'name' => $account->name, 'role' => $account->role, 'role_label' => $account->role_label],
            'extras' => $this->access->personal($account),
            'can_edit' => AccessService::canGiveExtras($actor, $account),
            'note' => Abilities::roleIsEditable((string) $account->role)
                ? "On top of what the {$role} role allows. What every {$role} may do is set in Staff and access."
                : 'A hospital admin already has every ability.',
            'abilities' => $this->catalogue(),
        ];
    }

    /** @return list<array{key:string, label:string, hint:string, needs:list<string>}> */
    private function catalogue(): array
    {
        $out = [];
        foreach (Abilities::ALL as $key => $ability) {
            $out[] = ['key' => $key, 'label' => $ability['label'], 'hint' => $ability['hint'], 'needs' => Abilities::needs($key)];
        }

        return $out;
    }

    private function roleLabel(string $role): string
    {
        return strtolower((new User(['role' => $role]))->role_label);
    }

    /** A foreign tenant's account simply does not exist here (404). */
    private function manageable(Request $request, int $id): User
    {
        return $this->staff->manageableBy($request->user())->findOrFail($id);
    }

    /**
     * One clinic's log only: with no hospital resolved (a platform account)
     * the tenant scope filters nothing and every clinic's rows would answer.
     */
    private function oneHospital(): void
    {
        abort_if(app(\App\Support\CurrentHospital::class)->id() === null, 403, 'This is kept by each clinic. Open it from inside the clinic.');
    }
}
