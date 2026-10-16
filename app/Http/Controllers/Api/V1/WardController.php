<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BedStatus;
use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\WardRequest;
use App\Http\Resources\BedResource;
use App\Http\Resources\WardResource;
use App\Models\Bed;
use App\Models\Ward;
use App\Services\HospitalStructureService;
use App\Services\WardService;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Wards — the web's Wards page (view: ipd.view; change: wards.manage). The
 * nightly rate on a ward is the default for its beds; `apply_to_beds` on a
 * save reprices every bed in it, exactly as the page's checkbox does.
 */
class WardController extends Controller
{
    use ReadsAdminInput;

    private const FIELDS = ['name', 'description', 'default_daily_charge', 'is_active'];

    public function __construct(private readonly WardService $wards, private readonly HospitalStructureService $structure) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ward::class);

        $q = trim((string) $request->query('q', ''));
        $rows = Ward::query()->withCount('beds')
            ->when($q !== '', fn (Builder $b) => $b->where('name', 'like', "%{$q}%"))
            ->when($request->query('status') === 'active', fn (Builder $b) => $b->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn (Builder $b) => $b->where('is_active', false))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, WardResource::collection($rows->items()));
    }

    public function show(Ward $ward): JsonResponse
    {
        $this->authorize('viewAny', Ward::class);

        $beds = Bed::with('currentAdmission.patient')->where('ward_id', $ward->id)->orderBy('name')->get();
        $occupied = $beds->where('status', BedStatus::Occupied)->count();

        return ApiResponse::success(array_merge((new WardResource($ward->loadCount('beds')))->resolve(), [
            'beds' => BedResource::collection($beds)->resolve(),
            // How full it is, and what a full night of it is worth.
            'figures' => [
                'beds' => $beds->count(),
                'occupied' => $occupied,
                'free' => $beds->where('status', BedStatus::Available)->count(),
                'percent' => $beds->count() === 0 ? 0 : (int) round($occupied / $beds->count() * 100),
                'a_night' => $beds->reduce(fn (string $sum, Bed $b) => bcadd($sum, (string) $b->daily_charge, 2), '0.00'),
            ],
        ]));
    }

    /** What ticking "apply to beds" would do, for the warning beside it. */
    public function repriceImpact(Ward $ward): JsonResponse
    {
        $this->authorize('update', $ward);

        return ApiResponse::success($this->wards->repriceImpact($ward));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Ward::class);

        return $this->save($request, null);
    }

    public function update(Request $request, Ward $ward): JsonResponse
    {
        $this->authorize('update', $ward);

        return $this->save($request, $ward);
    }

    public function destroy(Ward $ward): JsonResponse
    {
        $this->authorize('delete', $ward);

        try {
            $this->structure->assertWardDeletable($ward);
        } catch (\DomainException $e) {
            return $this->refused($e->getMessage());
        }

        $ward->delete();

        return ApiResponse::success(null, 'Ward archived.');
    }

    private function save(Request $request, ?Ward $ward): JsonResponse
    {
        $input = $this->formInput($request, self::FIELDS, $ward);
        $input['is_active'] = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $input['default_daily_charge'] ??= '0.00';

        $rules = WardRequest::rulesFor($ward?->id);
        $data = $this->validated($input + ['apply_to_beds' => $request->boolean('apply_to_beds')], $rules + ['apply_to_beds' => ['boolean']]);
        $apply = (bool) ($data['apply_to_beds'] ?? false);
        unset($data['apply_to_beds']);

        [$saved, $state] = CatalogueStore::persist(Ward::class, $ward, $data, ['description']);

        $message = 'Ward '.($state === 'created' ? 'created.' : ($state === 'restored' ? 'restored from the archive.' : 'updated.'));
        $repriced = null;

        if ($apply) {
            $this->authorize('update', $saved);
            $repriced = $this->wards->applyNightlyChargeToBeds($saved, (string) $saved->default_daily_charge);

            if ($repriced['beds'] > 0) {
                $message .= ' '.$repriced['beds'].' '.Str::plural('bed', $repriced['beds']).' set to the ward rate.';
                if ($repriced['occupied'] > 0) {
                    $message .= ' '.$repriced['occupied'].' '.Str::plural('stay', $repriced['occupied']).' in progress will be billed at the new rate for every night.';
                }
            }
        }

        return ApiResponse::success(
            array_merge((new WardResource($saved->loadCount('beds')))->resolve(), ['repriced' => $repriced]),
            $message,
            $state === 'updated' ? 200 : 201,
        );
    }
}
