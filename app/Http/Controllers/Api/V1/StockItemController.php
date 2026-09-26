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

        // The web register's filters (StockItem::listed): low, expiring,
        // expired; a category; a name — and its shelf figures in meta.
        $items = StockItem::with('category')
            ->listed((string) $request->query('filter', ''), $request->query('category'), (string) $request->query('q', ''))
            ->orderBy('name')
            ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

        return ApiResponse::paginated($items, StockItemResource::collection($items->items()), [
            'shelf' => StockItem::shelf(),
            'expiry_horizon_days' => StockItem::EXPIRY_HORIZON_DAYS,
            'categories' => \App\Models\StockCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
        ]);
    }

    public function show(StockItem $stock): JsonResponse
    {
        $this->authorize('view', $stock);

        return ApiResponse::success(new StockItemResource($stock->load('category')));
    }
}
