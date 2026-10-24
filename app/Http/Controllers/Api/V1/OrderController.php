<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\InsufficientStockException;
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
        ] + self::practice(), ['total' => $total, 'returned' => $orders->count(), 'limit' => self::LIST_CAP, 'truncated' => $total > self::LIST_CAP]);
    }

    /**
     * What this practice calls an order's fields and where a product line
     * starts, so the app words and defaults its forms the way the web's do
     * without reading them from /meta: `labels` (OrderDesk::wording — a dental
     * clinic's "Treatment" and "Dentist assigned"), `asks_teeth` (a procedure
     * names its teeth here) and `products_billed` (Billed or Not billed when a
     * product is added; HospitalSettings::productsBilledByDefault).
     *
     * @return array{labels:array{kind:string,title:string,assignee:string},asks_teeth:bool,products_billed:bool}
     */
    private static function practice(): array
    {
        return [
            'labels' => OrderDesk::wording(),
            'asks_teeth' => \App\Services\DentalService::teethOnOrders(),
            'products_billed' => app(\App\Support\HospitalSettings::class)->productsBilledByDefault(),
        ];
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
     * Correct one line in place (web: the order dialog's pencil, saveItem):
     * its quantity, the note beside it and, on a service priced as a range,
     * its price inside the range. A product's quantity is how much left the
     * shelf, so the difference moves stock either way, in the same
     * transaction as the money (OrderService::updateItem). A field left out
     * keeps what the line has. Refused once the order is cancelled or the
     * visit invoiced, and for a removed line, with the reason.
     */
    public function updateItem(Request $request, Order $order, int $item): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $line = OrderItem::where('order_id', $order->id)->findOrFail($item);

        $data = $request->validate([
            // Plain decimals only: "1e3" passes `numeric` but not bcmath.
            'quantity' => ['sometimes', 'required', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Only a service priced as a range moves, and only inside it (OrderService::allowedPrice).
            'unit_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
        ], [
            'quantity.gt' => 'Quantity must be more than zero.',
            'quantity.decimal' => 'Quantity is a number with at most two decimals.',
        ]);

        $quantity = array_key_exists('quantity', $data) ? (string) $data['quantity'] : (string) $line->quantity;
        $notes = array_key_exists('notes', $data) ? (trim((string) $data['notes']) ?: null) : $line->notes;
        $price = isset($data['unit_price']) && $data['unit_price'] !== '' ? (string) $data['unit_price'] : null;

        try {
            // The order cancelled or the visit invoiced: about the order, not a field.
            $this->orders->assertItemsEditable($order);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        if ($price !== null) {
            try {
                // A fixed price, a product, a charge another record placed, or outside the range.
                $this->orders->priceFor($line, $price);
            } catch (RuntimeException $e) {
                return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['unit_price' => [$e->getMessage()]]);
            }
        }

        try {
            $this->orders->updateItem($line, $quantity, $notes, $request->user()->id, $price);
        } catch (InsufficientStockException $e) {
            // "Not enough Amoxicillin in stock" reaches the reader as itself, by the quantity.
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['quantity' => [$e->getMessage()]]);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($order), 'Line updated.');
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
            // A product only: billed to the patient, or given without billing
            // (docs/orders.md). Left out, the hospital's choice.
            'billed' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'service_id.required_if' => 'Choose a service.',
            'stock_item_id.required_if' => 'Choose a product.',
            'quantity.gt' => 'Quantity must be more than zero.',
        ]);

        try {
            $this->orders->assertItemsEditable($order);
            $line = $data['kind'] === 'product'
                ? $this->orders->addProductItem($order, StockItem::findOrFail($data['stock_item_id']), (string) $data['quantity'], $request->user()->id,
                    isset($data['billed']) ? (bool) $data['billed'] : null)
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

    /**
     * Bill a product line, or take it off the bill. The stock stays where it
     * is; refused once the visit is invoiced (docs/orders.md).
     */
    public function setBilled(Request $request, Order $order, int $item): JsonResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless(OrderDesk::canWrite($request->user()), 403);

        $billed = (bool) $request->validate(['billed' => ['required', 'boolean']])['billed'];
        $line = OrderItem::where('order_id', $order->id)->findOrFail($item);

        try {
            $this->orders->setBilled($line, $billed, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->one($order), $billed ? 'Billed.' : 'Not billed.');
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
            + ['titles' => OrderDesk::titleSuggestions($type)] + self::practice());
    }

    /**
     * Services and products an order's lines are picked from, and the bill's
     * Add charge (whoever takes payments may add a charge: billing.manage).
     * A service priced as a range carries `max_price` (null for a fixed price).
     */
    public function lines(Request $request): JsonResponse
    {
        abort_unless(OrderDesk::canWrite($request->user()) || $request->user()->can('billing.manage'), 403);

        $q = trim((string) $request->query('q', ''));
        $kind = $request->query('kind') === 'product' ? 'product' : 'service';

        $rows = $kind === 'product'
            ? StockItem::where('is_active', true)->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
                ->orderBy('name')->limit(OrderDesk::PICK_LIMIT)->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'price' => (string) $s->sale_price, 'on_hand' => (string) $s->current_quantity, 'unit' => $s->unit])
            : Service::where('is_active', true)->when($q !== '', fn ($x) => $x->where('name', 'like', "%{$q}%"))
                ->orderBy('name')->limit(OrderDesk::PICK_LIMIT)->get()
                ->map(fn (Service $s) => ['id' => $s->id, 'name' => $s->name, 'price' => (string) $s->price, 'max_price' => $s->hasRange() ? (string) $s->max_price : null]);

        return ApiResponse::success($rows->values());
    }

    /** @return array<string,mixed> */
    private function one(Order $order): array
    {
        // With each line's service (its price range), and who put it on and
        // who last changed it, as the dialog shows them.
        return (new OrderResource($order->fresh(['items.service', 'items.orderedBy', 'items.updatedBy', 'assignee', 'department', 'subject', 'editor', 'visit'])))->resolve() + self::practice();
    }
}
