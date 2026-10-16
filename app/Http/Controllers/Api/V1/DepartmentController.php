<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Room;
use App\Models\StaffProfile;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Departments — the web's Departments page (view: access-admin; change:
 * departments.manage), through the same DepartmentRequest rules and
 * CatalogueStore the page saves with.
 */
class DepartmentController extends Controller
{
    use ReadsAdminInput;

    private const FIELDS = ['name', 'code', 'head_user_id', 'description', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $q = trim((string) $request->query('q', ''));
        $rows = Department::query()
            ->with('head')->withCount(['rooms', 'staffProfiles'])
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $x) => $x->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->when($request->query('status') === 'active', fn (Builder $b) => $b->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn (Builder $b) => $b->where('is_active', false))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, DepartmentResource::collection($rows->items()));
    }

    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $department->load('head')->loadCount(['rooms', 'staffProfiles']);

        return ApiResponse::success(array_merge((new DepartmentResource($department))->resolve(), [
            // The dialog NAMES them, which is what somebody counting was going to ask next.
            'rooms' => Room::where('department_id', $department->id)->orderBy('name')->limit(8)->get(['id', 'name']),
            'staff' => StaffProfile::with('user')->where('department_id', $department->id)->where('is_active', true)->limit(8)->get()
                ->map(fn (StaffProfile $p) => ['id' => $p->id, 'user_id' => $p->user_id, 'name' => $p->user?->name, 'job_title' => $p->job_title])->values(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        return $this->save($request, null);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        return $this->save($request, $department);
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        $department->delete();

        return ApiResponse::success(null, 'Department archived.');
    }

    private function save(Request $request, ?Department $department): JsonResponse
    {
        $input = $this->formInput($request, self::FIELDS, $department);
        $input['is_active'] = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $input['code'] = filled($input['code'] ?? null) ? strtoupper(trim((string) $input['code'])) : null;

        $data = $this->validated($input, DepartmentRequest::rulesFor($department?->id));

        [$saved, $state] = CatalogueStore::persist(Department::class, $department, $data, ['code', 'description']);

        return ApiResponse::success(
            new DepartmentResource($saved->load('head')->loadCount(['rooms', 'staffProfiles'])),
            'Department '.($state === 'created' ? 'created.' : ($state === 'restored' ? 'restored from the archive.' : 'updated.')),
            $state === 'updated' ? 200 : 201,
        );
    }
}
