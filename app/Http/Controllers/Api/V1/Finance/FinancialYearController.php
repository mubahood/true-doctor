<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Exceptions\FinancialYearException;
use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FinancialYearApiRequest;
use App\Http\Resources\FinancialYearResource;
use App\Models\FinancialYear;
use App\Services\FinancialYearService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accounting periods (web: admin.financial-years.*), authorised by
 * FinancialYearPolicy exactly as the pages are: view = finance.view,
 * open/close/reopen = finance.manage. Closing freezes the period for posting.
 */
class FinancialYearController extends Controller
{
    use AnswersFinanceRequests;

    /** finance.view. ?q= on the name. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinancialYear::class);

        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $rows = FinancialYear::query()->with('closedBy')
            ->when($q !== '', fn ($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->orderByDesc('starts_on')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, FinancialYearResource::collection($rows->getCollection())->resolve());
    }

    /** finance.view. The period and its roll-up (FinancialYearService::report). */
    public function show(FinancialYear $financialYear, FinancialYearService $service): JsonResponse
    {
        $this->authorize('view', $financialYear);

        return ApiResponse::success((new FinancialYearResource($financialYear->load('closedBy')))->resolve() + [
            'report' => $service->report($financialYear),
        ]);
    }

    /** finance.manage. {name, starts_on, ends_on}; periods may not overlap. */
    public function store(FinancialYearApiRequest $request, FinancialYearService $service): JsonResponse
    {
        $this->authorize('manage', FinancialYear::class);

        try {
            $year = $service->create($request->validated());
        } catch (FinancialYearException|\DomainException $e) {
            return $this->refuse($e);
        }

        return ApiResponse::success((new FinancialYearResource($year))->resolve(), 'Financial year created.', 201);
    }

    /** finance.manage. Freeze the period. */
    public function close(Request $request, FinancialYear $financialYear, FinancialYearService $service): JsonResponse
    {
        $this->authorize('manage', FinancialYear::class);

        try {
            $service->close($financialYear, $request->user()->id);
        } catch (FinancialYearException|\DomainException $e) {
            return $this->refuse($e);
        }

        return ApiResponse::success((new FinancialYearResource($financialYear->refresh()->load('closedBy')))->resolve(), 'Period closed.');
    }

    /** finance.manage. */
    public function reopen(FinancialYear $financialYear, FinancialYearService $service): JsonResponse
    {
        $this->authorize('manage', FinancialYear::class);

        $service->reopen($financialYear);

        return ApiResponse::success((new FinancialYearResource($financialYear->refresh()->load('closedBy')))->resolve(), 'Period reopened.');
    }
}
