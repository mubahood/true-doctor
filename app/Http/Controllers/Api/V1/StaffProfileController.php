<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffProfileRequest;
use App\Http\Resources\StaffProfileResource;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\StaffProfile;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff profiles — the clinical/HR extension of a user (view: access-admin;
 * change: staff.manage). One profile per user; the user link cannot change
 * once the profile exists. Never carries credentials.
 */
class StaffProfileController extends Controller
{
    use ReadsAdminInput;

    private const FIELDS = ['user_id', 'department_id', 'job_title', 'specialty', 'license_no', 'qualifications', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $q = trim((string) $request->query('q', ''));
        $rows = StaffProfile::query()->with(['user', 'department'])
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $x) => $x
                ->whereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$q}%"))
                ->orWhere('specialty', 'like', "%{$q}%")->orWhere('license_no', 'like', "%{$q}%")))
            ->when($request->filled('department_id'), fn (Builder $b) => $b->where('department_id', (int) $request->query('department_id')))
            ->when($request->query('status') === 'active', fn (Builder $b) => $b->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn (Builder $b) => $b->where('is_active', false))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, StaffProfileResource::collection($rows->items()));
    }

    public function show(StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('view', $staffProfile);

        $staffProfile->load(['user', 'department']);

        return ApiResponse::success(array_merge((new StaffProfileResource($staffProfile))->resolve(), [
            // The days they are bookable, and how many patients are already booked with them.
            'schedule' => DoctorSchedule::with('room')->where('user_id', $staffProfile->user_id)
                ->orderBy('weekday')->orderBy('start_time')->get()
                ->map(fn (DoctorSchedule $w) => [
                    'weekday' => $w->weekday,
                    'start_time' => $w->start_time,
                    'end_time' => $w->end_time,
                    'room' => $w->room?->name,
                ])->values(),
            'upcoming_appointments' => Appointment::where('doctor_user_id', $staffProfile->user_id)
                ->whereDate('scheduled_at', '>=', now()->toDateString())->count(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', StaffProfile::class);

        return $this->save($request, null);
    }

    public function update(Request $request, StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('update', $staffProfile);

        return $this->save($request, $staffProfile);
    }

    public function destroy(StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('delete', $staffProfile);

        $staffProfile->delete();

        return ApiResponse::success(null, 'Profile archived.');
    }

    private function save(Request $request, ?StaffProfile $profile): JsonResponse
    {
        $input = $this->formInput($request, self::FIELDS, $profile);
        $input['is_active'] = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $data = $this->validated($input, StaffProfileRequest::rulesFor($profile?->id));

        // The user link is immutable once the profile exists (it carries the tenant).
        if ($profile !== null) {
            unset($data['user_id']);
        }

        [$saved, $state] = CatalogueStore::persist(
            StaffProfile::class, $profile, $data,
            ['job_title', 'specialty', 'license_no', 'qualifications'], ['user_id'],
        );

        return ApiResponse::success(
            new StaffProfileResource($saved->load(['user', 'department'])),
            'Profile '.($state === 'created' ? 'created.' : ($state === 'restored' ? 'restored from the archive.' : 'updated.')),
            $state === 'updated' ? 200 : 201,
        );
    }
}
