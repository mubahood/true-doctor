<?php

namespace App\Http\Resources;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Admission;
use App\Models\OrderItem;
use App\Services\OrderDesk;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A piece of work on a visit, as the order dialog shows it: what it is,
 * where it has got to, what it used, and what may happen next.
 *
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $live = $this->relationLoaded('items')
            ? $this->items->where('status', '!=', OrderItemStatus::Cancelled)
            : collect();
        $stay = $this->relationLoaded('subject') && $this->subject instanceof Admission ? $this->subject : null;
        // Asked once: it reads the invoice, and the list asks it per order.
        $editable = $this->relationLoaded('items') ? app(OrderService::class)->itemsEditable($order) : null;

        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'title' => $this->title,
            'status' => $this->status->value,
            // Where it may go next — never straight to Cancelled, which asks why.
            'next_statuses' => array_values(array_map(fn ($s) => $s->value, array_filter(
                $this->status->transitionsTo(), fn ($s) => $s !== OrderStatus::Cancelled,
            ))),
            'can_cancel' => $this->status->canTransitionTo(OrderStatus::Cancelled),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'assigned_to' => $this->assigned_to !== null ? (int) $this->assigned_to : null,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'notes' => $this->notes,
            // The teeth a procedure is on (FDI), charted when it is completed; [] for none.
            'teeth' => $this->teeth ?? [],
            'report' => $this->report,
            'report_updated_at' => $this->report_updated_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => $this->line($it))->values()),
            'total' => $live->reduce(fn (string $sum, $it) => bcadd($sum, (string) $it->line_total, 2), '0.00'),
            // The same rules the dialog shows rather than lets you discover.
            'completable' => $this->when($this->relationLoaded('items'), fn () => $order->hasEvidence()),
            'editable' => $this->when($editable !== null, fn () => $editable),
            // Whether Edit order may swap the order's one charge for another
            // service (web: OrderDetail::chargeSwappable): not placed by another
            // record, not invoiced, one service line at most and no product.
            'charge_swappable' => $this->when($editable !== null, fn () => $editable === true
                && $order->subject_type === null
                && $live->count() <= 1
                && $live->every(fn ($it) => $it->stock_item_id === null)),
            // Whether this reader may change the order itself (PATCH /orders/{uuid}).
            'can_edit' => OrderDesk::canEdit($order, $request->user()),
            'takes_title' => $this->type->takesTitle(),
            'edited_by' => $this->whenLoaded('editor', fn () => $this->editor?->name),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'stay' => $stay === null ? null : ['uuid' => $stay->uuid, 'status' => $stay->status->value, 'bed' => $stay->bed?->name],
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * One line. Who put it on and who last changed it, and a ranged service's
     * range, only when the order was read with them (the order's own answers);
     * the visit's list leaves them out rather than sending them empty.
     *
     * @return array<string,mixed>
     */
    private function line(OrderItem $it): array
    {
        $row = [
            'id' => $it->id,
            'name' => $it->name,
            'kind' => $it->stock_item_id !== null ? 'product' : 'service',
            'service_id' => $it->service_id !== null ? (int) $it->service_id : null,
            'stock_item_id' => $it->stock_item_id !== null ? (int) $it->stock_item_id : null,
            'quantity' => $it->quantity,
            'unit_price' => $it->unit_price,
            'line_total' => $it->line_total,
            'status' => $it->status->value,
            'notes' => $it->notes,
            // A product given without billing carries no amount (docs/orders.md).
            'billed' => $it->isBilled(),
            'billing_set_at' => $it->billing_set_at?->toIso8601String(),
            // A dispensing's product: its quantity is the dispensing's, not changed
            // here, and it comes off only with the whole dispensing (cancel the order).
            'quantity_fixed' => $it->stock_item_id !== null && OrderService::isDispensing($this->resource),
            'removable' => ! ($it->stock_item_id !== null && OrderService::isDispensing($this->resource)),
        ];

        if ($it->relationLoaded('service')) {
            // Where in its range the counter may put this line (OrderService::allowedPrice):
            // only a service priced as a range, and not a charge another record placed.
            $service = $it->service;
            $row['price_range'] = $service !== null && $service->hasRange() && $this->subject_type === null
                ? ['from' => (string) $service->price, 'to' => (string) $service->max_price]
                : null;
        }
        if ($it->relationLoaded('orderedBy')) {
            $row['ordered_by'] = $it->orderedBy?->name;
            $row['ordered_at'] = $it->created_at?->toIso8601String();
        }
        if ($it->relationLoaded('updatedBy')) {
            $row['changed_by'] = $it->wasCorrected() ? ($it->updatedBy->name ?? 'Someone') : null;
            $row['changed_at'] = $it->wasCorrected() ? $it->updated_at?->toIso8601String() : null;
        }

        return $row;
    }
}
