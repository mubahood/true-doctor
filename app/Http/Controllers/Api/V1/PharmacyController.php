<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DispensationRequest;
use App\Http\Requests\StockAdjustRequest;
use App\Http\Requests\StockReceiveRequest;
use App\Http\Requests\StockWriteOffRequest;
use App\Http\Resources\StockItemResource;
use App\Models\StockItem;
use App\Models\Visit;
use App\Services\DispensationService;
use App\Services\StockService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The pharmacy through the API: dispensing on a visit (billed as it goes),
 * and moving stock — received, adjusted, written off — through the same
 * services, requests and policies as the web's pharmacy screens. A movement
 * that would take the shelf below nothing is refused, in words.
 */
class PharmacyController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    /** What has been dispensed on this visit. */
    public function dispensations(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        $out = [];
        foreach ($visit->dispensations()->with(['items', 'dispensedBy'])->latest()->get() as $d) {
            $items = [];
            foreach ($d->items as $i) {
                $items[] = ['name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'line_total' => $i->line_total];
            }
            $out[] = [
                'uuid' => $d->uuid,
                'note' => $d->note,
                'dispensed_by' => $d->dispensedBy?->name,
                'created_at' => $d->created_at?->toIso8601String(),
                'items' => $items,
            ];
        }

        return ApiResponse::success($out);
    }

    /** Dispense and bill, in one go; a shortfall names the drug and the row. */
    public function dispense(DispensationRequest $request, Visit $visit, DispensationService $service): JsonResponse
    {
        $this->authorize('view', $visit);
        abort_unless($request->user()->can('pharmacy.dispense'), 403);

        $data = $request->validated();
        $lines = array_map(fn (array $row) => [
            'stock_item_id' => (int) $row['stock_item_id'],
            'quantity' => (string) $row['quantity'],
        ], array_values($data['items']));

        try {
            $service->dispense($visit, $lines, $data['note'] ?? null, $request->user()->id, $data['prescription_id'] ?? null);
        } catch (InsufficientStockException $e) {
            $row = $this->shortfallRow($lines);

            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ["items.{$row}.quantity" => [$e->getMessage()]]);
        }

        return ApiResponse::success(['dispensed' => true], 'Dispensed and billed.', 201);
    }

    /** One item's ledger, newest first: every movement and the balance after it. */
    public function movements(Request $request, StockItem $stock): JsonResponse
    {
        $this->authorize('view', $stock);

        $moves = $stock->movements()->with('creator')->latest('id')->paginate(min(max((int) $request->query('per_page', 30), 1), 100));

        $rows = [];
        foreach ($moves->items() as $m) {
            /** @var \App\Models\StockMovement $m */
            $rows[] = [
                'reason' => $m->reason->value,
                'quantity' => $m->quantity,
                'balance_after' => $m->balance_after,
                'unit_cost' => $m->unit_cost,
                'note' => $m->note,
                'by' => $m->creator?->name,
                'at' => $m->created_at?->toIso8601String(),
            ];
        }

        return ApiResponse::paginated($moves, $rows, [
            'item' => (new StockItemResource($stock->load('category')))->resolve(),
            'adjust_reasons' => array_map(fn (StockMovementReason $r) => $r->value, StockAdjustRequest::reasons()),
            'loss_reasons' => array_map(fn (StockMovementReason $r) => $r->value, StockMovementReason::losses()),
        ]);
    }

    public function receive(StockReceiveRequest $request, StockItem $stock): JsonResponse
    {
        $this->authorize('update', $stock);
        $data = $request->validate(array_merge(StockReceiveRequest::rulesFor(), [
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]));

        return $this->moved(fn () => $this->stock->receive(
            $stock,
            self::amount($data['quantity']),
            filled($data['unit_cost'] ?? null) ? self::amount($data['unit_cost']) : null,
            $request->user()->id,
            ($data['note'] ?? null) ?: null,
            filled($data['sale_price'] ?? null) ? self::amount($data['sale_price']) : null,
        ), $stock, 'Stock received.');
    }

    public function adjust(StockAdjustRequest $request, StockItem $stock): JsonResponse
    {
        $this->authorize('update', $stock);
        $data = $request->validated();

        return $this->moved(fn () => $this->stock->adjust(
            $stock, StockMovementReason::from($data['reason']), self::amount($data['quantity']), $request->user()->id, ($data['note'] ?? null) ?: null,
        ), $stock, 'Adjustment recorded.');
    }

    public function writeOff(StockWriteOffRequest $request, StockItem $stock): JsonResponse
    {
        $this->authorize('update', $stock);
        $data = $request->validated();

        return $this->moved(fn () => $this->stock->adjust(
            $stock, StockMovementReason::from($data['reason']), self::amount($data['quantity']), $request->user()->id, $data['note'],
        ), $stock, 'Written off, and the reason is on the record.');
    }

    private function moved(callable $move, StockItem $stock, string $message): JsonResponse
    {
        try {
            $move();
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['quantity' => [$e->getMessage()]]);
        }

        return ApiResponse::success((new StockItemResource($stock->fresh('category')))->resolve(), $message);
    }

    /**
     * Which row the shortfall came from. The transaction rolled back, so the
     * shelf is exactly as it was when the service refused.
     *
     * @param  list<array{stock_item_id:int,quantity:string}>  $lines
     */
    private function shortfallRow(array $lines): int
    {
        foreach ($lines as $i => $line) {
            $item = StockItem::find($line['stock_item_id']);
            if ($item !== null && bccomp((string) $item->current_quantity, self::amount($line['quantity']), 2) < 0) {
                return $i;
            }
        }

        return 0;
    }

    /** Two decimal places, as the ledger stores it. */
    private static function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
