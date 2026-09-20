<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockItemResource;
use App\Models\StockItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only stock items for pharmacy/integration clients (pharmacy.view). */
class StockItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockItem::class);

        $items = StockItem::with('category')
            ->when($request->query('filter') === 'low', fn ($q) => $q->lowStock())
            ->when($request->query('filter') === 'expiring', fn ($q) => $q->expiringBefore(now()->addDays(90)->toDateString()))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->query('q').'%'))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ApiResponse::paginated($items, StockItemResource::collection($items->items()));
    }

    public function show(StockItem $stock): JsonResponse
    {
        $this->authorize('view', $stock);

        return ApiResponse::success(new StockItemResource($stock->load('category')));
    }
}
