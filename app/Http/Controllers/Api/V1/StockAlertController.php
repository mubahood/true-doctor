<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\StockMovementReason;
use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Services\StockAlertBoard;
use App\Services\StockService;
use App\Support\ApiResponse;
use App\Support\Practice\Practice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stock alerts (web: Stock alerts). pharmacy.view to read; the sweep needs
 * pharmacy.manage on each line. Four lanes, worst first - out, expired, low,
 * expiring - from StockAlertBoard, the page's own rules.
 */
class StockAlertController extends Controller
{
    private const LANES = ['out', 'expired', 'low', 'expiring'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockItem::class);

        $board = StockAlertBoard::forCategory($request->query('category'));
        $lane = (string) $request->query('lane', '');
        $lanes = in_array($lane, self::LANES, true) ? [$lane] : self::LANES;

        $rows = [];
        foreach ($lanes as $name) {
            $items = match ($name) {
                'out' => $board->outOfStock(),
                'expired' => $board->expired(),
                'low' => $board->lowStock(),
                'expiring' => $board->expiring(),
            };
            $rows[$name] = $items->map(fn (StockItem $i) => $this->row($i))->values();
        }

        return ApiResponse::success([
            'summary' => array_merge($board->atRisk(), ['value' => number_format((float) $board->atRisk()['value'], 2, '.', '')]),
            'lanes' => $rows,
            'horizon_days' => StockAlertBoard::EXPIRY_HORIZON_DAYS,
            'urgent_days' => StockAlertBoard::EXPIRY_URGENT_DAYS,
            'sweep_reasons' => array_map(fn (StockMovementReason $r) => $r->value, StockMovementReason::losses()),
            // The shelves the category filter offers (active ones, as the web's board does).
            'categories' => \App\Models\StockCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'module_label' => Practice::current()->moduleName('pharmacy', 'Pharmacy'),
        ]);
    }

    /**
     * Write off several expired lines under one reason: one ledger movement
     * each, and the ones that could not be written off are named.
     */
    public function sweep(Request $request, StockService $stock): JsonResponse
    {
        $this->authorize('update', new StockItem);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['string'],
            'reason' => ['required', Rule::in(array_map(fn (StockMovementReason $r) => $r->value, StockMovementReason::losses()))],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'note.required' => 'Say why these are being written off — this is the record somebody audits.',
        ]);

        // Only lines the board calls expired can be swept.
        $expired = StockAlertBoard::forCategory(null)->expired()->keyBy('uuid');
        $picked = collect($data['items'])->unique()->map(fn ($uuid) => $expired->get($uuid))->filter();

        if ($picked->count() !== count(array_unique($data['items']))) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, 'Only expired lines still on the shelf can be swept.', 422, ['items' => ['Only expired lines still on the shelf can be swept.']]);
        }

        foreach ($picked as $item) {
            $this->authorize('update', $item);
        }

        $result = (new StockAlertBoard)->sweep($picked, StockMovementReason::from($data['reason']), $data['note'], $request->user()->id, $stock);
        $ok = $result['refused'] === [];

        return ApiResponse::success($result, $ok
            ? $result['done'].' '.\Illuminate\Support\Str::plural('line', $result['done']).' written off.'
            : $result['done'].' written off; could not write off '.implode(', ', $result['refused']).'.');
    }

    /** @return array<string,mixed> */
    private function row(StockItem $i): array
    {
        return (new \App\Http\Resources\StockItemResource($i))->resolve() + [
            'days_left' => StockAlertBoard::daysLeft($i),
            'expiry_tone' => StockAlertBoard::expiryTone($i),
            'short_by' => StockAlertBoard::shortBy($i),
            'shortfall_value' => StockAlertBoard::shortfallValue($i),
        ];
    }
}
