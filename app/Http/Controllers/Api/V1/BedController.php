<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BedStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\BedRequest;
use App\Http\Resources\BedResource;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Ward;
use App\Services\HospitalStructureService;
use App\Services\WardService;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Beds — the web's Beds page (view: ipd.view; change: wards.manage). The
 * subscription plan's bed limit applies on create, an occupied bed's status is
 * never changed by hand and an occupied bed cannot be deleted.
 */
class BedController extends Controller
{
    use ReadsAdminInput;

    private const FIELDS = ['ward_id', 'name', 'daily_charge', 'status', 'is_active'];

    public function __construct(private readonly HospitalStructureService $structure, private readonly WardService $wards) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bed::class);

        $q = trim((string) $request->query('q', ''));
        $rows = Bed::query()->with('ward')
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $x) => $x->where('name', 'like', "%{$q}%")->orWhereHas('ward', fn (Builder $w) => $w->where('name', 'like', "%{$q}%"))))
            ->when($request->filled('ward_id'), fn (Builder $b) => $b->where('ward_id', (int) $request->query('ward_id')))
            ->when($request->filled('status'), fn (Builder $b) => $b->where('status', (string) $request->query('status')))
            ->when($request->query('active') === '1', fn (Builder $b) => $b->where('is_active', true))
            ->orderBy('ward_id')->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, BedResource::collection($rows->items()));
    }

    /** The pickers for the form: statuses and the active wards (with the rate a new bed starts at). */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', Bed::class);

        return ApiResponse::success([
            'statuses' => BedStatus::options(),
            'wards' => Ward::where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_daily_charge']),
        ]);
    }

    public function show(Bed $bed): JsonResponse
    {
        $this->authorize('viewAny', Bed::class);

        $bed->load(['ward', 'currentAdmission.patient']);
        $closed = Admission::where('bed_id', $bed->id)->whereNotNull('discharged_at')->get();

        return ApiResponse::success(array_merge((new BedResource($bed))->resolve(), [
            // Who has been in it lately, and what it has actually earned.
            'stays' => Admission::with('patient')->where('bed_id', $bed->id)->latest('admitted_at')->limit(5)->get()
                ->map(fn (Admission $a) => [
                    'id' => $a->id,
                    'patient' => $a->patient?->full_name,
                    'admitted_at' => $a->admitted_at?->toIso8601String(),
                    'discharged_at' => $a->discharged_at?->toIso8601String(),
                ])->values(),
            'figures' => [
                'stays' => Admission::where('bed_id', $bed->id)->count(),
                'nights' => (int) $closed->sum(fn (Admission $a) => $a->nights()),
                'earned' => $closed->reduce(fn (string $sum, Admission $a) => bcadd($sum, (string) $a->bed_charge_total, 2), '0.00'),
            ],
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Bed::class);

        return $this->save($request, null);
    }

    public function update(Request $request, Bed $bed): JsonResponse
    {
        $this->authorize('update', $bed);

        return $this->save($request, $bed);
    }

    /** Take a bed out of service or put it back (never to or from occupied). */
    public function status(Request $request, Bed $bed): JsonResponse
    {
        $this->authorize('update', $bed);

        $data = $request->validate(['status' => ['required', new Enum(BedStatus::class)]]);

        try {
            $this->wards->setBedStatus($bed, BedStatus::from($data['status']));
        } catch (\RuntimeException $e) {
            return $this->refused($e->getMessage(), 'status');
        }

        return ApiResponse::success(new BedResource($bed->fresh('ward')), 'Bed '.$bed->name.' is now '.$data['status'].'.');
    }

    public function destroy(Bed $bed): JsonResponse
    {
        $this->authorize('delete', $bed);

        try {
            $this->structure->assertBedDeletable($bed);
        } catch (\DomainException $e) {
            return $this->refused($e->getMessage());
        }

        $bed->delete();

        return ApiResponse::success(null, 'Bed archived.');
    }

    private function save(Request $request, ?Bed $bed): JsonResponse
    {
        $input = $this->formInput($request, self::FIELDS, $bed);
        $input['is_active'] = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        // A new bed starts at its ward's standard rate, unless one was given.
        if ($bed === null && (! isset($input['daily_charge']) || $input['daily_charge'] === '') && isset($input['ward_id'])) {
            $input['daily_charge'] = (string) (Ward::find($input['ward_id'])?->default_daily_charge ?? '');
        }
        if ($bed === null) {
            $input['status'] ??= BedStatus::Available->value;
        }

        $data = $this->validated($input, BedRequest::rulesFor($bed?->id));

        if ($bed === null) {
            try {
                $this->structure->assertBedCreatable();
            } catch (PlanLimitExceededException $e) {
                return $this->forbiddenLimit($e->getMessage());
            }
        }

        $data = $this->structure->bedAttributes($bed, $data);

        [$saved, $state] = CatalogueStore::persist(Bed::class, $bed, $data, [], ['ward_id', 'name']);

        return ApiResponse::success(
            new BedResource($saved->load('ward')),
            'Bed '.($state === 'created' ? 'created.' : ($state === 'restored' ? 'restored from the archive.' : 'updated.')),
            $state === 'updated' ? 200 : 201,
        );
    }
}
