<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\WritesCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockCategoryRequest;
use App\Http\Resources\StockCategoryResource;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Services\StockAlertBoard;
use App\Support\ApiResponse;
use App\Support\Practice\Practice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock categories - the shelves (web: Stock categories). pharmacy.view to
 * read, pharmacy.manage to write (StockCategoryPolicy). A category that still
 * holds items cannot be deleted.
 */
class StockCategoryController extends Controller
{
    use WritesCatalogue;

    private const FIELDS = ['name', 'unit', 'description', 'is_active'];

    private const SORTS = ['name', 'items_count', 'on_hand', 'stock_value', 'low_count', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockCategory::class);

        $horizon = now()->addDays(StockAlertBoard::EXPIRY_HORIZON_DAYS)->toDateString();
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $status = (string) $request->query('status', '');
        $sort = (string) $request->query('sort', '');

        $query = StockCategory::query()->withShelfFigures($horizon)
            ->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($x) => $x->where('is_active', $status === 'active'))
            ->when($request->boolean('attention'), fn ($x) => $x->needingAttention($horizon));

        in_array($sort, self::SORTS, true)
            ? $query->orderBy($sort, $request->query('dir') === 'desc' ? 'desc' : 'asc')
            : $query->orderBy('name');

        $rows = $query->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, StockCategoryResource::collection($rows->items()), [
            'store' => [
                'categories' => StockCategory::count(),
                'items' => StockItem::where('is_active', true)->count(),
                'low' => StockItem::where('is_active', true)->lowStock()->count(),
                'expiring' => StockItem::where('is_active', true)->expiringBefore($horizon)->count(),
                'quantity' => number_format((float) StockItem::where('is_active', true)->sum('current_quantity'), 2, '.', ''),
                'value' => number_format((float) StockItem::where('is_active', true)->sum('current_stock_value'), 2, '.', ''),
            ],
            'expiry_horizon_days' => StockAlertBoard::EXPIRY_HORIZON_DAYS,
            'module_label' => Practice::current()->moduleName('pharmacy', 'Pharmacy'),
        ]);
    }

    /** One category and the eight lines on it that need the most attention (least left first). */
    public function show(StockCategory $stockCategory): JsonResponse
    {
        $this->authorize('viewAny', StockCategory::class);

        $category = StockCategory::withShelfFigures(now()->addDays(StockAlertBoard::EXPIRY_HORIZON_DAYS)->toDateString())->findOrFail($stockCategory->id);

        return ApiResponse::success((new StockCategoryResource($category))->resolve() + [
            'items' => StockItem::where('stock_category_id', $category->id)->where('is_active', true)
                ->orderBy('current_quantity')->limit(8)->get()
                ->map(fn (StockItem $i) => ['uuid' => $i->uuid, 'name' => $i->name, 'unit' => $i->unit, 'current_quantity' => $i->current_quantity, 'is_low_stock' => $i->isLowStock()])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$category, $state] = $this->createRow($request, StockCategory::class, fn ($id) => StockCategoryRequest::rulesFor($id), self::FIELDS, ['description'], ['is_active' => true]);

        return $this->saved('Category', $state, new StockCategoryResource($category));
    }

    public function update(Request $request, StockCategory $stockCategory): JsonResponse
    {
        [$category, $state] = $this->updateRow($request, $stockCategory, fn ($id) => StockCategoryRequest::rulesFor($id), self::FIELDS, ['description']);

        return $this->saved('Category', $state, new StockCategoryResource($category));
    }

    public function destroy(StockCategory $stockCategory): JsonResponse
    {
        return $this->destroyRow($stockCategory, 'Category');
    }
}
