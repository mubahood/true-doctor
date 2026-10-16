<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\WritesCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\RadiologyStudyRequest;
use App\Http\Resources\RadiologyStudyResource;
use App\Models\RadiologyStudy;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The radiology study catalogue (web: Radiology studies). radiology.view to read, radiology.manage to write. */
class RadiologyStudyController extends Controller
{
    use WritesCatalogue;

    private const FIELDS = ['name', 'modality', 'body_part', 'price', 'is_active'];

    private const NULLABLE = ['modality', 'body_part'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RadiologyStudy::class);

        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $rows = RadiologyStudy::query()->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
            ->orderBy('name')->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, RadiologyStudyResource::collection($rows->items()));
    }

    public function show(RadiologyStudy $radiologyStudy): JsonResponse
    {
        $this->authorize('viewAny', RadiologyStudy::class);

        return ApiResponse::success((new RadiologyStudyResource($radiologyStudy))->resolve() + ['usage' => CatalogueStore::usage($radiologyStudy)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$study, $state] = $this->createRow($request, RadiologyStudy::class, fn ($id) => RadiologyStudyRequest::rulesFor($id), self::FIELDS, self::NULLABLE, ['is_active' => true]);

        return $this->saved('Study', $state, new RadiologyStudyResource($study));
    }

    public function update(Request $request, RadiologyStudy $radiologyStudy): JsonResponse
    {
        [$study, $state] = $this->updateRow($request, $radiologyStudy, fn ($id) => RadiologyStudyRequest::rulesFor($id), self::FIELDS, self::NULLABLE);

        return $this->saved('Study', $state, new RadiologyStudyResource($study));
    }

    public function destroy(RadiologyStudy $radiologyStudy): JsonResponse
    {
        return $this->destroyRow($radiologyStudy, 'Study');
    }
}
