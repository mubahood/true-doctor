<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Requests\DoctorScheduleRequest;
use App\Http\Resources\DoctorScheduleResource;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\DoctorScheduleService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Doctor availability — the Livewire page's roster and list readings and its
 * dialog, over DoctorScheduleService and DoctorSchedulePolicy. Reading needs
 * appointments.view; every write needs schedules.manage.
 */
class ScheduleController extends Controller
{
    public function __construct(private readonly DoctorScheduleService $service) {}

    /**
     * view=roster (default): a page of doctors, each with their week.
     * view=list: a page of windows, one per row.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DoctorSchedule::class);

        $f = $request->validate([
            'view' => ['nullable', Rule::in(['roster', 'list'])],
            'day' => ['nullable', Rule::in(array_map('strval', array_column(Weekday::cases(), 'value')))],
            'state' => ['nullable', Rule::in(['active', 'off'])],
            'unrostered' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($f['per_page'] ?? 20);
        $q = trim((string) ($f['q'] ?? ''));
        $filter = fn ($query) => $query
            ->when(filled($f['day'] ?? null), fn ($x) => $x->where('weekday', (int) $f['day']))
            ->when(filled($f['state'] ?? null), fn ($x) => $x->where('is_active', $f['state'] === 'active'));

        $facts = $this->service->facts();
        $meta = ['facts' => [
            'doctors' => $facts['doctors'], 'rostered' => $facts['rostered'], 'unrostered' => $facts['unrostered'],
            'hours' => $facts['hours'], 'slots' => $facts['slots'], 'windows' => $facts['windows'], 'off' => $facts['off'],
            'coverage' => $facts['coverage'], 'overlapping_window_ids' => array_keys($facts['conflicts']),
        ]];

        if (($f['view'] ?? 'roster') === 'list') {
            $page = $filter(DoctorSchedule::query()->with(['doctor', 'room'])
                ->when($q !== '', fn (Builder $x) => $x->whereHas('doctor', fn (Builder $u) => $u->where('name', 'like', "%{$q}%"))))
                ->orderBy('user_id')->orderBy('weekday')->orderBy('start_time')
                ->paginate($perPage);

            return ApiResponse::paginated($page, DoctorScheduleResource::collection($page->items()), $meta);
        }

        $page = User::currentHospital()->clinicians()
            ->when($q !== '', fn (Builder $x) => $x->where('name', 'like', "%{$q}%"))
            ->when((bool) ($f['unrostered'] ?? false), fn (Builder $x) => $x->whereDoesntHave('schedules'))
            ->with(['schedules' => fn ($s) => $filter($s)->with('room')->orderBy('weekday')->orderBy('start_time')])
            ->orderBy('name')
            ->paginate($perPage);

        return ApiResponse::paginated($page, array_map(fn (User $d) => $this->doctorRow($d), $page->items()), $meta);
    }

    /** One doctor's week, enough to draw the grid: seven days, Monday first. */
    public function doctor(int $doctor): JsonResponse
    {
        $this->authorize('viewAny', DoctorSchedule::class);

        $user = User::currentHospital()->clinicians()
            ->with(['schedules' => fn ($s) => $s->with('room')->orderBy('weekday')->orderBy('start_time')])
            ->findOrFail($doctor);

        return ApiResponse::success($this->doctorRow($user));
    }

    /**
     * Add a window. `weekday` is 0 (Sunday) to 6, or "all" / "weekdays" /
     * "weekend" to add one window per day (days that already have the same
     * window are skipped).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', DoctorSchedule::class);

        $rules = DoctorScheduleRequest::rulesFor();
        $rules['weekday'] = ['required', Rule::in(array_merge(
            DoctorScheduleService::repeatingAnswers(),
            array_map('strval', array_column(Weekday::cases(), 'value')),
        ))];
        $data = $request->validate($rules);
        $data['is_active'] = $request->boolean('is_active', true);

        $days = $this->service->daysMeant((string) $data['weekday']);
        $result = $this->service->addWindows($data, $days);
        $windows = collect($result['windows'])->each->load(['doctor', 'room']);

        $message = $result['added'] === 0
            ? 'Every one of those days already has this window.'
            : $result['added'].' '.\Illuminate\Support\Str::plural('window', $result['added']).' added'
                .($result['skipped'] > 0 ? ', '.$result['skipped'].' already there.' : '.');

        return ApiResponse::success([
            'added' => $result['added'], 'skipped' => $result['skipped'],
            'windows' => DoctorScheduleResource::collection($windows)->resolve(),
        ], $message, $result['added'] > 0 ? 201 : 200);
    }

    public function update(Request $request, DoctorSchedule $schedule): JsonResponse
    {
        $this->authorize('update', $schedule);

        $data = $request->validate(DoctorScheduleRequest::rulesFor($schedule->id));
        $data['is_active'] = $request->boolean('is_active', $schedule->is_active);
        $schedule->update($data);

        return ApiResponse::success(new DoctorScheduleResource($schedule->load(['doctor', 'room'])), 'Availability window updated.');
    }

    /** Switch one window on or off without editing it. */
    public function toggle(DoctorSchedule $schedule): JsonResponse
    {
        $this->authorize('update', $schedule);

        $schedule->is_active = ! $schedule->is_active;
        $schedule->save();

        return ApiResponse::success(new DoctorScheduleResource($schedule->load(['doctor', 'room'])), $schedule->is_active
            ? 'Window switched on — it can be booked again.'
            : 'Window switched off — nothing new can be booked into it.');
    }

    public function destroy(DoctorSchedule $schedule): JsonResponse
    {
        $this->authorize('delete', $schedule);

        $schedule->delete();

        return ApiResponse::success(null, 'Availability window removed.');
    }

    /** @return array<string,mixed> */
    private function doctorRow(User $doctor): array
    {
        $byDay = [];
        $minutes = 0;
        foreach ($doctor->schedules as $w) {
            $byDay[$w->weekday->value][] = (new DoctorScheduleResource($w))->resolve();
            if ($w->is_active) {
                $minutes += max(0, DoctorScheduleService::minutesOf($w->end_time) - DoctorScheduleService::minutesOf($w->start_time));
            }
        }

        $week = [];
        foreach (Weekday::options() as $value => $label) {
            $week[] = ['weekday' => $value, 'label' => $label, 'windows' => $byDay[$value] ?? []];
        }

        return [
            'id' => $doctor->id, 'name' => $doctor->name,
            'hours_per_week' => round($minutes / 60, 1),
            'has_schedule' => $doctor->schedules->isNotEmpty(),
            'week' => $week,
        ];
    }
}
