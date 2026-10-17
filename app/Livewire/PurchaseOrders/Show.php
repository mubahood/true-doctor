<?php

namespace App\Livewire\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\ProcurementService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One purchase order, its deliveries and its returns (docs/procurement.md).
 *
 * A draft collects lines; "Send" makes it an order; deliveries are received
 * against it, partly or wholly; a line of a delivery can go back to the
 * supplier. Every action is one call into ProcurementService, which owns the
 * rules: this screen only collects what was typed.
 *
 * @property-read PurchaseOrder $order
 * @property-read array{ordered:string,received:string,outstanding:string,returned:string} $figures
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $orderId;

    // Adding a line to a draft.
    public ?int $stock_item_id = null;

    public string $line_quantity = '';

    public string $line_cost = '';

    public int $lineNonce = 0;

    // Receiving a delivery.
    public bool $showReceive = false;

    /** @var array<int, array{quantity:string,unit_cost:string,batch_no:string,expiry_date:string}> keyed by order line id */
    public array $receiving = [];

    public string $received_on = '';

    public string $supplier_invoice_no = '';

    public string $receipt_notes = '';

    // Sending a delivery line back.
    public bool $showReturn = false;

    #[Locked]
    public ?int $returnLineId = null;

    public string $return_quantity = '';

    public string $return_reason = '';

    // Closing or cancelling.
    public bool $showEnd = false;

    #[Locked]
    public string $endAs = 'cancel';

    public string $end_reason = '';

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        $this->authorize('view', $purchaseOrder);
        $this->orderId = $purchaseOrder->id;
    }

    #[Computed]
    public function order(): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->with(['supplier', 'items.stockItem', 'receipts.items.stockItem', 'receipts.items.returns', 'receipts.receivedBy', 'createdBy', 'orderedBy', 'endedBy'])
            ->findOrFail($this->orderId);
    }

    /** @return array{ordered:string,received:string,outstanding:string,returned:string} */
    #[Computed]
    public function figures(): array
    {
        return app(ProcurementService::class)->figures($this->order);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function fresh(string $message): void
    {
        unset($this->order, $this->figures);
        $this->dispatch('toast', message: $message, type: 'success');
    }

    // ── Draft lines ────────────────────────────────────────────────────

    #[On('select-search:picked')]
    public function picked(string $name, int $id): void
    {
        if ($name === 'po_stock_item_id') {
            $this->stock_item_id = $id;
            $item = \App\Models\StockItem::find($id);
            if ($item !== null && $this->line_cost === '') {
                $this->line_cost = rtrim(rtrim((string) $item->cost_price, '0'), '.');
            }
        }
    }

    #[On('select-search:cleared')]
    public function cleared(string $name): void
    {
        if ($name === 'po_stock_item_id') {
            $this->stock_item_id = null;
        }
    }

    public function addLine(ProcurementService $procurement): void
    {
        $this->authorize('update', $this->order);
        $this->validate([
            'stock_item_id' => ['required', 'integer'],
            'line_quantity' => ['required', 'numeric', 'gt:0'],
            'line_cost' => ['nullable', 'numeric', 'min:0'],
        ], [], ['stock_item_id' => 'item', 'line_quantity' => 'quantity', 'line_cost' => 'unit cost']);

        $procurement->addLine($this->order, [
            'stock_item_id' => (int) $this->stock_item_id,
            'quantity' => $this->line_quantity,
            'unit_cost' => $this->line_cost,
        ]);

        $this->reset(['stock_item_id', 'line_quantity', 'line_cost']);
        $this->lineNonce++;
        $this->fresh('Added to the order.');
    }

    public function removeLine(int $lineId, ProcurementService $procurement): void
    {
        $this->authorize('update', $this->order);
        $line = PurchaseOrderItem::query()->where('purchase_order_id', $this->orderId)->findOrFail($lineId);
        $procurement->removeLine($this->order, $line);
        $this->fresh('Line removed.');
    }

    public function send(ProcurementService $procurement): void
    {
        $this->authorize('update', $this->order);
        $procurement->markOrdered($this->order, $this->user());
        $this->fresh('Marked as ordered. Print it or send the PDF to the supplier.');
    }

    // ── Receiving ──────────────────────────────────────────────────────

    public function openReceive(): void
    {
        $this->authorize('update', $this->order);
        $this->receiving = [];
        foreach ($this->order->items as $line) {
            $left = $line->outstanding();
            if (bccomp($left, '0', 2) <= 0) {
                continue;
            }
            $this->receiving[$line->id] = [
                'quantity' => rtrim(rtrim($left, '0'), '.'),
                'unit_cost' => rtrim(rtrim((string) $line->unit_cost, '0'), '.') ?: '0',
                'batch_no' => '',
                'expiry_date' => '',
            ];
        }
        $this->received_on = now()->toDateString();
        $this->supplier_invoice_no = '';
        $this->receipt_notes = '';
        $this->resetErrorBag();
        $this->showReceive = true;
    }

    public function receive(ProcurementService $procurement): void
    {
        $this->authorize('update', $this->order);
        $this->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'receipt_notes' => ['nullable', 'string', 'max:500'],
            'receiving.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'receiving.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'receiving.*.batch_no' => ['nullable', 'string', 'max:60'],
            'receiving.*.expiry_date' => ['nullable', 'date'],
        ], [], ['receiving.*.quantity' => 'quantity', 'receiving.*.unit_cost' => 'unit cost', 'receiving.*.expiry_date' => 'expiry date']);

        $lines = [];
        foreach ($this->receiving as $lineId => $row) {
            $lines[] = [
                'purchase_order_item_id' => (int) $lineId,
                'quantity' => $row['quantity'],
                'unit_cost' => $row['unit_cost'],
                'batch_no' => $row['batch_no'],
                'expiry_date' => $row['expiry_date'],
            ];
        }

        try {
            $receipt = $procurement->receive($this->order, [
                'received_on' => $this->received_on,
                'supplier_invoice_no' => $this->supplier_invoice_no,
                'notes' => $this->receipt_notes,
            ], $lines, $this->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The service names lines by position; the form keys them by order line.
            $ids = array_keys($this->receiving);
            foreach ($e->errors() as $key => $messages) {
                $mapped = preg_replace_callback('/^lines\.(\d+)\./', fn ($m) => 'receiving.'.($ids[(int) $m[1]] ?? $m[1]).'.', $key) ?? $key;
                $this->addError($mapped === 'lines' ? 'receiving' : $mapped, $messages[0]);
            }

            return;
        }

        $this->showReceive = false;
        $this->receiving = [];
        $this->fresh($receipt->grn_no.' received: on the shelf and in the books.');
    }

    // ── Returns ────────────────────────────────────────────────────────

    public function openReturn(int $receiptLineId): void
    {
        $this->authorize('update', $this->order);
        $line = $this->receiptLine($receiptLineId);
        $this->returnLineId = $line->id;
        $this->return_quantity = rtrim(rtrim($line->returnable(), '0'), '.');
        $this->return_reason = '';
        $this->resetErrorBag();
        $this->showReturn = true;
    }

    public function sendBack(ProcurementService $procurement): void
    {
        $this->authorize('update', $this->order);
        $this->validate([
            'return_quantity' => ['required', 'numeric', 'gt:0'],
            'return_reason' => ['required', 'string', 'max:250'],
        ], [], ['return_quantity' => 'quantity', 'return_reason' => 'reason']);

        $line = $this->receiptLine((int) $this->returnLineId);

        try {
            $return = $procurement->returnToSupplier($line, $this->return_quantity, $this->return_reason, $this->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError('return_'.$key, $messages[0]);
            }

            return;
        }

        $this->showReturn = false;
        $this->fresh($return->return_no.' recorded: off the shelf and off what is owed.');
    }

    private function receiptLine(int $id): GoodsReceiptItem
    {
        return GoodsReceiptItem::query()
            ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $this->orderId))
            ->findOrFail($id);
    }

    // ── Close / cancel ─────────────────────────────────────────────────

    public function openEnd(string $as): void
    {
        $this->authorize('update', $this->order);
        $this->endAs = $as === 'close' ? 'close' : 'cancel';
        $this->end_reason = '';
        $this->resetErrorBag();
        $this->showEnd = true;
    }

    public function end(ProcurementService $procurement): void
    {
        $this->authorize('update', $this->order);
        $this->validate(['end_reason' => ['required', 'string', 'max:250']], [], ['end_reason' => 'reason']);

        $this->endAs === 'close'
            ? $procurement->close($this->order, $this->end_reason, $this->user())
            : $procurement->cancel($this->order, $this->end_reason, $this->user());

        $this->showEnd = false;
        $this->fresh($this->endAs === 'close' ? 'Order closed. Nothing more is expected on it.' : 'Order cancelled.');
    }

    public function render()
    {
        $order = $this->order;
        $this->authorize('view', $order);

        return view('livewire.purchase-orders.show', [
            'order' => $order,
            'isDraft' => $order->status === PurchaseOrderStatus::Draft,
        ])->title($order->po_no);
    }
}
