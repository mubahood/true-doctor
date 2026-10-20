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
use App\Models\User;
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
    /** The most one visit's list returns; more is reported in meta.truncated. */
    private const LIST_CAP = 200;

    public function __construct(private readonly OrderDesk $desk, private readonly OrderService $orders) {}

    /** Everything on this visit, newest first, with a count per kind. */
    public function index(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        // A visit has a handful of orders, but "a handful" is not a bound: cap
        // the slice and say so (meta.total / meta.truncated).
        $query = Order::where('visit_id', $visit->id)
            ->when(OrderType::tryFrom((string) $request->query('type')), fn ($q, $t) => $q->where('type', $t->value));
        $total = (clone $query)->count();
        $orders = $query->with(['items', 'assignee', 'department', 'subject', 'editor', 'visit'])
            ->latest('id')->limit(self::LIST_CAP)->get();

        $counts = Order::where('visit_id', $visit->id)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');

        return ApiResponse::withMeta([
            'orders' => OrderResource::collection($orders),
            'counts' => collect(OrderType::cases())->mapWithKeys(fn ($t) => [$t->value => (int) ($counts[$t->value] ?? 0)]),
            'writable' => OrderDesk::canWrite($request->user()) && $visit->isOpen(),
        ], ['total' => $total, 'returned' => $orders->count(), 'limit' => self::LIST_CAP, 'truncated' => $total > self::LIST_CAP]);
    }

    /** Place one order of any kind. */
    public function store(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        // Only the kinds this practice is offered: a clinic with no wards is
        // never offered an admission, and is refused one here.
        $type = OrderType::from($request->validate(
            ['type' => ['required', Rule::in(array_map(fn (OrderType $t) => $t->value, OrderDesk::kinds()))]],
            ['type.in' => 'This practice does not offer that kind of order.'],
        )['type']);
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

    /**
     * Change an order: its service (title), instructions, assigned clinician
     * and, until the visit is invoiced, its charge. Every field is optional.
     * Idempotent like every other write (Idempotency-Key).
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $data = $request->validate(OrderDesk::editRules($order), OrderDesk::messages() + [
            'lines.*.quantity.gt' => 'Quantity must be more than zero.',
            'quantity.gt' => 'Quantity must be more than zero.',
        ]);

        try {
            $this->desk->edit($order, $data, $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($order), 'Order updated.');
    }

    /** Who an order can be assigned to: the people who see patients here. */
    public function assignees(Request $request): JsonResponse
    {
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $q = trim((string) $request->query('q', ''));

        $rows = User::currentHospital()->clinicians()->where('is_active', true)
            ->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
            ->orderBy('name')->limit(OrderDesk::PICK_LIMIT)->get(['id', 'name', 'role'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) $u->name, 'role' => (string) $u->role]);

        return ApiResponse::success([
            'label' => OrderDesk::wording()['assignee'],
            'people' => $rows->values(),
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
            // For a service priced as a range; checked against it, ignored otherwise.
            'unit_price' => ['nullable', 'numeric', 'min:0'],
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
                : $this->orders->addServiceItem($order, Service::findOrFail($data['service_id']), (string) $data['quantity'], $request->user()->id,
                    isset($data['unit_price']) ? (string) $data['unit_price'] : null);
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
        return (new OrderResource($order->fresh(['items', 'assignee', 'department', 'subject', 'editor', 'visit'])))->resolve();
    }
}
