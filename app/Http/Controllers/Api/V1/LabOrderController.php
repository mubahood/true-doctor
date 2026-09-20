<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabOrderResource;
use App\Models\LabOrder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only lab orders for the lab/integration clients (lab.view). */
class LabOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lab.view'), 403);

        $orders = LabOrder::with(['patient', 'items'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::paginated($orders, LabOrderResource::collection($orders->items()));
    }

    public function show(Request $request, LabOrder $labOrder): JsonResponse
    {
        abort_unless($request->user()->can('lab.view'), 403);

        return ApiResponse::success(new LabOrderResource($labOrder->load(['patient', 'items'])));
    }
}
