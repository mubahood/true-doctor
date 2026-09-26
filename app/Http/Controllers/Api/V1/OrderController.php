<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\Visit;
use App\Services\OrderDesk;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * The work on a visit — the web's orders panel and order dialog, through
 * the same OrderDesk and OrderService (docs/orders.md). Reading needs the
 * visit; writing needs visits.create or visits.manage (OrderDesk::canWrite)
 * plus whatever the kind of work asks for.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderDesk $desk, private readonly OrderService $orders) {}

    /** Everything on this visit, newest first, with a count per kind. */
    public function index(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        $orders = Order::where('visit_id', $visit->id)
            ->when(OrderType::tryFrom((string) $request->query('type')), fn ($q, $t) => $q->where('type', $t->value))
            ->with(['items', 'assignee', 'department', 'subject'])
            ->latest('id')->get();

        $counts = Order::where('visit_id', $visit->id)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');

        return ApiResponse::success([
            'orders' => OrderResource::collection($orders),
            'counts' => collect(OrderType::cases())->mapWithKeys(fn ($t) => [$t->value => (int) ($counts[$t->value] ?? 0)]),
            'writable' => OrderDesk::canWrite($request->user()) && $visit->isOpen(),
        ]);
    }

    /** Place one order of any kind. */
    public function store(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $type = OrderType::from($request->validate(['type' => ['required', Rule::enum(OrderType::class)]])['type']);
        $data = $request->validate(OrderDesk::rules($type), OrderDesk::messages());

        try {
            $message = $this->desk->place($visit, $type, $data, $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422,
                $type === OrderType::Admission ? ['bed_id' => [$e->getMessage()]] : null);
        }

        return ApiResponse::success(['placed' => true], $message, 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order->visit);

        return ApiResponse::success($this->one($order) + [
            // What cancelling would undo — money off the bill, goods back on the shelf.
            'reversal' => $this->orders->reversalPlan($order),
        ]);
    }

    /** In progress, or done (for a stay: a discharge). */
    public function move(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $data = $request->validate(['status' => ['required', Rule::enum(OrderStatus::class)], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $message = $this->desk->move($order, OrderStatus::from($data['status']), $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($order), $message);
    }

    /** Called off — the charges come off the bill and the goods go back. */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        try {
            $this->orders->transition($order, OrderStatus::Cancelled, $request->user()->id, $reason ?: null);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($order), 'Order cancelled. Its charges are off the bill.');
    }

    public function report(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        // The dialog's rule: max 20,000, and only while the order can be changed.
        $report = $request->validate(['report' => ['nullable', 'string', 'max:20000']])['report'] ?? null;

        try {
            $this->orders->assertItemsEditable($order);
            $this->orders->saveReport($order, $report);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($order), 'Report saved.');
    }

    /** Record what the work used — a service or a product (which leaves the shelf). */
    public function addItem(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['service', 'product'])],
            'service_id' => ['required_if:kind,service', 'nullable', 'integer'],
            'stock_item_id' => ['required_if:kind,product', 'nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'service_id.required_if' => 'Choose a service.',
            'stock_item_id.required_if' => 'Choose a product.',
            'quantity.gt' => 'Quantity must be more than zero.',
        ]);

        try {
            $this->orders->assertItemsEditable($order);
            $line = $data['kind'] === 'product'
                ? $this->orders->addProductItem($order, StockItem::findOrFail($data['stock_item_id']), (string) $data['quantity'], $request->user()->id)
                : $this->orders->addServiceItem($order, Service::findOrFail($data['service_id']), (string) $data['quantity'], $request->user()->id);
            if (($data['notes'] ?? '') !== '') {
                $line->update(['notes' => trim((string) $data['notes'])]);
            }
        } catch (RuntimeException $e) {
            // "Not enough Amoxicillin in stock" reaches the reader as itself.
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($order), 'Added.', 201);
    }

    public function removeItem(Request $request, Order $order, int $item): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $line = OrderItem::where('order_id', $order->id)->findOrFail($item);

        try {
            $this->orders->assertItemsEditable($order);
            $this->orders->removeItem($line, $request->user()->id);
        } catch (Throwable $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e instanceof RuntimeException ? $e->getMessage() : 'That could not be removed.', 422);
        }

        return ApiResponse::success($this->one($order), 'Removed.');
    }

    /** What an order of this kind is placed from, and how it is usually worded. */
    public function catalogue(Request $request): JsonResponse
    {
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $type = (string) $request->query('type', '');

        return ApiResponse::success(OrderDesk::catalogue($type, (string) $request->query('q', ''), array_map('intval', (array) $request->query('chosen', [])))
            + ['titles' => OrderDesk::titleSuggestions($type)]);
    }

    /** Services and products an order's lines are picked from. */
    public function lines(Request $request): JsonResponse
    {
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $q = trim((string) $request->query('q', ''));
        $kind = $request->query('kind') === 'product' ? 'product' : 'service';

        $rows = $kind === 'product'
            ? StockItem::where('is_active', true)->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
                ->orderBy('name')->limit(OrderDesk::PICK_LIMIT)->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'price' => (string) $s->sale_price, 'on_hand' => (string) $s->current_quantity, 'unit' => $s->unit])
            : Service::where('is_active', true)->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
                ->orderBy('name')->limit(OrderDesk::PICK_LIMIT)->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'price' => (string) $s->price]);

        return ApiResponse::success($rows->values());
    }

    /** @return array<string,mixed> */
    private function one(Order $order): array
    {
        return (new OrderResource($order->fresh(['items', 'assignee', 'department', 'subject'])))->resolve();
    }
}
