<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\WritesCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\LabTestRequest;
use App\Http\Resources\LabTestResource;
use App\Models\LabTest;
use App\Support\ApiResponse;
use App\Support\CatalogueStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The lab test catalogue (web: Lab tests). lab.view to read, lab.manage to write (LabTestPolicy). */
class LabTestController extends Controller
{
    use WritesCatalogue;

    private const FIELDS = ['name', 'code', 'specimen', 'unit', 'reference_range', 'price', 'is_active'];

    private const NULLABLE = ['code', 'specimen', 'unit', 'reference_range'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LabTest::class);

        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $rows = LabTest::query()->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
            ->orderBy('name')->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, LabTestResource::collection($rows->items()));
    }

    public function show(LabTest $labTest): JsonResponse
    {
        $this->authorize('viewAny', LabTest::class);

        return ApiResponse::success((new LabTestResource($labTest))->resolve() + ['usage' => CatalogueStore::usage($labTest)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$test, $state] = $this->createRow($request, LabTest::class, fn ($id) => LabTestRequest::rulesFor($id), self::FIELDS, self::NULLABLE, ['is_active' => true]);

        return $this->saved('Lab test', $state, new LabTestResource($test));
    }

    public function update(Request $request, LabTest $labTest): JsonResponse
    {
        [$test, $state] = $this->updateRow($request, $labTest, fn ($id) => LabTestRequest::rulesFor($id), self::FIELDS, self::NULLABLE);

        return $this->saved('Lab test', $state, new LabTestResource($test));
    }

    public function destroy(LabTest $labTest): JsonResponse
    {
        return $this->destroyRow($labTest, 'Lab test');
    }
}
