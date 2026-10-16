<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementReason;
use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\StockLedger;
use App\Support\ApiResponse;
use App\Support\Practice\Practice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The stock ledger (web: Stock ledger): every movement, in and out, store-wide.
 * pharmacy.view. Filters: item (uuid), direction (in|out), reason, from / to
 * (dates), q (item name or note); sort created_at|quantity|reason, dir asc|desc.
 */
class StockLedgerController extends Controller
{
    /** Sums come back from the database unpadded on some engines; money is two decimals. */
    private function money(array $totals, array $keys): array
    {
        foreach ($keys as $k) {
            $totals[$k] = number_format((float) $totals[$k], 2, '.', '');
        }

        return $totals;
    }

    private const SORTS = ['created_at', 'quantity', 'reason'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockItem::class);

        $request->validate([
            'direction' => ['nullable', 'in:in,out'],
            'reason' => ['nullable', 'in:'.implode(',', array_keys(StockMovementReason::options()))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        // The item is named by its uuid, as everywhere in the API.
        $item = (string) $request->query('item', '');
        $itemId = $item === '' ? '' : (string) (StockItem::where('uuid', $item)->value('id') ?? 0);

        $filtered = StockLedger::filtered([
            'item' => $itemId, 'reason' => $request->query('reason'), 'direction' => $request->query('direction'),
            'from' => $request->query('from'), 'to' => $request->query('to'),
            'search' => trim(mb_substr((string) $request->query('q', ''), 0, 100)),
        ]);

        $sort = (string) $request->query('sort', '');
        $rows = (clone $filtered)->with(['stockItem.category', 'createdBy']);
        in_array($sort, self::SORTS, true)
            ? $rows->orderBy($sort, $request->query('dir') === 'asc' ? 'asc' : 'desc')
            : $rows->latest('id');

        $page = $rows->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return ApiResponse::paginated($page, array_map(fn (StockMovement $m) => [
            'id' => $m->id,
            'item' => $m->stockItem === null ? null : [
                'uuid' => $m->stockItem->uuid, 'name' => $m->stockItem->name, 'unit' => $m->stockItem->unit,
                'category' => $m->stockItem->category?->name,
            ],
            'reason' => $m->reason->value,
            'reason_label' => $m->reason->label(),
            'direction' => $m->reason->isIncoming() ? 'in' : 'out',
            'quantity' => $m->quantity,
            'balance_after' => $m->balance_after,
            'unit_cost' => $m->unit_cost,
            'unit_price' => $m->unit_price,
            'cost_value' => $m->costValue(),
            'sale_value' => $m->saleValue(),
            'margin' => $m->margin(),
            'note' => $m->note,
            'by' => $m->createdBy?->name,
            'source' => $m->source_type === null ? null : ['type' => class_basename($m->source_type), 'id' => $m->source_id],
            'at' => $m->created_at?->toIso8601String(),
        ], $page->items()), [
            'totals' => $this->money(StockLedger::totals($filtered), ['in', 'out']),
            'reasons' => StockMovementReason::options(),
            'module_label' => Practice::current()->moduleName('pharmacy', 'Pharmacy'),
        ]);
    }
}
