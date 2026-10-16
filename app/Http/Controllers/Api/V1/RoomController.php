<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rooms — the web's Rooms page (view: access-admin; change: rooms.manage). */
class RoomController extends Controller
{
    use ReadsAdminInput;

    private const FIELDS = ['name', 'department_id', 'type', 'status', 'capacity', 'notes'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        $q = trim((string) $request->query('q', ''));
        $rows = Room::query()->with('department')
            ->when($q !== '', fn (Builder $b) => $b->where('name', 'like', "%{$q}%"))
            ->when($request->filled('department_id'), fn (Builder $b) => $b->where('department_id', (int) $request->query('department_id')))
            ->when($request->filled('type'), fn (Builder $b) => $b->where('type', (string) $request->query('type')))
            ->when($request->filled('status'), fn (Builder $b) => $b->where('status', (string) $request->query('status')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, RoomResource::collection($rows->items()));
    }

    /** The pickers for the form: types, statuses and the departments to attach to. */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        return ApiResponse::success([
            'types' => RoomType::options(),
            'statuses' => RoomStatus::options(),
            'departments' => \App\Models\Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        return ApiResponse::success(new RoomResource($room->load('department')));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Room::class);

        return $this->save($request, null);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $this->authorize('update', $room);

        return $this->save($request, $room);
    }

    public function destroy(Room $room): JsonResponse
    {
        $this->authorize('delete', $room);

        $room->delete();

        return ApiResponse::success(null, 'Room archived.');
    }

    private function save(Request $request, ?Room $room): JsonResponse
    {
        $input = $this->formInput($request, self::FIELDS, $room);
        $input['capacity'] ??= 1;

        $data = $this->validated($input, RoomRequest::rulesFor($room?->id));

        [$saved, $state] = CatalogueStore::persist(Room::class, $room, $data, ['notes']);

        return ApiResponse::success(
            new RoomResource($saved->load('department')),
            'Room '.($state === 'created' ? 'created.' : ($state === 'restored' ? 'restored from the archive.' : 'updated.')),
            $state === 'updated' ? 200 : 201,
        );
    }
}
