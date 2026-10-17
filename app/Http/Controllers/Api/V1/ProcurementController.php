<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\WritesCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\ProcurementService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Procurement for the app (docs/api/procurement.md): suppliers, purchase
 * orders, goods received and returns. The same ProcurementService and
 * policies as the web pages. Orders, receipts and returns are addressed by
 * uuid; an order's lines and a delivery's lines by their integer `id` inside
 * the order they belong to.
 */
class ProcurementController extends Controller
{
    use WritesCatalogue;

    private const SUPPLIER_FIELDS = ['name', 'contact_person', 'phone', 'email', 'address', 'tin', 'payment_terms_days', 'notes', 'is_active'];

    private const SUPPLIER_NULLABLE = ['contact_person', 'phone', 'email', 'address', 'tin', 'payment_terms_days', 'notes'];

    public function __construct(private readonly ProcurementService $procurement) {}

    // ── Suppliers ──────────────────────────────────────────────────────

    /** GET suppliers?q=&status=active|inactive */
    public function suppliers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $page = Supplier::query()
            ->when($q !== '', fn (Builder $x) => $x->where('name', 'like', "%{$q}%"))
            ->when($status !== '', fn (Builder $x) => $x->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate($this->perPage($request, 25));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (Supplier $s) => $this->supplier($s))->all());
    }

    /** GET suppliers/{supplier} */
    public function supplierShow(Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return ApiResponse::success($this->supplier($supplier) + ['figures' => $supplier->figures()]);
    }

    /** POST suppliers */
    public function supplierStore(Request $request): JsonResponse
    {
        [$supplier, $state] = $this->createRow($request, Supplier::class, fn ($id) => SupplierRequest::rulesFor($id),
            self::SUPPLIER_FIELDS, self::SUPPLIER_NULLABLE, ['is_active' => true]);

        /** @var Supplier $supplier */
        return $this->saved('Supplier', $state, $this->supplier($supplier));
    }

    /** PUT suppliers/{supplier} */
    public function supplierUpdate(Request $request, Supplier $supplier): JsonResponse
    {
        [$saved, $state] = $this->updateRow($request, $supplier, fn ($id) => SupplierRequest::rulesFor($id),
            self::SUPPLIER_FIELDS, self::SUPPLIER_NULLABLE);

        /** @var Supplier $saved */
        return $this->saved('Supplier', $state, $this->supplier($saved));
    }

    // ── Purchase orders ────────────────────────────────────────────────

    /** GET purchase-orders?status=&supplier_uuid=&q= */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $supplierId = null;
        if ($request->filled('supplier_uuid')) {
            $supplierId = Supplier::withTrashed()->where('uuid', (string) $request->query('supplier_uuid'))->value('id') ?? -1;
        }

        $page = PurchaseOrder::query()->with('supplier')->withCount('items')
            ->listed((string) $request->query('status', ''), $supplierId, (string) $request->query('q', ''))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (PurchaseOrder $o) => $this->summary($o))->all());
    }

    /** GET purchase-orders/{purchaseOrder} */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return ApiResponse::success($this->detail($purchaseOrder));
    }

    /** POST purchase-orders - a draft, with any lines given. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $data = $request->validate([
            'supplier_uuid' => ['required', 'uuid'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.stock_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $supplier = Supplier::query()->where('uuid', $data['supplier_uuid'])->firstOrFail();
        $order = $this->procurement->createOrder($supplier, [
            'expected_date' => $data['expected_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], array_values($data['lines'] ?? []), $this->user($request));

        return ApiResponse::success($this->detail($order), $order->po_no.' started.', 201);
    }

    /** POST purchase-orders/{purchaseOrder}/lines - add to a draft. */
    public function addLine(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->procurement->addLine($purchaseOrder, $data);

        return ApiResponse::success($this->detail($purchaseOrder), 'Added to the order.', 201);
    }

    /** DELETE purchase-orders/{purchaseOrder}/lines/{line} */
    public function removeLine(PurchaseOrder $purchaseOrder, int $line): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $item = PurchaseOrderItem::query()->where('purchase_order_id', $purchaseOrder->id)->findOrFail($line);
        $this->procurement->removeLine($purchaseOrder, $item);

        return ApiResponse::success($this->detail($purchaseOrder), 'Line removed.');
    }

    /** POST purchase-orders/{purchaseOrder}/order - draft → ordered. */
    public function order(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $this->procurement->markOrdered($purchaseOrder, $this->user($request));

        return ApiResponse::success($this->detail($purchaseOrder), 'Marked as ordered.');
    }

    /** POST purchase-orders/{purchaseOrder}/close {reason} */
    public function close(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $this->procurement->close($purchaseOrder, $data['reason'], $this->user($request));

        return ApiResponse::success($this->detail($purchaseOrder), 'Order closed.');
    }

    /** POST purchase-orders/{purchaseOrder}/cancel {reason} */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $this->procurement->cancel($purchaseOrder, $data['reason'], $this->user($request));

        return ApiResponse::success($this->detail($purchaseOrder), 'Order cancelled.');
    }

    /** POST purchase-orders/{purchaseOrder}/receive - a delivery (GRN). Idempotency-Key required from the app. */
    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $data = $request->validate([
            'received_on' => ['nullable', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_order_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ]);

        $receipt = $this->procurement->receive($purchaseOrder, $data, array_values($data['lines']), $this->user($request));

        return ApiResponse::success([
            'receipt' => $this->receipt($receipt),
            'order' => $this->detail($purchaseOrder),
        ], $receipt->grn_no.' received.', 201);
    }

    /** POST purchase-orders/{purchaseOrder}/returns {goods_receipt_item_id, quantity, reason}. Idempotency-Key required from the app. */
    public function returnToSupplier(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $data = $request->validate([
            'goods_receipt_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $line = GoodsReceiptItem::query()
            ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $purchaseOrder->id))
            ->findOrFail((int) $data['goods_receipt_item_id']);

        $return = $this->procurement->returnToSupplier($line, (string) $data['quantity'], $data['reason'], $this->user($request));

        return ApiResponse::success([
            'return' => $this->returned($return),
            'order' => $this->detail($purchaseOrder),
        ], $return->return_no.' recorded.', 201);
    }

    // ── Shapes ─────────────────────────────────────────────────────────

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return array<string,mixed> */
    private function supplier(Supplier $s): array
    {
        return [
            'uuid' => $s->uuid,
            'id' => $s->id,
            'name' => $s->name,
            'contact_person' => $s->contact_person,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'tin' => $s->tin,
            'payment_terms_days' => $s->payment_terms_days,
            'notes' => $s->notes,
            'is_active' => (bool) $s->is_active,
        ];
    }

    /** @return array<string,mixed> */
    private function summary(PurchaseOrder $o): array
    {
        return [
            'uuid' => $o->uuid,
            'po_no' => $o->po_no,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'supplier' => $o->supplier ? ['uuid' => $o->supplier->uuid, 'name' => $o->supplier->name] : null,
            'order_date' => $o->order_date->toDateString(),
            'expected_date' => $o->expected_date?->toDateString(),
            'total' => (string) $o->total,
            'total_label' => HospitalSettings::money($o->total),
            'lines_count' => (int) ($o->items_count ?? $o->items()->count()),
            'updated_at' => $o->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function detail(PurchaseOrder $order): array
    {
        $o = $order->fresh(['supplier', 'items.stockItem', 'receipts.items.stockItem', 'receipts.items.returns']) ?? $order;
        $figures = $this->procurement->figures($o);

        return $this->summary($o) + [
            'notes' => $o->notes,
            'ordered_at' => $o->ordered_at?->toIso8601String(),
            'ended_at' => $o->ended_at?->toIso8601String(),
            'end_reason' => $o->end_reason,
            'figures' => $figures + array_combine(
                array_map(fn ($k) => $k.'_label', array_keys($figures)),
                array_map(fn ($v) => HospitalSettings::money($v), array_values($figures)),
            ),
            'can' => [
                'edit_lines' => $o->status === \App\Enums\PurchaseOrderStatus::Draft,
                'order' => $o->status === \App\Enums\PurchaseOrderStatus::Draft && $o->items->isNotEmpty(),
                'receive' => $o->status->canReceive(),
                'close' => $o->status === \App\Enums\PurchaseOrderStatus::PartReceived,
                'cancel' => $o->status->canTransitionTo(\App\Enums\PurchaseOrderStatus::Cancelled) && $o->receipts->isEmpty(),
            ],
            'lines' => $o->items->map(fn (PurchaseOrderItem $l) => [
                'id' => $l->id,
                'stock_item_id' => $l->stock_item_id,
                'stock_item_uuid' => $l->stockItem?->uuid,
                'name' => $l->stockItem->name ?? 'Stock item',
                'unit' => $l->stockItem?->unit,
                'quantity' => (string) $l->quantity,
                'received_quantity' => (string) $l->received_quantity,
                'outstanding' => $o->status->isTerminal() ? '0.00' : $l->outstanding(),
                'unit_cost' => (string) $l->unit_cost,
                'line_total' => (string) $l->line_total,
                'line_total_label' => HospitalSettings::money($l->line_total),
            ])->values()->all(),
            'receipts' => $o->receipts->map(fn (GoodsReceipt $r) => $this->receipt($r))->values()->all(),
            'pdf_url' => route('api.purchase-orders.pdf', $o),
        ];
    }

    /** @return array<string,mixed> */
    private function receipt(GoodsReceipt $r): array
    {
        $r->loadMissing(['items.stockItem', 'items.returns']);

        return [
            'uuid' => $r->uuid,
            'grn_no' => $r->grn_no,
            'received_on' => $r->received_on->toDateString(),
            'supplier_invoice_no' => $r->supplier_invoice_no,
            'total' => (string) $r->total,
            'total_label' => HospitalSettings::money($r->total),
            'notes' => $r->notes,
            'lines' => $r->items->map(fn (GoodsReceiptItem $l) => [
                'id' => $l->id,
                'purchase_order_item_id' => $l->purchase_order_item_id,
                'name' => $l->stockItem->name ?? 'Stock item',
                'quantity' => (string) $l->quantity,
                'unit_cost' => (string) $l->unit_cost,
                'line_total' => (string) $l->line_total,
                'batch_no' => $l->batch_no,
                'expiry_date' => $l->expiry_date?->toDateString(),
                'returned_quantity' => $l->returns->reduce(fn (string $s, SupplierReturn $x) => bcadd($s, (string) $x->quantity, 2), '0.00'),
                'returns' => $l->returns->map(fn (SupplierReturn $x) => $this->returned($x))->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function returned(SupplierReturn $x): array
    {
        return [
            'uuid' => $x->uuid,
            'return_no' => $x->return_no,
            'quantity' => (string) $x->quantity,
            'unit_cost' => (string) $x->unit_cost,
            'total' => (string) $x->total,
            'total_label' => HospitalSettings::money($x->total),
            'reason' => $x->reason,
            'created_at' => $x->created_at?->toIso8601String(),
        ];
    }
}
