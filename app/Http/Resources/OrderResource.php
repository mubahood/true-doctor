<?php

namespace App\Http\Resources;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Admission;
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
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'notes' => $this->notes,
            'report' => $this->report,
            'report_updated_at' => $this->report_updated_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'id' => $it->id,
                'name' => $it->name,
                'kind' => $it->stock_item_id !== null ? 'product' : 'service',
                'quantity' => $it->quantity,
                'unit_price' => $it->unit_price,
                'line_total' => $it->line_total,
                'status' => $it->status->value,
                'notes' => $it->notes,
            ])->values()),
            'total' => number_format((float) $live->sum(fn ($it) => (float) $it->line_total), 2, '.', ''),
            // The same rules the dialog shows rather than lets you discover.
            'completable' => $this->when($this->relationLoaded('items'), fn () => $order->hasEvidence()),
            'editable' => $this->when($this->relationLoaded('items'), fn () => app(OrderService::class)->itemsEditable($order)),
            'stay' => $stay === null ? null : ['uuid' => $stay->uuid, 'status' => $stay->status->value, 'bed' => $stay->bed?->name],
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
