<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PagesAndDeltas;
use App\Http\Controllers\Api\V1\Concerns\WritesCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockItemRequest;
use App\Http\Resources\StockItemResource;
use App\Models\StockItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The stock register (web: Stock items). Read with pharmacy.view; created,
 * edited and deleted with pharmacy.manage (StockItemPolicy), validated by
 * StockItemRequest::rulesFor() as the web's form is. A quantity is never set
 * here: it moves only through the ledger (receive / adjust / write-off).
 * An item with movement history cannot be deleted - deactivate it instead.
 */
class StockItemController extends Controller
{
    use PagesAndDeltas, WritesCatalogue {
        PagesAndDeltas::perPage insteadof WritesCatalogue;
    }

    private const FIELDS = ['name', 'stock_category_id', 'sku', 'unit', 'batch_no', 'expiry_date', 'cost_price', 'sale_price', 'reorder_level', 'is_active'];

    private const NULLABLE = ['sku', 'batch_no', 'expiry_date', 'sale_price'];

    public function store(Request $request): JsonResponse
    {
        [$item, $state] = $this->createRow(
            $request, StockItem::class, fn ($id) => StockItemRequest::rulesFor($id), self::FIELDS, self::NULLABLE,
            ['is_active' => true], ['uuid' => (string) Str::uuid()],
        );

        return $this->saved('Stock item', $state, new StockItemResource($item->load('category')));
    }

    public function update(Request $request, StockItem $stock): JsonResponse
    {
        [$item, $state] = $this->updateRow($request, $stock, fn ($id) => StockItemRequest::rulesFor($id), self::FIELDS, self::NULLABLE);

        return $this->saved('Stock item', $state, new StockItemResource($item->load('category')));
    }

    public function destroy(StockItem $stock): JsonResponse
    {
        return $this->destroyRow($stock, 'Stock item');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockItem::class);

        // The web register's filters (StockItem::listed): low, expiring,
        // expired; a category; a name — and its shelf figures in meta.
        $items = $this->applyDelta(StockItem::with('category')
            ->listed((string) $request->query('filter', ''), $request->query('category'), (string) $request->query('q', ''))
            ->orderBy('name'), $request)
            ->paginate($this->perPage($request, 25));

        return $this->deltaPage($request, $items, StockItemResource::class, [
            'shelf' => StockItem::shelf(),
            'expiry_horizon_days' => StockItem::EXPIRY_HORIZON_DAYS,
            // What this practice calls the store ("Drugs inventory" at a dental clinic).
            'module_label' => \App\Support\Practice\Practice::current()->moduleName('pharmacy', 'Pharmacy'),
            'categories' => \App\Models\StockCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
        ]);
    }

    public function show(StockItem $stock): JsonResponse
    {
        $this->authorize('view', $stock);

        return ApiResponse::success(new StockItemResource($stock->load('category')));
    }
}
